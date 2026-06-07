<script setup>
/**
 * PaketBedahListView — daftar paket bedah dengan ringkasan komposisi & jumlah penjamin.
 * Edit detail (items + tariffs) buka di sub-page /tarif-paket/paket-bedah/:id
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useTarifPaketStore } from '@/stores/tarifPaketStore'
import MasterTable from '@/components/master-data/MasterTable.vue'
import MasterFormModal from '@/components/master-data/MasterFormModal.vue'

const router = useRouter()
const store = useTarifPaketStore()

const modal = ref({
  open: false, mode: 'create',
  payload: emptyForm(),
  errors: null, submitting: false, editingId: null,
})
const confirmDelete = ref({ open: false, row: null, busy: false })
const toast = ref(null)
const searchValue = ref('')
const filterActive = ref('')

const csvBusy = ref({ template: false, export: false, import: false })
const importResult = ref(null) // { created, updated, items_inserted, items_lookup_fail, errors }
const importFileInput = ref(null)
const openMenu = ref(null)   // 'template' | 'export' | null

function toggleMenu(name) {
  openMenu.value = openMenu.value === name ? null : name
}
function closeMenuOnOutside(e) {
  if (!e.target.closest?.('.pl-split')) openMenu.value = null
}

function showToast(t, msg) {
  toast.value = { type: t, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

function emptyForm() {
  return {
    name: '', package_type: 'BEDAH', category: '', surgery_type: '',
    description: '', keterangan: '',
    price: 0,
    is_active: true,
  }
}

const columns = [
  { key: 'name',             label: 'Nama Paket' },
  { key: 'package_type',     label: 'Jenis',       width: '110px' },
  { key: 'category',         label: 'Kategori',    width: '140px' },
  { key: 'surgery_type',     label: 'Jenis Bedah', width: '130px' },
  { key: 'items_count',      label: 'Items',       width: '70px', align: 'right' },
  { key: 'total_base_price', label: 'Base Total',  width: '140px', align: 'right' },
  { key: 'tariffs_count',    label: 'Tarif Jual',  width: '90px', align: 'right' },
  { key: 'is_active',        label: 'Status',      width: '90px', align: 'center' },
]

const rows = computed(() => {
  return (store.paket.items ?? []).map((r) => ({
    ...r,
    items_count:   (r.items ?? []).length,
    tariffs_count: (r.package_tariffs ?? r.packageTariffs ?? []).length,
  }))
})

const formatRp = (v) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID')

async function refresh() {
  const params = {}
  if (searchValue.value) params.search = searchValue.value
  if (filterActive.value !== '') params.active = filterActive.value
  await store.fetchPaketList({ page: 1, ...params })
}

function onPageChange(p) {
  store.fetchPaketList({ page: p, search: searchValue.value || undefined, active: filterActive.value === '' ? undefined : filterActive.value })
}

function onSearchUpdate(v) { searchValue.value = v; refresh() }

function openCreate() {
  modal.value = { open: true, mode: 'create', payload: emptyForm(), errors: null, submitting: false, editingId: null }
}

function openEdit(row) {
  modal.value = {
    open: true, mode: 'edit',
    payload: {
      name: row.name ?? '',
      package_type: row.package_type ?? 'BEDAH',
      category: row.category ?? '',
      surgery_type: row.surgery_type ?? '',
      description: row.description ?? '',
      keterangan: row.keterangan ?? '',
      price: row.price ?? 0,
      is_active: !!row.is_active,
    },
    errors: null, submitting: false, editingId: row.id,
  }
}

async function onSubmit(payload) {
  modal.value.submitting = true
  modal.value.errors = null
  try {
    if (modal.value.mode === 'create') {
      const created = await store.createPaket(payload)
      showToast('s', 'Paket dibuat — buka detail untuk tambah item & tarif')
      modal.value.open = false
      // Auto-navigate ke detail supaya admin langsung isi items + tariffs
      if (created?.id) router.push(`/tarif-paket/paket-bedah/${created.id}`)
    } else {
      await store.updatePaket(modal.value.editingId, payload)
      showToast('s', 'Paket diperbarui')
      modal.value.open = false
    }
  } catch (e) {
    if (e.response?.status === 422) modal.value.errors = e.response.data?.errors ?? null
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan')
  } finally {
    modal.value.submitting = false
  }
}

function askDelete(row) { confirmDelete.value = { open: true, row, busy: false } }

async function doDelete() {
  confirmDelete.value.busy = true
  try {
    await store.removePaket(confirmDelete.value.row.id)
    showToast('s', 'Paket dihapus')
    confirmDelete.value.open = false
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus')
  } finally {
    confirmDelete.value.busy = false
  }
}

const fields = [
  { key: 'name',               label: 'Nama Paket',     type: 'text',     required: true, cols: 2, placeholder: 'mis. Paket Phaco + IOL Monofocal' },
  { key: 'package_type',       label: 'Jenis Paket',    type: 'select',   cols: 1, options: [{ value: 'BEDAH', label: 'Bedah' }, { value: 'PEMERIKSAAN', label: 'Pemeriksaan' }], hint: 'Pemeriksaan: poliklinik, komponen tindakan saja' },
  { key: 'category',           label: 'Kategori',       type: 'text',     cols: 1, placeholder: 'mis. SBL / Glaukoma / Refraktif' },
  { key: 'surgery_type',       label: 'Jenis Bedah',    type: 'select',   cols: 1, options: [
      { value: '', label: '— pilih / auto dari nama —' },
      { value: 'KATARAK', label: 'Katarak' },
      { value: 'VITREORETINA', label: 'Vitreoretina' },
      { value: 'GLAUKOMA', label: 'Glaukoma' },
      { value: 'LAINNYA', label: 'Lainnya' },
    ], hint: 'Penentu form operasi resmi (Vitreoretina → RM 10.1). Kosongkan = auto dari nama paket.' },
  { key: 'price',              label: 'Harga Acuan (Rp)', type: 'number', min: 0, cols: 1, hint: 'Diabaikan jika ada tarif per penjamin' },
  { key: 'description',        label: 'Deskripsi',      type: 'textarea', cols: 2, rows: 2 },
  { key: 'keterangan',         label: 'Keterangan',     type: 'text',     cols: 2 },
  { key: 'is_active',          label: 'Aktif',          type: 'checkbox', cols: 1 },
]

function goDetail(row) { router.push(`/tarif-paket/paket-bedah/${row.id}`) }

// ─── CSV Template / Export / Import ──────────────────────────────────────
async function onDownloadTemplate(format = 'csv') {
  openMenu.value = null
  csvBusy.value.template = true
  try {
    await store.downloadPaketTemplate(format)
    showToast('s', `Template ${format.toUpperCase()} diunduh`)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal mengunduh template')
  } finally {
    csvBusy.value.template = false
  }
}

async function onExportCsv(format = 'csv') {
  openMenu.value = null
  csvBusy.value.export = true
  try {
    await store.exportPaketCsv(format)
    showToast('s', `Paket bedah diunduh (${format.toUpperCase()})`)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal export')
  } finally {
    csvBusy.value.export = false
  }
}

function pickImportFile() {
  importFileInput.value?.click()
}

async function onImportFileChange(evt) {
  const file = evt.target.files?.[0]
  evt.target.value = '' // reset supaya event re-fire kalau file sama dipilih
  if (!file) return
  csvBusy.value.import = true
  importResult.value = null
  try {
    const result = await store.importPaketCsv(file)
    importResult.value = result
    const msg = `Import: ${result.created} baru, ${result.updated} update, ${result.items_inserted} item`
      + (result.items_lookup_fail > 0 ? `, ${result.items_lookup_fail} item gagal lookup` : '')
    showToast(result.items_lookup_fail > 0 || result.errors?.length ? 'w' : 's', msg)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal import CSV')
  } finally {
    csvBusy.value.import = false
  }
}

onMounted(() => {
  document.addEventListener('click', closeMenuOnOutside)
  refresh()
})
onUnmounted(() => document.removeEventListener('click', closeMenuOnOutside))
</script>

<template>
  <div class="pl-wrap">
    <div class="pl-head">
      <div>
        <h2>Daftar Paket Bedah</h2>
        <p>Setiap paket berisi komposisi (tindakan + obat + BHP + IOL) + harga jual per penjamin (sistem auto-diskon).</p>
      </div>
      <div class="pl-head-actions">
        <div class="pl-split">
          <button class="pl-btn-ghost" :disabled="csvBusy.template" @click.stop="toggleMenu('template')" title="Unduh template kosong (header + contoh) — pilih format">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Template
            <svg class="pl-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div v-if="openMenu === 'template'" class="pl-menu">
            <button @click="onDownloadTemplate('csv')">CSV (.csv)</button>
            <button @click="onDownloadTemplate('xlsx')">Excel (.xlsx)</button>
          </div>
        </div>
        <div class="pl-split">
          <button class="pl-btn-ghost" :disabled="csvBusy.export" @click.stop="toggleMenu('export')" title="Export semua paket + komposisi — pilih format">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            {{ csvBusy.export ? 'Export…' : 'Export' }}
            <svg class="pl-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div v-if="openMenu === 'export'" class="pl-menu">
            <button @click="onExportCsv('csv')">CSV (.csv)</button>
            <button @click="onExportCsv('xlsx')">Excel (.xlsx)</button>
          </div>
        </div>
        <button class="pl-btn-ghost" :disabled="csvBusy.import" @click="pickImportFile" title="Import paket dari CSV/Excel (replace komposisi pada paket existing)">
          <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          {{ csvBusy.import ? 'Import…' : 'Import' }}
        </button>
        <input ref="importFileInput" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden @change="onImportFileChange" />
        <button class="pl-btn-primary" @click="openCreate">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Paket
        </button>
      </div>
    </div>

    <div class="pl-filters">
      <span class="pl-filter-label">Status:</span>
      <button class="pl-chip" :class="{ active: filterActive === '' }" @click="filterActive = ''; refresh()">Semua</button>
      <button class="pl-chip" :class="{ active: filterActive === 1 }" @click="filterActive = 1; refresh()">Aktif</button>
      <button class="pl-chip" :class="{ active: filterActive === 0 }" @click="filterActive = 0; refresh()">Nonaktif</button>
    </div>

    <MasterTable
      :columns="columns"
      :rows="rows"
      :loading="store.paket.loading"
      :error="store.paket.error"
      :meta="store.paket.meta"
      :search-value="searchValue"
      search-placeholder="Cari nama / kategori paket…"
      :show-search="true"
      empty-text="Belum ada paket bedah. Klik “Tambah Paket” untuk mulai."
      @update:search="onSearchUpdate"
      @page-change="onPageChange"
      @refresh="refresh"
    >
      <template #cell-name="{ row }">
        <div class="pl-cell-name">
          <strong @click="goDetail(row)" class="pl-name-link">{{ row.name }}</strong>
        </div>
      </template>

      <template #cell-package_type="{ value }">
        <span class="pl-jenis" :class="value === 'PEMERIKSAAN' ? 'pemeriksaan' : 'bedah'">
          {{ value === 'PEMERIKSAAN' ? 'Pemeriksaan' : 'Bedah' }}
        </span>
      </template>

      <template #cell-category="{ value }">
        <span v-if="value" class="pl-tag">{{ value }}</span>
        <span v-else>—</span>
      </template>

      <template #cell-items_count="{ value }">
        <span class="pl-pill" :class="{ zero: !value }">{{ value }}</span>
      </template>

      <template #cell-tariffs_count="{ value }">
        <span class="pl-pill" :class="{ zero: !value }">{{ value }}</span>
      </template>

      <template #cell-total_base_price="{ value }">
        <span class="pl-price">{{ formatRp(value) }}</span>
      </template>

      <template #cell-is_active="{ value }">
        <span class="pl-status" :class="value ? 'on' : 'off'">{{ value ? 'Aktif' : 'Nonaktif' }}</span>
      </template>

      <template #actions="{ row }">
        <button class="pl-icon-btn" title="Buka detail (items + tariffs)" @click="goDetail(row)">
          <svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
        </button>
        <button class="pl-icon-btn" title="Edit info paket" @click="openEdit(row)">
          <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
        </button>
        <button class="pl-icon-btn pl-icon-danger" title="Hapus" @click="askDelete(row)">
          <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </button>
      </template>
    </MasterTable>

    <MasterFormModal
      v-model:open="modal.open"
      v-model="modal.payload"
      :title="modal.mode === 'create' ? 'Tambah Paket Bedah' : 'Edit Paket Bedah'"
      :fields="fields"
      :submitting="modal.submitting"
      :errors="modal.errors"
      :submit-label="modal.mode === 'create' ? 'Buat Paket' : 'Simpan'"
      width="600px"
      @submit="onSubmit"
    />

    <Teleport to="body">
      <div v-if="confirmDelete.open" class="pl-confirm-overlay" @click.self="confirmDelete.open = false">
        <div class="pl-confirm">
          <div class="pl-confirm-icon">
            <svg viewBox="0 0 24 24"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
          </div>
          <h3>Hapus paket?</h3>
          <p><strong>{{ confirmDelete.row?.name }}</strong> beserta semua items &amp; tariffs akan dihapus permanen.</p>
          <div class="pl-confirm-actions">
            <button class="pl-btn-secondary" :disabled="confirmDelete.busy" @click="confirmDelete.open = false">Batal</button>
            <button class="pl-btn-danger" :disabled="confirmDelete.busy" @click="doDelete">{{ confirmDelete.busy ? 'Menghapus…' : 'Hapus' }}</button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="importResult" class="pl-confirm-overlay" @click.self="importResult = null">
        <div class="pl-import-result">
          <div class="pl-import-head">
            <h3>Hasil Import CSV</h3>
            <button class="pl-icon-btn" @click="importResult = null" title="Tutup">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="pl-import-stats">
            <div class="pl-stat-card"><span class="pl-stat-num">{{ importResult.created }}</span><span class="pl-stat-lbl">Paket baru</span></div>
            <div class="pl-stat-card"><span class="pl-stat-num">{{ importResult.updated }}</span><span class="pl-stat-lbl">Paket update</span></div>
            <div class="pl-stat-card"><span class="pl-stat-num">{{ importResult.items_inserted }}</span><span class="pl-stat-lbl">Item dimasukkan</span></div>
            <div class="pl-stat-card" :class="{ warn: importResult.items_lookup_fail > 0 }">
              <span class="pl-stat-num">{{ importResult.items_lookup_fail }}</span>
              <span class="pl-stat-lbl">Item gagal lookup</span>
            </div>
          </div>
          <div v-if="importResult.errors?.length" class="pl-import-errors">
            <div class="pl-import-errors-head">⚠ {{ importResult.errors.length }} baris bermasalah</div>
            <ul>
              <li v-for="(err, i) in importResult.errors.slice(0, 50)" :key="i">{{ err }}</li>
              <li v-if="importResult.errors.length > 50" class="pl-more">… dan {{ importResult.errors.length - 50 }} error lainnya</li>
            </ul>
          </div>
          <div class="pl-confirm-actions">
            <button class="pl-btn-primary" @click="importResult = null">Tutup</button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="toast" class="pl-toast-wrap">
        <div class="pl-toast" :class="`pl-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.pl-wrap { display: flex; flex-direction: column; gap: 1rem; }
.pl-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; }
.pl-head h2 { font-family: 'Space Grotesk', serif; font-size: 20px; color: var(--td); margin: 0; }
.pl-head p { font-size: 13px; color: var(--tm); margin: 4px 0 0; }

.pl-head-actions { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }

.pl-btn-primary { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; border: 1px solid var(--ga); background: var(--ga); color: white; font-size: 13px; font-weight: 500; cursor: pointer; }
.pl-btn-primary:hover:not(:disabled) { background: var(--gm); border-color: var(--gm); }
.pl-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.pl-btn-primary svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }

.pl-btn-ghost { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 9px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 12.5px; font-weight: 500; cursor: pointer; }
.pl-btn-ghost:hover:not(:disabled) { background: var(--gl); color: var(--td); border-color: var(--ga); }
.pl-btn-ghost:disabled { opacity: 0.5; cursor: not-allowed; }
.pl-btn-ghost svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.pl-split { position: relative; }
.pl-caret { width: 11px !important; height: 11px !important; margin-left: 1px; }
.pl-menu { position: absolute; top: calc(100% + 4px); left: 0; z-index: 50; background: var(--bc); border: 1px solid var(--gb); border-radius: 9px; box-shadow: 0 8px 24px rgba(0,0,0,0.14); padding: 4px; min-width: 140px; display: flex; flex-direction: column; gap: 2px; }
.pl-menu button { text-align: left; padding: 7px 10px; border: none; background: transparent; border-radius: 6px; font-size: 12px; color: var(--td); cursor: pointer; }
.pl-menu button:hover { background: var(--gl); color: var(--ga); }

.pl-filters { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; padding: 0.55rem 0.8rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; }
.pl-filter-label { font-size: 12px; color: var(--tm); font-weight: 500; margin-right: 4px; }
.pl-chip { padding: 5px 12px; border-radius: 999px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 11.5px; cursor: pointer; font-weight: 500; }
.pl-chip:hover { background: var(--gl); border-color: var(--ga); color: var(--td); }
.pl-chip.active { background: var(--ga); border-color: var(--ga); color: white; }

.pl-cell-name { display: flex; flex-direction: column; gap: 1px; }
.pl-cell-name strong { font-weight: 500; color: var(--td); }
.pl-name-link { cursor: pointer; }
.pl-name-link:hover { color: var(--ga); text-decoration: underline; }

.pl-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.pl-jenis { display: inline-block; padding: 2px 9px; border-radius: 6px; font-size: 11px; font-weight: 600; border: 1px solid transparent; }
.pl-jenis.bedah { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.pl-jenis.pemeriksaan { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.pl-pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; background: var(--gl); color: var(--td); min-width: 28px; text-align: center; }
.pl-pill.zero { background: var(--bs); color: var(--tu); }
.pl-price { font-weight: 600; color: var(--td); font-variant-numeric: tabular-nums; }
.pl-status { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 500; }
.pl-status.on { background: var(--sb); color: var(--st); }
.pl-status.off { background: var(--eb); color: var(--et); }

.pl-icon-btn { width: 28px; height: 28px; border-radius: 7px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; margin-left: 4px; }
.pl-icon-btn svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.pl-icon-btn:hover { background: var(--gl); color: var(--td); border-color: var(--ga); }
.pl-icon-danger:hover { background: var(--eb); color: var(--et); border-color: var(--ebd); }

.pl-confirm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 9100; backdrop-filter: blur(3px); padding: 1rem; }
.pl-confirm { background: var(--bc); border-radius: 16px; width: 420px; max-width: 95vw; border: 1px solid var(--gb); padding: 1.6rem 1.5rem 1.3rem; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.7rem; box-shadow: 0 20px 60px rgba(0,0,0,0.22); }
.pl-confirm-icon { width: 48px; height: 48px; border-radius: 50%; background: var(--eb); display: flex; align-items: center; justify-content: center; }
.pl-confirm-icon svg { width: 24px; height: 24px; fill: none; stroke: var(--et); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.pl-confirm h3 { font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--td); margin: 0; }
.pl-confirm p { font-size: 13px; color: var(--tm); margin: 0; line-height: 1.5; }
.pl-confirm-actions { display: flex; gap: 0.6rem; margin-top: 0.5rem; width: 100%; justify-content: center; }
.pl-btn-secondary { padding: 8px 16px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 13px; cursor: pointer; font-weight: 500; }
.pl-btn-secondary:hover { background: var(--bs); }
.pl-btn-danger { padding: 8px 18px; border-radius: 8px; border: 1px solid var(--et); background: var(--et); color: white; font-size: 13px; cursor: pointer; font-weight: 500; }
.pl-btn-danger:hover:not(:disabled) { background: #b91c1c; border-color: #b91c1c; }

.pl-toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
.pl-toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; animation: pl-toast-in 0.2s ease; }
.pl-toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.pl-toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.pl-toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }

.pl-import-result { background: var(--bc); border-radius: 16px; width: 640px; max-width: 95vw; max-height: 85vh; border: 1px solid var(--gb); padding: 1.4rem 1.4rem 1.2rem; display: flex; flex-direction: column; gap: 0.9rem; box-shadow: 0 20px 60px rgba(0,0,0,0.22); overflow: hidden; }
.pl-import-head { display: flex; align-items: center; justify-content: space-between; }
.pl-import-head h3 { font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--td); margin: 0; }
.pl-import-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.6rem; }
.pl-stat-card { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 0.7rem 0.6rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; text-align: center; }
.pl-stat-card.warn { background: var(--wb); border-color: var(--wbd); }
.pl-stat-num { font-size: 22px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.pl-stat-card.warn .pl-stat-num { color: var(--wt); }
.pl-stat-lbl { font-size: 10.5px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: 0.04em; }
.pl-import-errors { background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; padding: 0.7rem 0.9rem; overflow-y: auto; max-height: 35vh; }
.pl-import-errors-head { font-size: 12px; font-weight: 600; color: var(--et); margin-bottom: 6px; }
.pl-import-errors ul { margin: 0; padding-left: 1.2rem; }
.pl-import-errors li { font-size: 12px; color: var(--tm); line-height: 1.5; }
.pl-import-errors .pl-more { color: var(--tu); font-style: italic; list-style: none; margin-left: -1rem; }
@keyframes pl-toast-in { from { transform: translateY(-8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
</style>
