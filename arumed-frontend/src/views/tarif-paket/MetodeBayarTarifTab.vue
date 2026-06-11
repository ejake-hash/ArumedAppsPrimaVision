<script setup>
/**
 * MetodeBayarTarifTab — komponen reusable untuk satu tab tarif di MetodeBayarDetailView.
 *
 * Props:
 *   - type ('tindakan' | 'obat' | 'bhp' | 'iol')
 *   - insurerId (UUID) — insurer untuk lookup tarif (kalau anggota TPA: pass id TPA induk)
 *   - readOnly (boolean) — true kalau insurer adalah anggota TPA (data dari TPA induk, tidak boleh CRUD)
 *   - insurerCode (string) — untuk label download CSV
 *
 * Auto-fill harga master saat tambah:
 *   Setelah pilih item dari dropdown, panggil GET /tarif-paket/master-price/{type}/{itemId}
 *   → isi field `price` (admin bisa edit override).
 */
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { masterApi, tarifPaketApi } from '@/services/api'
import MasterTable from '@/components/master-data/MasterTable.vue'
import MasterFormModal from '@/components/master-data/MasterFormModal.vue'

const props = defineProps({
  type:        { type: String, required: true },     // tindakan | obat | bhp | iol
  insurerId:   { type: String, required: true },
  readOnly:    { type: Boolean, default: false },
  insurerCode: { type: String, default: '' },
  // Mode Buku Tarif: tampilkan SEMUA item master (yg belum bertarif diberi badge
  // "harga belum ditentukan"). Dipakai di halaman Buku Tarif (insurer UMUM).
  bukuTarif:   { type: Boolean, default: false },
})

const emit = defineEmits(['changed'])

const TYPE_META = {
  tindakan: { itemLabel: 'Tindakan', dropdownApi: 'tindakan', itemNameKey: 'name',  itemCodeKey: 'code',          masterPriceKey: 'base_price', kategoriKey: 'category',  kategoriLabel: 'Kategori' },
  obat:     { itemLabel: 'Obat',     dropdownApi: 'obat',     itemNameKey: 'name',  itemCodeKey: 'code',          masterPriceKey: 'price',      kategoriKey: 'golongan',  kategoriLabel: 'Golongan', unitKey: 'unit' },
  bhp:      { itemLabel: 'BHP',      dropdownApi: 'bhp',      itemNameKey: 'name',  itemCodeKey: 'code',          masterPriceKey: 'price',      kategoriKey: 'category',  kategoriLabel: 'Kategori', unitKey: 'unit' },
  iol:      { itemLabel: 'IOL',      dropdownApi: 'iol',      itemNameKey: 'brand', itemCodeKey: 'serial_number', masterPriceKey: 'price',      kategoriKey: 'iol_type',  kategoriLabel: 'Tipe IOL' },
}

const meta = computed(() => TYPE_META[props.type])
const fkKey = computed(() => ({
  tindakan: 'procedure_id', obat: 'medication_id', bhp: 'bhp_item_id', iol: 'iol_item_id',
}[props.type]))

// Pos kwitansi obat (Obat Pulang/Tindakan/Injeksi) — pemisah baris OBAT di kwitansi.
// Hanya relevan untuk type 'obat'. Enum mirror MedicationTariff::POS_VALUES (backend).
const isObat = computed(() => props.type === 'obat')
// Kolom "Satuan" hanya untuk Obat & BHP (unit master item).
const hasSatuan = computed(() => !!meta.value.unitKey)
const POS_OPTIONS = [
  { value: 'OBAT_PULANG',   label: 'Obat Pulang' },
  { value: 'OBAT_TINDAKAN', label: 'Obat Tindakan' },
  { value: 'OBAT_INJEKSI',  label: 'Obat Injeksi' },
]
const posLabel = (v) => POS_OPTIONS.find((o) => o.value === v)?.label ?? 'Obat Pulang'

const items = ref([])               // tarif rows
const pageMeta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 })
const loading = ref(false)
const error = ref(null)
const search = ref('')              // server-side search (nama/kode item)

const itemDropdown = ref([])        // master items source untuk modal dropdown

const modal = ref({ open: false, mode: 'create', payload: emptyForm(), errors: null, submitting: false, editingId: null })
const confirmDelete = ref({ open: false, row: null, busy: false })
const toast = ref(null)
const importing = ref(false)
const fileInputRef = ref(null)
const openMenu = ref(null)   // 'template' | 'export' | null — dropdown pilih format

function toggleMenu(name) {
  openMenu.value = openMenu.value === name ? null : name
}
function closeMenuOnOutside(e) {
  if (!e.target.closest?.('.tt-split')) openMenu.value = null
}

function emptyForm() {
  return { item_id: '', price: 0, is_active: true, pos_kwitansi: 'OBAT_PULANG' }
}

function showToast(type, msg) {
  toast.value = { type, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

const formatRp = (v) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID', { minimumFractionDigits: 0 })

// ─── Table columns ────────────────────────────────────────────────────────
const columns = computed(() => [
  { key: '_no',          label: 'No',                       width: '50px',  align: 'center' },
  { key: 'item_code',    label: 'Kode',                     width: '150px' },
  { key: 'item_name',    label: 'Nama Item' },
  { key: 'item_kategori', label: meta.value.kategoriLabel,  width: '140px' },
  // Satuan hanya untuk Obat & BHP.
  ...(hasSatuan.value ? [{ key: 'item_satuan', label: 'Satuan', width: '90px', align: 'center' }] : []),
  // Pos kwitansi hanya untuk tab Obat.
  ...(isObat.value ? [{ key: 'pos_kwitansi', label: 'Klasifikasi', width: '130px' }] : []),
  { key: 'master_price', label: 'Harga Master',             width: '140px', align: 'right' },
  { key: 'price',        label: 'Harga Jual',               width: '140px', align: 'right' },
  { key: 'is_active',    label: 'Status',                   width: '90px',  align: 'center' },
])

const rows = computed(() => {
  const startIdx = ((pageMeta.value.current_page ?? 1) - 1) * (pageMeta.value.per_page ?? 25)
  return items.value.map((r, i) => {
    const item = r.procedure || r.medication || r.bhp_item || r.bhpItem || r.iol_item || r.iolItem || {}
    return {
      ...r,
      _no:            startIdx + i + 1,
      item_code:      item[meta.value.itemCodeKey] ?? '—',
      item_name:      item[meta.value.itemNameKey] ?? '—',
      item_kategori:  item[meta.value.kategoriKey] ?? '',
      item_satuan:    meta.value.unitKey ? (item[meta.value.unitKey] ?? '') : '',
      master_price:   item[meta.value.masterPriceKey] ?? 0,
    }
  })
})

// ─── Fetch ────────────────────────────────────────────────────────────────
async function refresh(page = 1) {
  if (!props.insurerId) return
  loading.value = true
  error.value = null
  try {
    const params = { page, per_page: pageMeta.value.per_page, insurer_id: props.insurerId }
    if (props.bukuTarif) params.include_unpriced = 1
    if (search.value.trim()) params.search = search.value.trim()
    const res = await tarifPaketApi.tarif.list(props.type, params)
    const payload = res.data?.data
    if (payload && Array.isArray(payload.data)) {
      items.value = payload.data
      pageMeta.value = {
        current_page: payload.current_page ?? 1,
        last_page:    payload.last_page ?? 1,
        total:        payload.total ?? payload.data.length,
        per_page:     payload.per_page ?? 25,
      }
    } else {
      items.value = []
    }
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal memuat tarif'
  } finally {
    loading.value = false
  }
}

async function loadItemDropdown() {
  try {
    const api = masterApi[meta.value.dropdownApi]
    const res = await api.list({ per_page: 500, active: 1 })
    const payload = res.data?.data
    itemDropdown.value = Array.isArray(payload?.data) ? payload.data : (Array.isArray(payload) ? payload : [])
  } catch (e) {
    itemDropdown.value = []
  }
}

function onPageChange(p) { refresh(p) }

// Search dari MasterTable (sudah di-debounce 300ms di komponen) → reset ke hal. 1.
function onSearch(v) {
  search.value = v ?? ''
  refresh(1)
}

// ─── CRUD modal ───────────────────────────────────────────────────────────
function openCreate() {
  modal.value = { open: true, mode: 'create', payload: emptyForm(), errors: null, submitting: false, editingId: null }
}

// Buku Tarif: item belum bertarif → buka modal create dgn item terkunci (set harga).
function openSetPrice(row) {
  const item = row.procedure || row.medication || row.bhp_item || row.bhpItem || row.iol_item || row.iolItem || {}
  modal.value = {
    open: true,
    mode: 'create',
    payload: {
      ...emptyForm(),
      item_id:    row[fkKey.value],
      _itemLabel: `${item[meta.value.itemCodeKey] ?? ''} · ${item[meta.value.itemNameKey] ?? ''}`,
    },
    errors: null,
    submitting: false,
    editingId: null,
  }
}

function openEdit(row) {
  const item = row.procedure || row.medication || row.bhp_item || row.bhpItem || row.iol_item || row.iolItem || {}
  modal.value = {
    open: true,
    mode: 'edit',
    payload: {
      item_id:      row[fkKey.value],
      _itemLabel:   `${item[meta.value.itemCodeKey] ?? ''} · ${item[meta.value.itemNameKey] ?? ''}`,
      price:        Number(row.price ?? 0),
      is_active:    row.is_active ?? true,
      pos_kwitansi: row.pos_kwitansi ?? 'OBAT_PULANG',
    },
    errors: null,
    submitting: false,
    editingId: row.id,
  }
}

// ─── Kemasan jual obat (varian Strip/Box, harga independen per kemasan) ──────
// Berlaku semua penjamin (insurer NULL) — kemasan dasar (satuan kecil) tetap
// harga jual tarif di tab ini. Saran harga = harga dasar × isi (editable).
const kemasanModal = ref({ open: false, row: null, units: [], loading: false, busy: false, form: { label: '', isi: 1, price: 0 }, editingId: null })

function openKemasan(row) {
  kemasanModal.value = {
    open: true, row, units: [], loading: true, busy: false,
    form: { label: '', isi: 1, price: 0 }, editingId: null,
  }
  loadKemasan()
}
async function loadKemasan() {
  const m = kemasanModal.value
  if (!m.row) return
  m.loading = true
  try {
    const res = await tarifPaketApi.kemasanObat.list(m.row[fkKey.value])
    m.units = res.data?.data ?? []
  } catch (e) {
    m.units = []
    showToast('e', e.response?.data?.message ?? 'Gagal memuat kemasan')
  } finally { m.loading = false }
}
// Harga dasar (per satuan kecil) utk saran = harga jual baris tarif, fallback master.
const kemasanBasePrice = computed(() => Number(kemasanModal.value.row?.price ?? 0) || Number(kemasanModal.value.row?.master_price ?? 0))
function kemasanSuggest() {
  const isi = Number(kemasanModal.value.form.isi) || 1
  kemasanModal.value.form.price = Math.round(kemasanBasePrice.value * isi)
}
function editKemasanRow(u) {
  kemasanModal.value.editingId = u.id
  kemasanModal.value.form = { label: u.label, isi: Number(u.isi), price: Number(u.price) }
}
function cancelKemasanEdit() {
  kemasanModal.value.editingId = null
  kemasanModal.value.form = { label: '', isi: 1, price: 0 }
}
async function submitKemasan() {
  const m = kemasanModal.value
  const f = m.form
  if (!String(f.label).trim()) { showToast('e', 'Label kemasan wajib diisi (mis. Strip / Box)'); return }
  if (!(Number(f.isi) >= 1)) { showToast('e', 'Isi kemasan minimal 1'); return }
  m.busy = true
  try {
    if (m.editingId) {
      await tarifPaketApi.kemasanObat.update(m.editingId, { label: f.label, isi: Number(f.isi), price: Number(f.price) })
    } else {
      await tarifPaketApi.kemasanObat.create(m.row[fkKey.value], { label: f.label, isi: Number(f.isi), price: Number(f.price) })
    }
    showToast('s', 'Kemasan jual disimpan')
    cancelKemasanEdit()
    await loadKemasan()
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan kemasan')
  } finally { m.busy = false }
}
async function toggleKemasanActive(u) {
  try {
    await tarifPaketApi.kemasanObat.update(u.id, { is_active: !u.is_active })
    await loadKemasan()
  } catch (e) { showToast('e', e.response?.data?.message ?? 'Gagal mengubah status') }
}
async function removeKemasan(u) {
  if (!window.confirm(`Hapus kemasan ${u.label} (isi ${u.isi})?`)) return
  kemasanModal.value.busy = true
  try {
    await tarifPaketApi.kemasanObat.remove(u.id)
    showToast('s', 'Kemasan dihapus')
    await loadKemasan()
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus kemasan')
  } finally { kemasanModal.value.busy = false }
}

// Watch item selection for auto-fill master price
watch(() => modal.value.payload.item_id, async (newId, oldId) => {
  if (modal.value.mode !== 'create') return
  if (!newId || newId === oldId) return
  // Cek dari dropdown local dulu (faster); fallback panggil master-price API
  const localItem = itemDropdown.value.find((it) => it.id === newId)
  if (localItem && localItem[meta.value.masterPriceKey] !== undefined) {
    modal.value.payload.price = Number(localItem[meta.value.masterPriceKey] ?? 0)
    return
  }
  try {
    const res = await tarifPaketApi.masterPrice(props.type, newId)
    modal.value.payload.price = Number(res.data?.data?.price ?? 0)
  } catch (e) { /* silent */ }
})

const modalFields = computed(() => {
  const itemOpts = itemDropdown.value.map((it) => ({
    value: it.id,
    label: `${it[meta.value.itemCodeKey] ?? '-'} · ${it[meta.value.itemNameKey] ?? '-'}`,
  }))
  // Field pos kwitansi hanya untuk tab Obat (1 obat = 1 pos; berlaku lintas penjamin).
  const posField = isObat.value
    ? [{ key: 'pos_kwitansi', label: 'Klasifikasi', type: 'select', options: POS_OPTIONS, cols: 2,
        hint: 'Menentukan pos baris obat di kwitansi (Pulang/Tindakan/Injeksi). Berlaku untuk obat ini di semua penjamin.' }]
    : []

  if (modal.value.mode === 'create') {
    // Item terkunci bila dibuka via "Set Harga" (item sudah ditentukan); else dropdown.
    const itemField = modal.value.payload._itemLabel
      ? { key: '_itemLabel', label: meta.value.itemLabel, type: 'text', disabled: true, cols: 2 }
      : { key: 'item_id', label: meta.value.itemLabel, type: 'select', options: itemOpts, required: true, cols: 2,
          hint: 'Setelah pilih, harga master ter-isi otomatis. Anda bisa override.' }
    return [
      itemField,
      { key: 'price',     label: 'Harga (Rp)',  type: 'number',   required: true, min: 0, cols: 1 },
      { key: 'is_active', label: 'Aktif',       type: 'checkbox', cols: 1 },
      ...posField,
    ]
  }
  return [
    { key: '_itemLabel', label: meta.value.itemLabel,    type: 'text',     disabled: true, cols: 2 },
    { key: 'price',      label: 'Harga (Rp)',  type: 'number',   required: true, min: 0, cols: 1 },
    { key: 'is_active',  label: 'Aktif',       type: 'checkbox', cols: 1 },
    ...posField,
  ]
})

async function onSubmit(payload) {
  modal.value.submitting = true
  modal.value.errors = null
  try {
    if (modal.value.mode === 'create') {
      const data = {
        [fkKey.value]: payload.item_id,
        insurer_id:    props.insurerId,
        price:         Number(payload.price),
        is_active:     payload.is_active ?? true,
      }
      // Pos kwitansi hanya dikirim untuk tab Obat.
      if (isObat.value) data.pos_kwitansi = payload.pos_kwitansi ?? 'OBAT_PULANG'
      await tarifPaketApi.tarif.create(props.type, data)
      showToast('s', 'Tarif disimpan')
    } else {
      const data = {
        price:     Number(payload.price),
        is_active: payload.is_active ?? true,
      }
      if (isObat.value) data.pos_kwitansi = payload.pos_kwitansi ?? 'OBAT_PULANG'
      await tarifPaketApi.tarif.update(props.type, modal.value.editingId, data)
      showToast('s', 'Tarif diperbarui')
    }
    modal.value.open = false
    await refresh(pageMeta.value.current_page)
    emit('changed')
  } catch (e) {
    if (e.response?.status === 422) modal.value.errors = e.response.data?.errors ?? null
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan')
  } finally {
    modal.value.submitting = false
  }
}

function askDelete(row) {
  confirmDelete.value = { open: true, row, busy: false }
}

async function doDelete() {
  confirmDelete.value.busy = true
  try {
    await tarifPaketApi.tarif.remove(props.type, confirmDelete.value.row.id)
    showToast('s', 'Tarif dihapus')
    confirmDelete.value.open = false
    await refresh(pageMeta.value.current_page)
    emit('changed')
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus')
  } finally {
    confirmDelete.value.busy = false
  }
}

// ─── CSV ──────────────────────────────────────────────────────────────────
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

async function onCsvTemplate(format = 'csv') {
  openMenu.value = null
  try {
    const res = await tarifPaketApi.metodeBayar.csvTemplate(props.insurerId, props.type, format === 'xlsx' ? 'xlsx' : undefined)
    triggerDownload(res.data, `template-tarif-${props.type}.${format}`)
  } catch (e) {
    showToast('e', 'Gagal download template')
  }
}

async function onCsvExport(format = 'csv') {
  openMenu.value = null
  try {
    const res = await tarifPaketApi.metodeBayar.csvExport(props.insurerId, props.type, format === 'xlsx' ? 'xlsx' : undefined)
    const code = props.insurerCode || 'tarif'
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '')
    triggerDownload(res.data, `tarif-${props.type}-${code}-${today}.${format}`)
  } catch (e) {
    showToast('e', 'Gagal export')
  }
}

async function onCsvImportClick() {
  fileInputRef.value?.click()
}

async function onCsvImportSelected(e) {
  const file = e.target.files?.[0]
  if (!file) return
  importing.value = true
  try {
    const res = await tarifPaketApi.metodeBayar.csvImport(props.insurerId, props.type, file)
    const result = res.data?.data ?? {}
    const counts = `Import: ${result.inserted ?? 0} baru, ${result.updated ?? 0} update, ${result.skipped ?? 0} dilewati`
    const errs = Array.isArray(result.errors) ? result.errors : []
    // Ada baris bermasalah → tampilkan alasan pertama (jangan diam-diam): baris dilewati
    // umumnya karena nama/kategori tak cocok master, atau pos_kwitansi tak dikenal.
    if (errs.length) {
      showToast('e', `${counts}. ${errs[0]}${errs.length > 1 ? ` (+${errs.length - 1} masalah lain)` : ''}`)
    } else {
      showToast('s', counts)
    }
    await refresh(1)
    emit('changed')
  } catch (err) {
    showToast('e', err.response?.data?.message ?? 'Gagal import CSV')
  } finally {
    importing.value = false
    if (e.target) e.target.value = ''  // reset agar bisa upload file sama lagi
  }
}

// ─── Lifecycle ────────────────────────────────────────────────────────────
onMounted(async () => {
  document.addEventListener('click', closeMenuOnOutside)
  await Promise.all([refresh(), loadItemDropdown()])
})
onUnmounted(() => document.removeEventListener('click', closeMenuOnOutside))

// Reload kalau insurerId berubah (saat user switch insurer di luar)
watch(() => props.insurerId, () => refresh())
</script>

<template>
  <div class="tt-tab">
    <!-- Toolbar: Tambah + CSV actions -->
    <div class="tt-toolbar">
      <button class="tt-btn-primary" :disabled="readOnly" @click="openCreate" :title="readOnly ? 'Insurer child mewarisi tarif dari TPA' : ''">
        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Tambah Tarif {{ meta.itemLabel }}
      </button>

      <div class="tt-csv-bar">
        <!-- Template (CSV / Excel) -->
        <div class="tt-split">
          <button class="tt-btn-csv" :disabled="readOnly" :title="readOnly ? 'Child tidak boleh import' : 'Download template (header) — pilih format'" @click.stop="toggleMenu('template')">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Template
            <svg class="tt-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div v-if="openMenu === 'template'" class="tt-menu">
            <button @click="onCsvTemplate('csv')">CSV (.csv)</button>
            <button @click="onCsvTemplate('xlsx')">Excel (.xlsx)</button>
          </div>
        </div>

        <button class="tt-btn-csv" :disabled="readOnly || importing" :title="readOnly ? 'Child tidak boleh import' : 'Upload CSV atau Excel'" @click="onCsvImportClick">
          <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          {{ importing ? 'Mengimport…' : 'Import' }}
        </button>

        <!-- Export (CSV / Excel) -->
        <div class="tt-split">
          <button class="tt-btn-csv" title="Download semua tarif insurer ini — pilih format" @click.stop="toggleMenu('export')">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export
            <svg class="tt-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div v-if="openMenu === 'export'" class="tt-menu">
            <button @click="onCsvExport('csv')">CSV (.csv)</button>
            <button @click="onCsvExport('xlsx')">Excel (.xlsx)</button>
          </div>
        </div>

        <input ref="fileInputRef" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" style="display:none" @change="onCsvImportSelected" />
      </div>
    </div>

    <MasterTable
      :columns="columns" :rows="rows"
      :loading="loading" :error="error" :meta="pageMeta"
      :search-value="search"
      :search-placeholder="`Cari ${meta.itemLabel.toLowerCase()} (nama / kode)…`"
      :empty-text="search.trim()
        ? `Tidak ada ${meta.itemLabel.toLowerCase()} yang cocok dengan pencarian.`
        : (bukuTarif ? 'Belum ada item master aktif.' : 'Belum ada tarif untuk insurer ini. Klik Tambah atau Import CSV.')"
      @update:search="onSearch"
      @page-change="onPageChange"
      @refresh="() => refresh(pageMeta.current_page)"
    >
      <template #cell-item_code="{ value }">
        <span class="tt-code">{{ value }}</span>
      </template>

      <template #cell-item_name="{ value }">
        <strong class="tt-name">{{ value }}</strong>
      </template>

      <template #cell-item_kategori="{ value }">
        <span v-if="value" class="tt-kategori-pill">{{ value }}</span>
        <span v-else class="tt-dim">—</span>
      </template>

      <template #cell-item_satuan="{ value }">
        <span v-if="value" class="tt-satuan-pill">{{ value }}</span>
        <span v-else class="tt-dim">—</span>
      </template>

      <template #cell-pos_kwitansi="{ value }">
        <span class="tt-pos-pill">{{ posLabel(value) }}</span>
      </template>

      <template #cell-master_price="{ value }">
        <span class="tt-master-price">{{ formatRp(value) }}</span>
      </template>

      <template #cell-price="{ row, value }">
        <div v-if="row._unpriced" class="tt-price-unset">
          <span class="tt-price tt-price-zero">{{ formatRp(0) }}</span>
          <span class="tt-badge-unset" title="Harga jual belum diisi di Buku Tarif — item akan ditagih Rp 0">harga belum ditentukan buku tarif</span>
        </div>
        <span v-else class="tt-price" :class="{ 'tt-price-diff': Number(value) !== Number(row.master_price) }">
          {{ formatRp(value) }}
        </span>
      </template>

      <template #cell-is_active="{ value }">
        <span class="tt-status" :class="value ? 'on' : 'off'">{{ value ? 'Aktif' : 'Nonaktif' }}</span>
      </template>

      <template #actions="{ row }">
        <template v-if="readOnly">
          <span class="tt-readonly-dot" title="Read-only (inherit dari parent)">—</span>
        </template>
        <template v-else>
          <!-- Buku Tarif: item belum bertarif → tombol Set Harga (buat tarif baru) -->
          <button v-if="row._unpriced" class="tt-btn-setprice" title="Set harga jual untuk item ini" @click="openSetPrice(row)">
            Set Harga
          </button>
          <template v-else>
            <button class="tt-icon-btn" title="Edit" @click="openEdit(row)">
              <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
            </button>
            <button class="tt-icon-btn tt-icon-danger" title="Hapus" @click="askDelete(row)">
              <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
          </template>
          <!-- Kemasan jual obat (varian Strip/Box, harga independen) -->
          <button v-if="isObat" class="tt-icon-btn" title="Kemasan jual (Strip/Box)" @click="openKemasan(row)">
            <svg viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
          </button>
        </template>
      </template>
    </MasterTable>

    <!-- Modal CRUD -->
    <MasterFormModal
      v-model:open="modal.open"
      v-model="modal.payload"
      :title="modal.mode === 'create' ? `Tambah Tarif ${meta.itemLabel}` : `Edit Tarif ${meta.itemLabel}`"
      :fields="modalFields"
      :submitting="modal.submitting"
      :errors="modal.errors"
      :submit-label="modal.mode === 'create' ? 'Simpan' : 'Simpan Perubahan'"
      width="560px"
      @submit="onSubmit"
    />

    <!-- Confirm delete -->
    <Teleport to="body">
      <div v-if="confirmDelete.open" class="tt-confirm-overlay" @click.self="confirmDelete.open = false">
        <div class="tt-confirm">
          <div class="tt-confirm-icon">
            <svg viewBox="0 0 24 24"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          </div>
          <h3>Hapus tarif?</h3>
          <p>Tarif untuk <strong>{{ confirmDelete.row?.item_name }}</strong> akan dihapus.</p>
          <div class="tt-confirm-actions">
            <button class="tt-btn-secondary" :disabled="confirmDelete.busy" @click="confirmDelete.open = false">Batal</button>
            <button class="tt-btn-danger" :disabled="confirmDelete.busy" @click="doDelete">
              {{ confirmDelete.busy ? 'Menghapus…' : 'Hapus' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Modal Kemasan Jual obat (varian Strip/Box, harga independen per kemasan) -->
    <Teleport to="body">
      <div v-if="kemasanModal.open" class="tt-confirm-overlay" @click.self="kemasanModal.open = false">
        <div class="tt-kemasan-modal">
          <h3 class="tt-kemasan-title">
            Kemasan Jual — {{ kemasanModal.row?.item_name ?? '' }}
          </h3>
          <p class="tt-kemasan-desc">
            Obat ini tetap dijual per <strong>{{ kemasanModal.row?.item_satuan || 'satuan kecil' }}</strong>
            dengan harga jual {{ formatRp(kemasanBasePrice) }}. Kemasan di bawah = pilihan jual TAMBAHAN
            (Strip/Box) dengan harga sendiri — dipilih Farmasi saat verifikasi resep. Berlaku semua penjamin.
          </p>

          <div v-if="kemasanModal.loading" class="tt-kemasan-empty">Memuat…</div>
          <table v-else-if="kemasanModal.units.length" class="tt-kemasan-table">
            <thead>
              <tr><th>Label</th><th class="ac">Isi / kemasan</th><th class="ar">Harga / kemasan</th><th class="ac">Status</th><th class="ac">Aksi</th></tr>
            </thead>
            <tbody>
              <tr v-for="u in kemasanModal.units" :key="u.id">
                <td><strong>{{ u.label }}</strong><span v-if="u.insurer" class="tt-kemasan-ins">{{ u.insurer.name }}</span></td>
                <td class="ac">{{ u.isi }} {{ kemasanModal.row?.item_satuan || '' }}</td>
                <td class="ar">{{ formatRp(u.price) }}</td>
                <td class="ac">
                  <button class="tt-kemasan-status" :class="u.is_active ? 'on' : 'off'" @click="toggleKemasanActive(u)">
                    {{ u.is_active ? 'Aktif' : 'Nonaktif' }}
                  </button>
                </td>
                <td class="ac">
                  <button class="tt-icon-btn" title="Edit" @click="editKemasanRow(u)">
                    <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                  </button>
                  <button class="tt-icon-btn tt-icon-danger" title="Hapus" :disabled="kemasanModal.busy" @click="removeKemasan(u)">
                    <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-else class="tt-kemasan-empty">Belum ada kemasan jual — obat hanya dijual per satuan kecil.</div>

          <div class="tt-kemasan-form">
            <div class="tt-kemasan-form-title">{{ kemasanModal.editingId ? 'Edit kemasan' : 'Tambah kemasan' }}</div>
            <div class="tt-kemasan-fields">
              <label>
                <span>Label</span>
                <input v-model="kemasanModal.form.label" type="text" placeholder="Strip / Box / Botol" maxlength="50" />
              </label>
              <label>
                <span>Isi ({{ kemasanModal.row?.item_satuan || 'satuan' }}/kemasan)</span>
                <input v-model.number="kemasanModal.form.isi" type="number" min="1" />
              </label>
              <label>
                <span>Harga / kemasan</span>
                <input v-model.number="kemasanModal.form.price" type="number" min="0" />
              </label>
              <button class="tt-btn-csv" type="button" :title="`Isi otomatis = ${formatRp(kemasanBasePrice)} × isi (boleh diubah/lebih murah)`" @click="kemasanSuggest">
                Saran: dasar × isi
              </button>
            </div>
            <div class="tt-kemasan-actions">
              <button v-if="kemasanModal.editingId" class="tt-btn-secondary" :disabled="kemasanModal.busy" @click="cancelKemasanEdit">Batal edit</button>
              <button class="tt-btn-primary" :disabled="kemasanModal.busy" @click="submitKemasan">
                {{ kemasanModal.busy ? 'Menyimpan…' : (kemasanModal.editingId ? 'Simpan Perubahan' : 'Tambah Kemasan') }}
              </button>
              <button class="tt-btn-secondary" @click="kemasanModal.open = false">Tutup</button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="toast" class="tt-toast-wrap">
        <div class="tt-toast" :class="`tt-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.tt-tab { display: flex; flex-direction: column; gap: 0.9rem; }

.tt-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }

.tt-btn-primary { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 9px; border: 1px solid var(--ga); background: var(--ga); color: white; font-size: 13px; font-weight: 500; cursor: pointer; transition: background 0.15s; }
.tt-btn-primary:hover:not(:disabled) { background: var(--gm); border-color: var(--gm); }
.tt-btn-primary:disabled { opacity: 0.45; cursor: not-allowed; }
.tt-btn-primary svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }

.tt-csv-bar { display: flex; gap: 6px; }
.tt-split { position: relative; }
.tt-caret { width: 11px !important; height: 11px !important; margin-left: 2px; }
.tt-menu { position: absolute; top: calc(100% + 4px); right: 0; z-index: 50; background: var(--bc); border: 1px solid var(--gb); border-radius: 9px; box-shadow: 0 8px 24px rgba(0,0,0,0.14); padding: 4px; min-width: 140px; display: flex; flex-direction: column; gap: 2px; }
.tt-menu button { text-align: left; padding: 7px 10px; border: none; background: transparent; border-radius: 6px; font-size: 12px; color: var(--td); cursor: pointer; }
.tt-menu button:hover { background: var(--gl); color: var(--ga); }
.tt-btn-csv { display: inline-flex; align-items: center; gap: 6px; padding: 7px 11px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 12px; font-weight: 500; cursor: pointer; transition: background 0.15s, color 0.15s, border-color 0.15s; }
.tt-btn-csv svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.tt-btn-csv:hover:not(:disabled) { background: var(--gl); color: var(--td); border-color: var(--ga); }
.tt-btn-csv:disabled { opacity: 0.4; cursor: not-allowed; }

.tt-code { font-family: 'Geist Mono', monospace; font-size: 12px; color: var(--td); background: var(--bs); padding: 2px 8px; border-radius: 6px; border: 1px solid var(--gb); }
.tt-name { font-weight: 500; color: var(--td); }
.tt-kategori-pill { display: inline-block; padding: 3px 9px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.tt-pos-pill { display: inline-block; padding: 3px 9px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--gl); color: var(--ga); border: 1px solid var(--gb); }
.tt-satuan-pill { display: inline-block; padding: 3px 9px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--ib); color: var(--tm); border: 1px solid var(--ibd); }
.tt-dim { color: var(--tu); font-size: 12px; }
.tt-master-price { color: var(--tm); font-variant-numeric: tabular-nums; }
.tt-price { font-weight: 600; color: var(--td); font-variant-numeric: tabular-nums; }
.tt-price-diff { color: var(--ga); }
.tt-price-unset { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; }
.tt-price-zero { color: var(--tu); font-weight: 500; }
.tt-badge-unset { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 600; line-height: 1.3; background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); white-space: nowrap; }
.tt-btn-setprice { padding: 5px 12px; border-radius: 8px; border: 1px solid var(--ga); background: var(--gl); color: var(--ga); font-size: 12px; font-weight: 600; cursor: pointer; transition: background 0.15s; }
.tt-btn-setprice:hover { background: var(--ga); color: #fff; }
.tt-status { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 500; }
.tt-status.on { background: var(--sb); color: var(--st); }
.tt-status.off { background: var(--eb); color: var(--et); }

.tt-readonly-dot { color: var(--tu); font-size: 14px; padding: 0 10px; }

.tt-icon-btn { width: 28px; height: 28px; border-radius: 7px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; margin-left: 4px; transition: background 0.15s, color 0.15s, border-color 0.15s; }
.tt-icon-btn svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.tt-icon-btn:hover { background: var(--gl); color: var(--td); border-color: var(--ga); }
.tt-icon-danger:hover { background: var(--eb); color: var(--et); border-color: var(--ebd); }

.tt-confirm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 9100; backdrop-filter: blur(3px); padding: 1rem; }
.tt-confirm { background: var(--bc); border-radius: 16px; width: 420px; max-width: 95vw; border: 1px solid var(--gb); padding: 1.6rem 1.5rem 1.3rem; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.7rem; box-shadow: 0 20px 60px rgba(0,0,0,0.22); }
.tt-confirm-icon { width: 48px; height: 48px; border-radius: 50%; background: var(--eb); display: flex; align-items: center; justify-content: center; }
.tt-confirm-icon svg { width: 24px; height: 24px; fill: none; stroke: var(--et); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.tt-confirm h3 { font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--td); margin: 0; }
.tt-confirm p { font-size: 13px; color: var(--tm); margin: 0; line-height: 1.5; }
.tt-confirm-actions { display: flex; gap: 0.6rem; margin-top: 0.5rem; width: 100%; justify-content: center; }
.tt-btn-secondary { padding: 8px 16px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 13px; cursor: pointer; font-weight: 500; }
.tt-btn-secondary:hover { background: var(--bs); }
.tt-btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }
.tt-btn-danger { padding: 8px 18px; border-radius: 8px; border: 1px solid var(--et); background: var(--et); color: white; font-size: 13px; cursor: pointer; font-weight: 500; }
.tt-btn-danger:hover:not(:disabled) { background: #b91c1c; border-color: #b91c1c; }
.tt-btn-danger:disabled { opacity: 0.6; cursor: not-allowed; }

.tt-toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
.tt-toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; animation: tt-toast-in 0.2s ease; }
.tt-toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.tt-toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.tt-toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.tt-toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
@keyframes tt-toast-in { from { transform: translateY(-8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

/* ─── Modal Kemasan Jual obat ───────────────────────────────────────────── */
.tt-kemasan-modal { background: var(--bc); border: 1px solid var(--gb); border-radius: 14px; padding: 1.2rem 1.3rem; width: min(640px, 94vw); max-height: 86vh; overflow-y: auto; box-shadow: 0 18px 50px rgba(0,0,0,0.22); }
.tt-kemasan-title { margin: 0 0 4px; font-size: 15px; font-weight: 600; color: var(--td); }
.tt-kemasan-desc { margin: 0 0 12px; font-size: 12px; color: var(--tm); line-height: 1.5; }
.tt-kemasan-table { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-bottom: 12px; }
.tt-kemasan-table th { text-align: left; padding: 7px 8px; background: var(--bs); color: var(--tm); font-size: 11px; text-transform: uppercase; border-bottom: 1px solid var(--gb); }
.tt-kemasan-table td { padding: 7px 8px; border-bottom: 1px solid var(--gb); color: var(--td); }
.tt-kemasan-table .ac { text-align: center; }
.tt-kemasan-table .ar { text-align: right; }
.tt-kemasan-ins { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 999px; font-size: 10px; background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.tt-kemasan-status { padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; border: 1px solid; cursor: pointer; background: transparent; }
.tt-kemasan-status.on  { color: var(--st); border-color: var(--sbd); background: var(--sb); }
.tt-kemasan-status.off { color: var(--tm); border-color: var(--gb); background: var(--bs); }
.tt-kemasan-empty { padding: 0.9rem; text-align: center; color: var(--tm); font-size: 12px; border: 1px dashed var(--gb); border-radius: 9px; margin-bottom: 12px; }
.tt-kemasan-form { border-top: 1px solid var(--gb); padding-top: 12px; }
.tt-kemasan-form-title { font-size: 12px; font-weight: 600; color: var(--td); margin-bottom: 8px; }
.tt-kemasan-fields { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 10px; }
.tt-kemasan-fields label { display: flex; flex-direction: column; gap: 4px; font-size: 11px; color: var(--tm); }
.tt-kemasan-fields input { width: 130px; padding: 7px 9px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--td); font-size: 12.5px; }
.tt-kemasan-fields input:focus { outline: none; border-color: var(--ga); }
.tt-kemasan-actions { display: flex; justify-content: flex-end; gap: 8px; }
</style>
