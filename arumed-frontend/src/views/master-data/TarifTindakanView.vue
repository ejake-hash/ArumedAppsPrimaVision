<script setup>
/**
 * TarifTindakanView — Master Tarif Tindakan (acuan utama).
 *
 * Source data: `procedures` (master tindakan).
 * Code format: {PREFIX}-{NNN} auto-generate dari kategori
 *   (mis. Tindakan→TND-001, Konsultasi→KSL-001).
 * Master kategori dikelola lewat modal "Kelola Kategori" di toolbar.
 *
 * Fitur:
 *   - CRUD tindakan via modal — code hidden saat create, read-only saat edit
 *   - Kategori = dropdown master (procedure_categories)
 *   - Modal Kelola Kategori inline (CRUD master kategori + code_prefix)
 *   - Filter: search + chip kategori
 *   - CSV import/export — column code opsional saat import (kosong = autogen)
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useMasterDataStore } from '@/stores/masterDataStore'
import { masterApi } from '@/services/api'
import MasterTable from '@/components/master-data/MasterTable.vue'
import MasterFormModal from '@/components/master-data/MasterFormModal.vue'
import CsvActionBar from '@/components/master-data/CsvActionBar.vue'
import MetodeBayarTarifTab from '@/views/tarif-paket/MetodeBayarTarifTab.vue'
import RoomTarifPanel from '@/components/tarif-paket/RoomTarifPanel.vue'

const store = useMasterDataStore()
const KEY = 'tindakan'

// ─── Tab Jenis (Buku Tarif) ──────────────────────────────────────────────
// Tindakan = master procedures (per-penjamin diatur di Metode Bayar).
// Obat/BHP/IOL = harga jual TUNGGAL → disimpan di baris insurer UMUM
// (komponen MetodeBayarTarifTab di-reuse dengan insurerId = umumId).
const TABS = [
  { key: 'all',      label: 'Buku Tarif' },   // daftar terpadu berkategori (default)
  { key: 'tindakan', label: 'Tindakan' },
  { key: 'obat',     label: 'Obat' },
  { key: 'bhp',      label: 'BHP' },
  { key: 'iol',      label: 'IOL' },
  { key: 'kamar',    label: 'Tarif Kamar' },
]
const activeTab = ref('all')
const umumId = ref('')        // id insurer sistem UMUM (untuk tab obat/bhp/iol)

async function loadUmumInsurer() {
  try {
    const res = await masterApi.penjamin({ type: 'UMUM', is_system: 1, per_page: 5 })
    const list = res.data?.data?.data ?? res.data?.data ?? []
    umumId.value = (Array.isArray(list) ? list : []).find((i) => i.is_system && i.type === 'UMUM')?.id
      ?? (Array.isArray(list) ? list[0]?.id : '') ?? ''
  } catch {
    umumId.value = ''
  }
}

// kategoriList: array of { id, name, code_prefix } dari /master/tindakan/kategori-list
const kategoriList = ref([])
const filterKategori = ref('')
const searchValue = ref('')
let searchDebounce = null

const kategoriNames = computed(() => kategoriList.value.map((k) => k.name))

const modal = ref({
  open: false,
  mode: 'create',
  payload: emptyForm(),
  errors: null,
  submitting: false,
  editingId: null,
})

const confirmDelete = ref({ open: false, row: null, busy: false })
const toast = ref(null)

// ─── Modal Kelola Kategori (CRUD master kategori) ────────────────────────
const kategoriModal = ref({ open: false })
const kategoriRows = ref([])           // list dari /master/tindakan/kategori (richer than kategoriList)
const kategoriBusy = ref(false)
const katForm = ref({ id: null, name: '', code_prefix: '', description: '', is_active: true })

async function openKategoriModal() {
  kategoriModal.value.open = true
  await refreshKategoriRows()
  resetKatForm()
}

function resetKatForm() {
  katForm.value = { id: null, name: '', code_prefix: '', description: '', is_active: true }
}

async function refreshKategoriRows() {
  try {
    const res = await masterApi.tindakan.kategori.list()
    kategoriRows.value = res.data?.data ?? []
  } catch (e) {
    showToast('e', 'Gagal memuat kategori')
  }
}

async function submitKategori() {
  if (!katForm.value.name || !katForm.value.code_prefix) {
    showToast('w', 'Nama & Code Prefix wajib diisi')
    return
  }
  kategoriBusy.value = true
  try {
    const payload = {
      name:        katForm.value.name.trim(),
      code_prefix: katForm.value.code_prefix.trim().toUpperCase(),
      description: katForm.value.description || null,
      is_active:   katForm.value.is_active,
    }
    if (katForm.value.id) {
      await masterApi.tindakan.kategori.update(katForm.value.id, payload)
      showToast('s', 'Kategori diperbarui')
    } else {
      await masterApi.tindakan.kategori.create(payload)
      showToast('s', 'Kategori dibuat')
    }
    resetKatForm()
    await refreshKategoriRows()
    await loadKategoriList()
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal simpan kategori')
  } finally {
    kategoriBusy.value = false
  }
}

function editKategoriRow(row) {
  katForm.value = {
    id:          row.id,
    name:        row.name,
    code_prefix: row.code_prefix,
    description: row.description ?? '',
    is_active:   row.is_active ?? true,
  }
}

async function deleteKategoriRow(row) {
  if (!confirm(`Hapus kategori "${row.name}" (prefix ${row.code_prefix})?`)) return
  try {
    await masterApi.tindakan.kategori.remove(row.id)
    showToast('s', 'Kategori dihapus')
    await refreshKategoriRows()
    await loadKategoriList()
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal hapus kategori')
  }
}

// ─── Kategori — CSV / Excel template / export / import ───────────────────
const katMenu = ref(null)          // 'template' | 'export' | null
const katFileInput = ref(null)
const katCsvBusy = ref(false)

function toggleKatMenu(name) { katMenu.value = katMenu.value === name ? null : name }
function closeKatMenu(e) { if (!e.target.closest?.('.tt-split')) katMenu.value = null }

function triggerKatDownload(blob, filename) {
  const url = URL.createObjectURL(blob instanceof Blob ? blob : new Blob([blob]))
  const a = document.createElement('a')
  a.href = url; a.download = filename
  document.body.appendChild(a); a.click(); document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

async function onKatTemplate(format = 'csv') {
  katMenu.value = null
  try {
    const res = await masterApi.tindakan.kategori.csvTemplate(format === 'xlsx' ? 'xlsx' : undefined)
    triggerKatDownload(res.data, `template-kategori-tindakan.${format}`)
  } catch (e) { showToast('e', 'Gagal unduh template') }
}

async function onKatExport(format = 'csv') {
  katMenu.value = null
  try {
    const res = await masterApi.tindakan.kategori.csvExport(format === 'xlsx' ? 'xlsx' : undefined)
    const stamp = new Date().toISOString().slice(0, 10).replace(/-/g, '')
    triggerKatDownload(res.data, `kategori-tindakan-${stamp}.${format}`)
  } catch (e) { showToast('e', 'Gagal export') }
}

function pickKatImport() { katFileInput.value?.click() }

async function onKatImport(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  katCsvBusy.value = true
  try {
    const res = await masterApi.tindakan.kategori.csvImport(file)
    const r = res.data?.data ?? {}
    const msg = `Import: ${r.inserted ?? 0} baru, ${r.updated ?? 0} update, ${r.skipped ?? 0} dilewati`
    showToast((r.errors?.length || (r.skipped ?? 0) > 0) ? 'w' : 's', msg)
    await refreshKategoriRows()
    await loadKategoriList()
  } catch (err) {
    showToast('e', err.response?.data?.message ?? 'Gagal import')
  } finally {
    katCsvBusy.value = false
  }
}

function emptyForm() {
  return {
    code:        '',
    name:        '',
    category:    '',
    base_price:  0,
    is_active:   true,
    keterangan:  '',
  }
}

function showToast(type, msg) {
  toast.value = { type, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

const formatRupiah = (v) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID', { minimumFractionDigits: 0 })

// ─── Buku Tarif terpadu (tab 'all') ─────────────────────────────────────────
// Satu daftar Tindakan+Obat+BHP+IOL berkategori dari GET /master/buku-tarif.
// Edit inline = set harga jual UMUM (PUT /master/buku-tarif/harga). Tambah/edit
// nama/kategori/CSV tetap lewat tab per-tipe (Tindakan/Obat/BHP/IOL).
const TIPE_LABEL = { tindakan: 'Tindakan', obat: 'Obat', bhp: 'BHP', iol: 'IOL' }
const buku = ref({ rows: [], meta: null, loading: false, error: null })
const bukuSearch = ref('')
const bukuKategori = ref('')
const bukuTipe = ref('')
const kategoriOptions = ref([])
let bukuSearchDebounce = null
const bukuEdit = ref({ key: null, value: '' })

async function loadBukuTarif(page = 1) {
  buku.value.loading = true
  buku.value.error = null
  try {
    const params = { page, per_page: 50 }
    if (bukuSearch.value) params.search = bukuSearch.value
    if (bukuKategori.value) params.kategori = bukuKategori.value
    if (bukuTipe.value) params.tipe = bukuTipe.value
    const res = await masterApi.bukuTarif.list(params)
    const payload = res.data?.data ?? res.data ?? {}
    const pag = payload.tarif ?? {}
    buku.value.rows = pag.data ?? []
    buku.value.meta = {
      current_page: pag.current_page ?? 1,
      last_page: pag.last_page ?? 1,
      per_page: pag.per_page ?? 50,
      total: pag.total ?? 0,
    }
    if (Array.isArray(payload.kategori_options)) kategoriOptions.value = payload.kategori_options
  } catch (e) {
    buku.value.error = e?.response?.data?.message || 'Gagal memuat Buku Tarif'
  } finally {
    buku.value.loading = false
  }
}

function onBukuSearch(v) {
  bukuSearch.value = v
  if (bukuSearchDebounce) clearTimeout(bukuSearchDebounce)
  bukuSearchDebounce = setTimeout(() => loadBukuTarif(), 300)
}

const bukuRowsNumbered = computed(() => {
  const start = ((buku.value.meta?.current_page ?? 1) - 1) * (buku.value.meta?.per_page ?? 50)
  return buku.value.rows.map((r, i) => ({ ...r, _no: start + i + 1, _key: `${r.tipe}:${r.id}` }))
})

function startEditHarga(row) {
  bukuEdit.value = { key: `${row.tipe}:${row.id}`, value: String(Math.round(Number(row.harga ?? 0))) }
}
function cancelEditHarga() { bukuEdit.value = { key: null, value: '' } }
async function saveEditHarga(row) {
  const price = Number(String(bukuEdit.value.value).replace(/[^\d]/g, ''))
  if (!Number.isFinite(price) || price < 0) { showToast('e', 'Harga tidak valid'); return }
  try {
    await masterApi.bukuTarif.setHarga({ tipe: row.tipe, item_id: row.id, price })
    row.harga = price
    showToast('s', 'Harga diperbarui')
    cancelEditHarga()
  } catch (e) {
    showToast('e', e?.response?.data?.message || 'Gagal menyimpan harga')
  }
}

// ─── Table config ──────────────────────────────────────────────────────────
const columns = [
  { key: '_no',        label: 'No',          width: '50px',  align: 'center' },
  { key: 'code',       label: 'Kode Tarif',  width: '140px' },
  { key: 'name',       label: 'Nama Tarif' },
  { key: 'category',   label: 'Kategori',    width: '170px' },
  { key: 'base_price', label: 'Harga',       width: '140px', align: 'right' },
  { key: 'is_active',  label: 'Status',      width: '100px', align: 'center' },
  { key: 'keterangan', label: 'Keterangan',  width: '200px' },
]

const rows = computed(() => {
  const slot = store.byResource[KEY]
  const startIdx = ((slot.meta?.current_page ?? 1) - 1) * (slot.meta?.per_page ?? 25)
  return (slot.items ?? []).map((r, i) => ({
    ...r,
    _no: startIdx + i + 1,
  }))
})

// ─── Search & filter ──────────────────────────────────────────────────────
function onSearchUpdate(v) {
  searchValue.value = v
  if (searchDebounce) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => refresh(), 300)
}

function onPageChange(p) {
  refresh(p)
}

async function refresh(page = 1) {
  const params = { page }
  if (searchValue.value) params.search = searchValue.value
  if (filterKategori.value) params.category = filterKategori.value
  await store.fetchList(KEY, params)
}

async function loadKategoriList() {
  try {
    const res = await masterApi.tindakan.kategoriList()
    const data = res.data?.data ?? []
    // Backend return array of { id, name, code_prefix }
    kategoriList.value = data
  } catch (e) {
    // silent — tidak fatal
  }
}

// Cari prefix dari nama kategori (untuk preview kode di form)
const previewCode = computed(() => {
  if (modal.value.mode !== 'create') return null
  const name = modal.value.payload?.category
  if (!name) return null
  const cat = kategoriList.value.find((k) => k.name === name)
  return cat ? `${cat.code_prefix}-NNN (auto-generate)` : null
})

// ─── CRUD ─────────────────────────────────────────────────────────────────
function openCreate() {
  modal.value = {
    open: true,
    mode: 'create',
    payload: emptyForm(),
    errors: null,
    submitting: false,
    editingId: null,
  }
}

function openEdit(row) {
  modal.value = {
    open: true,
    mode: 'edit',
    payload: {
      code:        row.code ?? '',
      name:        row.name ?? '',
      category:    row.category ?? '',
      base_price:  Number(row.base_price ?? 0),
      is_active:   row.is_active ?? true,
      keterangan:  row.keterangan ?? '',
    },
    errors: null,
    submitting: false,
    editingId: row.id,
  }
}

async function onSubmit(payload) {
  modal.value.submitting = true
  modal.value.errors = null
  try {
    if (modal.value.mode === 'create') {
      // Code di-drop dari payload — backend auto-generate dari kategori
      const { code, ...rest } = payload
      await store.create(KEY, rest)
      showToast('s', 'Tarif tindakan dibuat')
    } else {
      // Backend tidak izinkan ubah code via updateTindakan — kirim tanpa code
      const { code, ...updatable } = payload
      await store.update(KEY, modal.value.editingId, updatable)
      showToast('s', 'Tarif diperbarui')
    }
    modal.value.open = false
    await refresh()
  } catch (e) {
    if (e.response?.status === 422) {
      modal.value.errors = e.response.data?.errors ?? null
    }
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan tarif')
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
    await store.remove(KEY, confirmDelete.value.row.id)
    showToast('s', 'Tarif dihapus')
    confirmDelete.value.open = false
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus')
  } finally {
    confirmDelete.value.busy = false
  }
}

// ─── Modal field definitions ──────────────────────────────────────────────
const modalFields = computed(() => {
  const isCreate = modal.value.mode === 'create'
  const katOpts = kategoriList.value.map((k) => ({ value: k.name, label: `${k.name} (${k.code_prefix})` }))

  const fields = [
    { key: 'name',       label: 'Nama Tarif',  type: 'text',     required: true, cols: 1, placeholder: 'cth. Slit Lamp' },
    {
      key: 'category', label: 'Kategori', type: 'select', required: true, cols: 1, options: katOpts,
      hint: isCreate
        ? (previewCode.value ? `Kode akan: ${previewCode.value}` : 'Pilih kategori untuk auto-generate kode')
        : null,
    },
    { key: 'base_price', label: 'Harga (Rp)',  type: 'number',   required: true, min: 0, cols: 1, placeholder: '0' },
    { key: 'is_active',  label: 'Aktif',       type: 'checkbox', cols: 1 },
    { key: 'keterangan', label: 'Keterangan',  type: 'textarea', cols: 2, rows: 2, placeholder: 'Catatan opsional' },
  ]

  // Saat edit, tampilkan code read-only di depan (info, tidak bisa diubah)
  if (!isCreate) {
    fields.unshift({ key: 'code', label: 'Kode Tarif', type: 'text', disabled: true, cols: 2 })
  }
  return fields
})

// ─── Lifecycle ────────────────────────────────────────────────────────────
onMounted(async () => {
  document.addEventListener('click', closeKatMenu)
  await Promise.all([loadBukuTarif(), refresh(), loadKategoriList(), loadUmumInsurer()])
})
onUnmounted(() => document.removeEventListener('click', closeKatMenu))

function onImported(result) {
  showToast('s', `Import: ${result.inserted ?? 0} baru, ${result.updated ?? 0} update`)
  refresh()
  loadKategoriList()
}
</script>

<template>
  <div class="tt-wrap">
    <!-- Section header -->
    <div class="tt-section-head">
      <div>
        <h2>Buku Tarif</h2>
        <p>Sumber tunggal harga jual ke pasien: Tindakan, Obat, BHP, IOL. Tindakan kode auto-generate per kategori; override per penjamin di Metode Bayar. Harga Obat/BHP/IOL = harga jual tunggal (penjamin UMUM).</p>
      </div>
      <div v-if="activeTab === 'tindakan'" class="tt-header-actions">
        <button class="tt-btn-secondary" @click="openKategoriModal" title="Kelola master kategori + prefix kode">
          <svg viewBox="0 0 24 24"><path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
          Kelola Kategori
        </button>
        <button class="tt-btn-primary" @click="openCreate">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Tarif
        </button>
      </div>
    </div>

    <!-- Tab Jenis -->
    <div class="tt-jenis-tabs">
      <button
        v-for="t in TABS"
        :key="t.key"
        class="tt-jenis-tab"
        :class="{ active: activeTab === t.key }"
        @click="activeTab = t.key"
      >{{ t.label }}</button>
    </div>

    <!-- ══ Tab BUKU TARIF (terpadu, berkategori) ══ -->
    <template v-if="activeTab === 'all'">
      <div class="tt-buku-toolbar">
        <input
          class="tt-buku-search"
          type="search"
          :value="bukuSearch"
          placeholder="Cari kode / nama item…"
          @input="onBukuSearch($event.target.value)"
        />
        <select class="tt-buku-select" v-model="bukuTipe" @change="loadBukuTarif()">
          <option value="">Semua tipe</option>
          <option value="tindakan">Tindakan</option>
          <option value="obat">Obat</option>
          <option value="bhp">BHP</option>
          <option value="iol">IOL</option>
        </select>
        <select class="tt-buku-select" v-model="bukuKategori" @change="loadBukuTarif()">
          <option value="">Semua kategori</option>
          <option v-for="k in kategoriOptions" :key="k" :value="k">{{ k }}</option>
        </select>
        <span class="tt-buku-count" v-if="buku.meta">{{ buku.meta.total }} item</span>
      </div>

      <div v-if="buku.error" class="tt-jenis-warn">{{ buku.error }}</div>

      <div class="tt-buku-tablewrap">
        <table class="tt-buku-table">
          <thead>
            <tr>
              <th style="width:50px" class="ac">No</th>
              <th style="width:130px">Kode</th>
              <th>Nama</th>
              <th style="width:190px">Kategori</th>
              <th style="width:90px">Satuan</th>
              <th style="width:150px" class="ar">Harga (UMUM)</th>
              <th style="width:90px" class="ac">Status</th>
              <th style="width:90px" class="ac">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="buku.loading"><td colspan="8" class="tt-buku-empty">Memuat…</td></tr>
            <tr v-else-if="!bukuRowsNumbered.length"><td colspan="8" class="tt-buku-empty">Tidak ada item.</td></tr>
            <tr v-for="row in bukuRowsNumbered" :key="row._key">
              <td class="ac">{{ row._no }}</td>
              <td><span class="tt-code">{{ row.kode || '—' }}</span></td>
              <td>
                <strong class="tt-name">{{ row.nama }}</strong>
                <span class="tt-tipe-pill" :data-t="row.tipe">{{ TIPE_LABEL[row.tipe] }}</span>
              </td>
              <td><span class="tt-cat-pill">{{ row.kategori }}</span></td>
              <td>{{ row.satuan || '—' }}</td>
              <td class="ar">
                <input
                  v-if="bukuEdit.key === row._key"
                  class="tt-buku-priceinput"
                  v-model="bukuEdit.value"
                  @keyup.enter="saveEditHarga(row)"
                  @keyup.esc="cancelEditHarga"
                />
                <span v-else class="tt-price">{{ formatRupiah(row.harga) }}</span>
              </td>
              <td class="ac">
                <span class="tt-status" :class="row.aktif ? 'on' : 'off'">{{ row.aktif ? 'Aktif' : 'Nonaktif' }}</span>
              </td>
              <td class="ac">
                <template v-if="bukuEdit.key === row._key">
                  <button class="tt-icon-btn" title="Simpan" @click="saveEditHarga(row)">
                    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                  </button>
                  <button class="tt-icon-btn tt-icon-danger" title="Batal" @click="cancelEditHarga">
                    <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </template>
                <button v-else class="tt-icon-btn" title="Ubah harga jual UMUM" @click="startEditHarga(row)">
                  <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="tt-buku-pager" v-if="buku.meta && buku.meta.last_page > 1">
        <button class="tt-btn-secondary" :disabled="buku.meta.current_page <= 1" @click="loadBukuTarif(buku.meta.current_page - 1)">‹ Sebelumnya</button>
        <span>Hal {{ buku.meta.current_page }} / {{ buku.meta.last_page }}</span>
        <button class="tt-btn-secondary" :disabled="buku.meta.current_page >= buku.meta.last_page" @click="loadBukuTarif(buku.meta.current_page + 1)">Berikutnya ›</button>
      </div>
    </template>

    <!-- ══ Tab TINDAKAN (procedures) ══ -->
    <template v-else-if="activeTab === 'tindakan'">
    <!-- CSV / Excel bar — backend tindakan mendukung ?format=xlsx (csvOrXlsx) -->
    <CsvActionBar :resource-key="KEY" :show-template="true" :allow-excel="true" @imported="onImported" @error="(m) => showToast('e', m)" />

    <!-- Filter kategori chips -->
    <div v-if="kategoriList.length" class="tt-filters">
      <span class="tt-filter-label">Filter kategori:</span>
      <button
        class="tt-chip"
        :class="{ active: !filterKategori }"
        @click="filterKategori = ''; refresh()"
      >Semua</button>
      <button
        v-for="c in kategoriList"
        :key="c.id"
        class="tt-chip"
        :class="{ active: filterKategori === c.name }"
        @click="filterKategori = c.name; refresh()"
      >{{ c.name }} <span class="tt-chip-prefix">{{ c.code_prefix }}</span></button>
    </div>

    <!-- Main table -->
    <MasterTable
      :columns="columns"
      :rows="rows"
      :loading="store.byResource[KEY].loading"
      :error="store.byResource[KEY].error"
      :meta="store.byResource[KEY].meta"
      :search-value="searchValue"
      search-placeholder="Cari kode / nama / kategori / keterangan…"
      empty-text="Belum ada tarif tindakan. Klik “Tambah Tarif” atau import CSV."
      @update:search="onSearchUpdate"
      @page-change="onPageChange"
      @refresh="refresh"
    >
      <template #cell-code="{ value }">
        <span class="tt-code">{{ value || '—' }}</span>
      </template>

      <template #cell-name="{ value }">
        <strong class="tt-name">{{ value }}</strong>
      </template>

      <template #cell-category="{ value }">
        <span class="tt-cat-pill">{{ value || '—' }}</span>
      </template>

      <template #cell-base_price="{ value }">
        <span class="tt-price">{{ formatRupiah(value) }}</span>
      </template>

      <template #cell-is_active="{ value }">
        <span class="tt-status" :class="value ? 'on' : 'off'">
          {{ value ? 'Aktif' : 'Nonaktif' }}
        </span>
      </template>

      <template #cell-keterangan="{ value }">
        <span class="tt-note">{{ value || '—' }}</span>
      </template>

      <template #actions="{ row }">
        <button class="tt-icon-btn" title="Edit" @click="openEdit(row)">
          <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
        </button>
        <button class="tt-icon-btn tt-icon-danger" title="Hapus" @click="askDelete(row)">
          <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </button>
      </template>
    </MasterTable>
    </template>

    <!-- ══ Tab OBAT / BHP / IOL — harga jual tunggal (insurer UMUM) ══ -->
    <template v-else-if="['obat', 'bhp', 'iol'].includes(activeTab)">
      <div v-if="!umumId" class="tt-jenis-warn">
        Penjamin UMUM belum tersedia. Pastikan data penjamin sistem sudah ter-seed.
      </div>
      <MetodeBayarTarifTab
        v-else
        :key="activeTab"
        :type="activeTab"
        :insurer-id="umumId"
        :read-only="false"
        :buku-tarif="true"
      />
    </template>

    <!-- ══ Tab TARIF KAMAR — sewa kamar inap per kelas × penjamin ══ -->
    <template v-else-if="activeTab === 'kamar'">
      <RoomTarifPanel />
    </template>

    <!-- Modal CRUD -->
    <MasterFormModal
      v-model:open="modal.open"
      v-model="modal.payload"
      :title="modal.mode === 'create' ? 'Tambah Tarif Tindakan' : 'Edit Tarif Tindakan'"
      :fields="modalFields"
      :submitting="modal.submitting"
      :errors="modal.errors"
      :submit-label="modal.mode === 'create' ? 'Simpan Tarif' : 'Simpan Perubahan'"
      width="640px"
      @submit="onSubmit"
    />

    <!-- Modal Kelola Kategori (CRUD master kategori) -->
    <Teleport to="body">
      <div v-if="kategoriModal.open" class="tt-kat-overlay" @click.self="kategoriModal.open = false">
        <div class="tt-kat">
          <div class="tt-kat-head">
            <h3>Kelola Kategori Tindakan</h3>
            <button class="tt-kat-close" @click="kategoriModal.open = false">×</button>
          </div>
          <p class="tt-kat-sub">
            Kategori menentukan prefix kode tindakan. Mis. kategori "Tindakan" prefix <code>TND</code>
            → kode auto: <code>TND-001</code>, <code>TND-002</code>, dst.
          </p>

          <!-- CSV / Excel: Template / Import / Export -->
          <div class="tt-kat-csv">
            <div class="tt-split">
              <button class="tt-btn-csv" @click.stop="toggleKatMenu('template')" title="Unduh template (header + petunjuk)">
                <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Template
                <svg class="tt-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
              </button>
              <div v-if="katMenu === 'template'" class="tt-menu">
                <button @click="onKatTemplate('csv')">CSV (.csv)</button>
                <button @click="onKatTemplate('xlsx')">Excel (.xlsx)</button>
              </div>
            </div>
            <button class="tt-btn-csv" :disabled="katCsvBusy" @click="pickKatImport" title="Import kategori dari CSV/Excel">
              <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              {{ katCsvBusy ? 'Mengimport…' : 'Import' }}
            </button>
            <div class="tt-split">
              <button class="tt-btn-csv" @click.stop="toggleKatMenu('export')" title="Export semua kategori">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export
                <svg class="tt-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
              </button>
              <div v-if="katMenu === 'export'" class="tt-menu">
                <button @click="onKatExport('csv')">CSV (.csv)</button>
                <button @click="onKatExport('xlsx')">Excel (.xlsx)</button>
              </div>
            </div>
            <input ref="katFileInput" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" style="display:none" @change="onKatImport" />
          </div>

          <!-- Form add/edit -->
          <div class="tt-kat-form">
            <div class="tt-kat-form-row">
              <label>
                Nama Kategori
                <input v-model="katForm.name" type="text" placeholder="cth. Tindakan" />
              </label>
              <label>
                Code Prefix
                <input v-model="katForm.code_prefix" type="text" placeholder="cth. TND" maxlength="10"
                  @input="katForm.code_prefix = katForm.code_prefix.toUpperCase()" />
              </label>
              <label>
                Status
                <select v-model="katForm.is_active">
                  <option :value="true">Aktif</option>
                  <option :value="false">Nonaktif</option>
                </select>
              </label>
            </div>
            <label class="tt-kat-desc-label">
              Deskripsi (opsional)
              <input v-model="katForm.description" type="text" placeholder="penjelasan kategori" />
            </label>
            <div class="tt-kat-form-actions">
              <button v-if="katForm.id" class="tt-btn-secondary" :disabled="kategoriBusy" @click="resetKatForm">Batal Edit</button>
              <button class="tt-btn-primary" :disabled="kategoriBusy" @click="submitKategori">
                {{ katForm.id ? 'Simpan Perubahan' : 'Tambah Kategori' }}
              </button>
            </div>
          </div>

          <!-- List kategori existing -->
          <div class="tt-kat-list">
            <div class="tt-kat-list-head">
              <span>Nama</span>
              <span>Prefix</span>
              <span>Status</span>
              <span>Aksi</span>
            </div>
            <div v-if="!kategoriRows.length" class="tt-kat-empty">Belum ada kategori. Tambah di atas.</div>
            <div v-for="row in kategoriRows" :key="row.id" class="tt-kat-row">
              <span class="tt-kat-name">{{ row.name }}</span>
              <span class="tt-code">{{ row.code_prefix }}</span>
              <span class="tt-status" :class="row.is_active ? 'on' : 'off'">
                {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
              </span>
              <span class="tt-kat-actions">
                <button class="tt-icon-btn" title="Edit" @click="editKategoriRow(row)">
                  <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                </button>
                <button class="tt-icon-btn tt-icon-danger" title="Hapus" @click="deleteKategoriRow(row)">
                  <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
              </span>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Confirm delete modal -->
    <Teleport to="body">
      <div v-if="confirmDelete.open" class="tt-confirm-overlay" @click.self="confirmDelete.open = false">
        <div class="tt-confirm">
          <div class="tt-confirm-icon">
            <svg viewBox="0 0 24 24"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          </div>
          <h3>Hapus tarif?</h3>
          <p>
            <strong>{{ confirmDelete.row?.name }}</strong>
            (kode <strong>{{ confirmDelete.row?.code }}</strong>) akan dihapus permanen.
          </p>
          <div class="tt-confirm-actions">
            <button class="tt-btn-secondary" :disabled="confirmDelete.busy" @click="confirmDelete.open = false">Batal</button>
            <button class="tt-btn-danger" :disabled="confirmDelete.busy" @click="doDelete">
              {{ confirmDelete.busy ? 'Menghapus…' : 'Hapus' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Toast -->
    <Teleport to="body">
      <div v-if="toast" class="tt-toast-wrap">
        <div class="tt-toast" :class="`tt-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.tt-wrap { display: flex; flex-direction: column; gap: 1rem; }

/* ─── Tab Jenis (Buku Tarif) ─── */
.tt-jenis-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--gb); }
.tt-jenis-tab { padding: 9px 18px; border: none; background: transparent; color: var(--tm); font-size: 13px; font-weight: 500; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: color 0.15s, border-color 0.15s; }
.tt-jenis-tab:hover { color: var(--td); }
.tt-jenis-tab.active { color: var(--ga); border-bottom-color: var(--ga); }
.tt-jenis-warn { padding: 1.2rem; text-align: center; color: var(--wt); background: var(--wb); border: 1px solid var(--wbd); border-radius: 10px; font-size: 13px; }

.tt-section-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; }
.tt-section-head h2 { font-family: 'Space Grotesk', serif; font-size: 20px; color: var(--td); margin: 0; }
.tt-section-head p { font-size: 13px; color: var(--tm); margin: 4px 0 0; max-width: 640px; }

.tt-btn-primary { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; border: 1px solid var(--ga); background: var(--ga); color: white; font-size: 13px; font-weight: 500; cursor: pointer; transition: background 0.15s; }
.tt-btn-primary:hover { background: var(--gm); border-color: var(--gm); }
.tt-btn-primary svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }

.tt-filters { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; padding: 0.6rem 0.8rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; }
.tt-filter-label { font-size: 12px; color: var(--tm); font-weight: 500; margin-right: 4px; }
.tt-chip { padding: 5px 12px; border-radius: 999px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 11.5px; cursor: pointer; font-weight: 500; transition: background 0.15s, border-color 0.15s, color 0.15s; }
.tt-chip:hover { background: var(--gl); border-color: var(--ga); color: var(--td); }
.tt-chip.active { background: var(--ga); border-color: var(--ga); color: white; }

.tt-code { font-family: 'Geist Mono', monospace; font-size: 12px; color: var(--td); background: var(--bs); padding: 2px 8px; border-radius: 6px; border: 1px solid var(--gb); }
.tt-name { font-weight: 500; color: var(--td); }
.tt-cat-pill { display: inline-block; padding: 3px 9px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.tt-price { font-weight: 600; color: var(--td); font-variant-numeric: tabular-nums; }
.tt-status { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 500; }
.tt-status.on { background: var(--sb); color: var(--st); }
.tt-status.off { background: var(--eb); color: var(--et); }
.tt-note { font-size: 12px; color: var(--tm); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

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

/* ─── Header actions (Kelola Kategori + Tambah Tarif) ─── */
.tt-header-actions { display: flex; gap: 0.5rem; align-items: center; }
.tt-btn-secondary svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; margin-right: 6px; vertical-align: middle; }
.tt-btn-secondary { padding: 9px 14px; }

/* ─── Filter chip prefix label ─── */
.tt-chip-prefix { font-family: 'Geist Mono', monospace; font-size: 9.5px; opacity: 0.75; margin-left: 4px; padding: 1px 5px; border-radius: 4px; background: rgba(0,0,0,0.06); }
.tt-chip.active .tt-chip-prefix { background: rgba(255,255,255,0.25); }

/* ─── Modal Kelola Kategori ─── */
.tt-kat-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 9100; backdrop-filter: blur(3px); padding: 1rem; }
.tt-kat { background: var(--bc); border-radius: 16px; width: 720px; max-width: 95vw; max-height: 88vh; overflow-y: auto; border: 1px solid var(--gb); padding: 1.5rem 1.6rem; display: flex; flex-direction: column; gap: 0.9rem; box-shadow: 0 20px 60px rgba(0,0,0,0.22); }
.tt-kat-head { display: flex; justify-content: space-between; align-items: center; }
.tt-kat-head h3 { font-family: 'Space Grotesk', serif; font-size: 20px; color: var(--td); margin: 0; }
.tt-kat-close { background: transparent; border: none; font-size: 24px; color: var(--tm); cursor: pointer; padding: 0 8px; line-height: 1; }
.tt-kat-close:hover { color: var(--td); }
.tt-kat-sub { font-size: 12.5px; color: var(--tm); margin: 0; }
.tt-kat-sub code { background: var(--bs); padding: 1px 6px; border-radius: 4px; font-family: 'Geist Mono', monospace; font-size: 11.5px; color: var(--td); border: 1px solid var(--gb); }

.tt-kat-csv { display: flex; gap: 6px; flex-wrap: wrap; }
.tt-split { position: relative; }
.tt-caret { width: 11px !important; height: 11px !important; margin-left: 1px; }
.tt-btn-csv { display: inline-flex; align-items: center; gap: 6px; padding: 7px 11px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 12px; font-weight: 500; cursor: pointer; transition: background 0.15s, color 0.15s, border-color 0.15s; }
.tt-btn-csv svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.tt-btn-csv:hover:not(:disabled) { background: var(--gl); color: var(--td); border-color: var(--ga); }
.tt-btn-csv:disabled { opacity: 0.45; cursor: not-allowed; }
.tt-menu { position: absolute; top: calc(100% + 4px); left: 0; z-index: 50; background: var(--bc); border: 1px solid var(--gb); border-radius: 9px; box-shadow: 0 8px 24px rgba(0,0,0,0.14); padding: 4px; min-width: 140px; display: flex; flex-direction: column; gap: 2px; }
.tt-menu button { text-align: left; padding: 7px 10px; border: none; background: transparent; border-radius: 6px; font-size: 12px; color: var(--td); cursor: pointer; }
.tt-menu button:hover { background: var(--gl); color: var(--ga); }

.tt-kat-form { background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; padding: 1rem 1.1rem; display: flex; flex-direction: column; gap: 0.7rem; }
.tt-kat-form-row { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 0.7rem; }
.tt-kat-form-row label, .tt-kat-desc-label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--tm); font-weight: 500; }
.tt-kat-form input, .tt-kat-form select { padding: 7px 10px; border-radius: 7px; border: 1px solid var(--gb); background: var(--bc); color: var(--td); font-size: 13px; }
.tt-kat-form input:focus, .tt-kat-form select:focus { outline: none; border-color: var(--ga); }
.tt-kat-form-actions { display: flex; justify-content: flex-end; gap: 0.5rem; }

.tt-kat-list { border: 1px solid var(--gb); border-radius: 10px; overflow: hidden; }
.tt-kat-list-head, .tt-kat-row { display: grid; grid-template-columns: 2fr 100px 120px 100px; gap: 0.6rem; padding: 9px 14px; align-items: center; font-size: 12.5px; }
.tt-kat-list-head { background: var(--bs); color: var(--tu); font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; border-bottom: 1px solid var(--gb); }
.tt-kat-row { border-bottom: 1px solid var(--gb); }
.tt-kat-row:last-child { border-bottom: none; }
.tt-kat-row:hover { background: var(--bs); }
.tt-kat-name { font-weight: 500; color: var(--td); }
.tt-kat-actions { display: flex; gap: 4px; }
.tt-kat-empty { padding: 1.5rem; text-align: center; color: var(--tm); font-size: 12.5px; }

/* ── Buku Tarif terpadu ── */
.tt-buku-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; margin-bottom: 0.9rem; }
.tt-buku-search { flex: 1 1 260px; min-width: 200px; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--td); font-size: 13px; }
.tt-buku-select { padding: 8px 10px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--td); font-size: 13px; }
.tt-buku-search:focus, .tt-buku-select:focus { outline: none; border-color: var(--ga); }
.tt-buku-count { margin-left: auto; font-size: 12px; color: var(--tm); white-space: nowrap; }
.tt-buku-tablewrap { border: 1px solid var(--gb); border-radius: 10px; overflow: hidden; }
.tt-buku-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.tt-buku-table thead th { background: var(--bs); color: var(--tu); font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; padding: 9px 12px; text-align: left; border-bottom: 1px solid var(--gb); }
.tt-buku-table tbody td { padding: 8px 12px; border-bottom: 1px solid var(--gb); color: var(--td); vertical-align: middle; }
.tt-buku-table tbody tr:last-child td { border-bottom: none; }
.tt-buku-table tbody tr:hover { background: var(--bs); }
.tt-buku-table .ar { text-align: right; }
.tt-buku-table .ac { text-align: center; }
.tt-buku-empty { padding: 1.6rem; text-align: center; color: var(--tm); }
.tt-tipe-pill { display: inline-block; margin-left: 7px; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; background: var(--bs); color: var(--tu); border: 1px solid var(--gb); }
.tt-tipe-pill[data-t="tindakan"] { color: #2563eb; border-color: #bfdbfe; background: #eff6ff; }
.tt-tipe-pill[data-t="obat"] { color: #16a34a; border-color: #bbf7d0; background: #f0fdf4; }
.tt-tipe-pill[data-t="bhp"] { color: #c2410c; border-color: #fed7aa; background: #fff7ed; }
.tt-tipe-pill[data-t="iol"] { color: #7c3aed; border-color: #ddd6fe; background: #f5f3ff; }
.tt-buku-priceinput { width: 120px; padding: 5px 8px; border-radius: 6px; border: 1px solid var(--ga); background: var(--bc); color: var(--td); font-size: 12.5px; text-align: right; }
.tt-buku-priceinput:focus { outline: none; }
.tt-buku-pager { display: flex; align-items: center; justify-content: center; gap: 1rem; margin-top: 0.9rem; font-size: 12.5px; color: var(--tm); }
.tt-buku-pager button:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
