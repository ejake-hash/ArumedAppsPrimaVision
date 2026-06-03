<script setup>
/**
 * MetodeBayarView — list master penjamin (insurers).
 *
 * Fitur:
 * - Tabel: No, Nama, Tipe, TPA, Telepon, Status, Aksi
 * - 3 baris sistem (UMUM/BPJS/SOSIAL) ditandai badge "Sistem", tidak boleh hapus
 * - Anggota TPA (punya parent_id) DISEMBUNYIKAN dari daftar (only_tpa_view) — tarifnya
 *   identik dgn TPA induk. Anggota dikelola dari halaman detail TPA induk.
 * - TPA induk (punya anggota) tampak badge `Induk TPA · n anggota`
 * - Tombol "Kelola Tarif" navigate ke detail page
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { masterApi } from '@/services/api'
import MasterTable from '@/components/master-data/MasterTable.vue'
import MasterFormModal from '@/components/master-data/MasterFormModal.vue'

const router = useRouter()

const TYPES = [
  { value: 'UMUM',       label: 'Umum' },
  { value: 'BPJS',       label: 'BPJS' },
  { value: 'ASURANSI',   label: 'Asuransi' },
  { value: 'PERUSAHAAN', label: 'Perusahaan' },
  { value: 'SOSIAL',     label: 'Sosial' },
]

const items = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 })
const loading = ref(false)
const error = ref(null)

const filterType = ref('')
const searchValue = ref('')
let searchDebounce = null

const modal = ref({ open: false, mode: 'create', payload: emptyForm(), errors: null, submitting: false, editingId: null })
const confirmDelete = ref({ open: false, row: null, busy: false })
const toast = ref(null)

function emptyForm() {
  return { name: '', code: '', type: 'ASURANSI', phone: '', email: '', address: '', is_active: true, is_tpa: false }
}

function showToast(type, msg) {
  toast.value = { type, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

// ─── CSV / Excel: Template / Import / Export ────────────────────────────────
const csvMenu = ref(null)          // 'template' | 'export' | null
const csvFileInput = ref(null)
const csvBusy = ref(false)

function toggleCsvMenu(name) { csvMenu.value = csvMenu.value === name ? null : name }
function closeCsvMenu(e) { if (!e.target.closest?.('.mb-split')) csvMenu.value = null }

function triggerDownload(blob, filename) {
  const url = URL.createObjectURL(blob instanceof Blob ? blob : new Blob([blob]))
  const a = document.createElement('a')
  a.href = url; a.download = filename
  document.body.appendChild(a); a.click(); document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

async function onTemplate(format = 'csv') {
  csvMenu.value = null
  try {
    const res = await masterApi.penjamin.csvTemplate(format === 'xlsx' ? 'xlsx' : undefined)
    triggerDownload(res.data, `template-penjamin.${format}`)
  } catch (e) { showToast('e', 'Gagal unduh template') }
}

async function onExport(format = 'csv') {
  csvMenu.value = null
  try {
    const res = await masterApi.penjamin.csvExport(format === 'xlsx' ? 'xlsx' : undefined)
    const stamp = new Date().toISOString().slice(0, 10).replace(/-/g, '')
    triggerDownload(res.data, `penjamin-${stamp}.${format}`)
  } catch (e) { showToast('e', 'Gagal export') }
}

function pickImport() { csvFileInput.value?.click() }

async function onImport(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  csvBusy.value = true
  try {
    const res = await masterApi.penjamin.csvImport(file)
    const r = res.data?.data ?? {}
    const msg = `Import: ${r.inserted ?? 0} baru, ${r.updated ?? 0} update, ${r.skipped ?? 0} dilewati`
    showToast((r.errors?.length || (r.skipped ?? 0) > 0) ? 'w' : 's', msg)
    await refresh(meta.value.current_page)
  } catch (err) {
    showToast('e', err.response?.data?.message ?? 'Gagal import')
  } finally {
    csvBusy.value = false
  }
}

// ─── Table ────────────────────────────────────────────────────────────────
const columns = [
  { key: '_no',       label: 'No',     width: '50px',  align: 'center' },
  { key: 'name',      label: 'Nama Penjamin' },
  { key: 'type',      label: 'Tipe',   width: '120px' },
  { key: 'tpa',       label: 'TPA',    width: '160px' },
  { key: 'phone',     label: 'Telepon', width: '130px' },
  { key: 'is_active', label: 'Status', width: '95px', align: 'center' },
]

const rows = computed(() => {
  const startIdx = ((meta.value.current_page ?? 1) - 1) * (meta.value.per_page ?? 25)
  const filtered = searchValue.value
    ? items.value.filter((r) => {
        const q = searchValue.value.toLowerCase()
        return (r.name ?? '').toLowerCase().includes(q)
          || (r.phone ?? '').toLowerCase().includes(q)
      })
    : items.value
  return filtered.map((r, i) => ({
    ...r,
    _no: startIdx + i + 1,
    _childrenCount: r.children_count ?? 0,
    _isTpa: !!r.is_tpa,
  }))
})

const typeLabel = (v) => TYPES.find((t) => t.value === v)?.label ?? v

// ─── Fetch ────────────────────────────────────────────────────────────────
async function refresh(page = 1) {
  loading.value = true
  error.value = null
  try {
    const params = { page, per_page: meta.value.per_page, only_tpa_view: 1 }
    if (filterType.value) params.type = filterType.value
    const res = await masterApi.penjamin.list(params)
    const payload = res.data?.data
    if (payload && Array.isArray(payload.data)) {
      items.value = payload.data
      meta.value = {
        current_page: payload.current_page ?? 1,
        last_page:    payload.last_page ?? 1,
        total:        payload.total ?? payload.data.length,
        per_page:     payload.per_page ?? 25,
      }
    } else if (Array.isArray(payload)) {
      items.value = payload
      meta.value = { current_page: 1, last_page: 1, total: payload.length, per_page: payload.length }
    } else {
      items.value = []
    }
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal memuat metode bayar'
    showToast('e', error.value)
  } finally {
    loading.value = false
  }
}

function onSearchUpdate(v) {
  searchValue.value = v
  if (searchDebounce) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => { /* purely frontend */ }, 100)
}

function onPageChange(p) { refresh(p) }

function goDetail(row) {
  router.push({ name: 'metode-bayar-detail', params: { id: row.id } })
}

// ─── CRUD ─────────────────────────────────────────────────────────────────
function openCreate() {
  modal.value = { open: true, mode: 'create', payload: emptyForm(), errors: null, submitting: false, editingId: null }
}

function openEdit(row) {
  modal.value = {
    open: true,
    mode: 'edit',
    payload: {
      name:      row.name ?? '',
      code:      row.code ?? '',
      type:      row.type ?? 'ASURANSI',
      phone:     row.phone ?? '',
      email:     row.email ?? '',
      address:   row.address ?? '',
      is_active: row.is_active ?? true,
      is_tpa:    !!row.is_tpa,
      _is_system: !!row.is_system,
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
    const data = { ...payload }
    delete data._is_system
    if (modal.value.mode === 'create') {
      await masterApi.penjamin.create(data)
      showToast('s', 'Penjamin dibuat')
    } else {
      await masterApi.penjamin.update(modal.value.editingId, data)
      showToast('s', 'Penjamin diperbarui')
    }
    modal.value.open = false
    await refresh(meta.value.current_page)
  } catch (e) {
    if (e.response?.status === 422) modal.value.errors = e.response.data?.errors ?? null
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan')
  } finally {
    modal.value.submitting = false
  }
}

function askDelete(row) {
  if (row.is_system) { showToast('w', 'Insurer sistem tidak bisa dihapus'); return }
  confirmDelete.value = { open: true, row, busy: false }
}

async function doDelete() {
  confirmDelete.value.busy = true
  try {
    await masterApi.penjamin.remove(confirmDelete.value.row.id)
    showToast('s', 'Penjamin dihapus')
    confirmDelete.value.open = false
    await refresh(meta.value.current_page)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus')
  } finally {
    confirmDelete.value.busy = false
  }
}

// ─── Modal fields ──────────────────────────────────────────────────────────
const modalFields = computed(() => {
  const isSystem = modal.value.payload._is_system

  // Keanggotaan TPA TIDAK diatur di sini — dikelola dari halaman detail TPA induk
  // (panel "Anggota TPA"). Lihat MetodeBayarDetailView.
  const fields = [
    { key: 'name',      label: 'Nama Penjamin', type: 'text',     required: true, cols: 2, placeholder: 'cth. Mandiri Inhealth', disabled: isSystem },
    { key: 'type',      label: 'Tipe',          type: 'select',   required: true, cols: 2, options: TYPES, disabled: isSystem },
    { key: 'phone',     label: 'Telepon',       type: 'text',     cols: 1, placeholder: '021-xxx' },
    { key: 'email',     label: 'Email',         type: 'text',     cols: 1, placeholder: 'info@…' },
    { key: 'is_active', label: 'Aktif',         type: 'checkbox', cols: 2 },
    { key: 'is_tpa',    label: 'Tandai sebagai TPA induk', type: 'checkbox', cols: 2,
      hint: 'TPA induk bisa punya anggota (asuransi/perusahaan) yang mengikuti tarifnya. Kelola anggota dari halaman detail.',
      showIf: (f) => !f._is_system },
    { key: 'address',   label: 'Alamat',        type: 'textarea', cols: 2, rows: 2 },
  ]
  return fields
})

onMounted(() => {
  document.addEventListener('click', closeCsvMenu)
  refresh()
})
onUnmounted(() => document.removeEventListener('click', closeCsvMenu))
</script>

<template>
  <div class="mb-wrap">
    <div class="mb-section-head">
      <div>
        <h2>Metode Bayar</h2>
        <p>Master penjamin pembayaran. UMUM/BPJS/SOSIAL adalah baris sistem (tidak bisa dihapus). Anggota TPA tidak ditampilkan di sini — kelola dari halaman TPA induk.</p>
      </div>
      <div class="mb-header-actions">
        <!-- CSV / Excel: Template / Import / Export -->
        <div class="mb-split">
          <button class="mb-btn-csv" @click.stop="toggleCsvMenu('template')" title="Unduh template (header + petunjuk)">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Template
            <svg class="mb-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div v-if="csvMenu === 'template'" class="mb-menu">
            <button @click="onTemplate('csv')">CSV (.csv)</button>
            <button @click="onTemplate('xlsx')">Excel (.xlsx)</button>
          </div>
        </div>
        <button class="mb-btn-csv" :disabled="csvBusy" @click="pickImport" title="Import penjamin dari CSV/Excel">
          <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          {{ csvBusy ? 'Mengimport…' : 'Import' }}
        </button>
        <div class="mb-split">
          <button class="mb-btn-csv" @click.stop="toggleCsvMenu('export')" title="Export semua penjamin">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export
            <svg class="mb-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div v-if="csvMenu === 'export'" class="mb-menu">
            <button @click="onExport('csv')">CSV (.csv)</button>
            <button @click="onExport('xlsx')">Excel (.xlsx)</button>
          </div>
        </div>
        <input ref="csvFileInput" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" style="display:none" @change="onImport" />

        <button class="mb-btn-primary" @click="openCreate">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Penjamin
        </button>
      </div>
    </div>

    <div class="mb-filters">
      <span class="mb-filter-label">Filter tipe:</span>
      <button class="mb-chip" :class="{ active: !filterType }" @click="filterType = ''; refresh()">Semua</button>
      <button
        v-for="t in TYPES" :key="t.value"
        class="mb-chip" :class="{ active: filterType === t.value }"
        @click="filterType = t.value; refresh()"
      >{{ t.label }}</button>
    </div>

    <MasterTable
      :columns="columns" :rows="rows"
      :loading="loading" :error="error" :meta="meta"
      :search-value="searchValue"
      search-placeholder="Cari nama / telepon…"
      empty-text="Belum ada penjamin. Klik “Tambah Penjamin”."
      @update:search="onSearchUpdate"
      @page-change="onPageChange"
      @refresh="() => refresh(meta.current_page)"
    >
      <template #cell-name="{ row }">
        <a class="mb-name-link" @click="goDetail(row)" role="button">{{ row.name }}</a>
        <span v-if="row.is_system" class="mb-badge-system" title="Insurer sistem (immutable)">SISTEM</span>
      </template>

      <template #cell-type="{ value }">
        <span class="mb-type-pill" :data-t="value">{{ typeLabel(value) }}</span>
      </template>

      <template #cell-tpa="{ row }">
        <div class="mb-parent-cell">
          <span v-if="row._childrenCount > 0" class="mb-children-tag" :title="`Induk TPA dengan ${row._childrenCount} anggota`">
            Induk TPA · {{ row._childrenCount }} anggota
          </span>
          <span v-else-if="row._isTpa" class="mb-tpa-empty-tag" title="Ditandai sebagai TPA induk, belum ada anggota">
            TPA · belum ada anggota
          </span>
          <span v-else class="mb-dim">—</span>
        </div>
      </template>

      <template #cell-phone="{ value }">
        <span class="mb-mono">{{ value || '—' }}</span>
      </template>

      <template #cell-is_active="{ value }">
        <span class="mb-status" :class="value ? 'on' : 'off'">{{ value ? 'Aktif' : 'Nonaktif' }}</span>
      </template>

      <template #actions="{ row }">
        <button class="mb-icon-btn" title="Kelola Tarif" @click="goDetail(row)">
          <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </button>
        <button class="mb-icon-btn" title="Edit" @click="openEdit(row)">
          <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
        </button>
        <button v-if="!row.is_system" class="mb-icon-btn mb-icon-danger" title="Hapus" @click="askDelete(row)">
          <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </button>
      </template>
    </MasterTable>

    <MasterFormModal
      v-model:open="modal.open"
      v-model="modal.payload"
      :title="modal.mode === 'create' ? 'Tambah Penjamin' : 'Edit Penjamin'"
      :fields="modalFields"
      :submitting="modal.submitting"
      :errors="modal.errors"
      :submit-label="modal.mode === 'create' ? 'Simpan' : 'Simpan Perubahan'"
      width="640px"
      @submit="onSubmit"
    />

    <Teleport to="body">
      <div v-if="confirmDelete.open" class="mb-confirm-overlay" @click.self="confirmDelete.open = false">
        <div class="mb-confirm">
          <div class="mb-confirm-icon">
            <svg viewBox="0 0 24 24"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          </div>
          <h3>Hapus penjamin?</h3>
          <p><strong>{{ confirmDelete.row?.name }}</strong> akan dihapus. Tarif dan child (jika ada) harus dikelola dulu.</p>
          <div class="mb-confirm-actions">
            <button class="mb-btn-secondary" :disabled="confirmDelete.busy" @click="confirmDelete.open = false">Batal</button>
            <button class="mb-btn-danger" :disabled="confirmDelete.busy" @click="doDelete">
              {{ confirmDelete.busy ? 'Menghapus…' : 'Hapus' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="toast" class="mb-toast-wrap">
        <div class="mb-toast" :class="`mb-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.mb-wrap { display: flex; flex-direction: column; gap: 1rem; }

.mb-section-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; }
.mb-section-head h2 { font-family: 'Space Grotesk', serif; font-size: 20px; color: var(--td); margin: 0; }
.mb-section-head p { font-size: 13px; color: var(--tm); margin: 4px 0 0; max-width: 700px; }

.mb-btn-primary { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; border: 1px solid var(--ga); background: var(--ga); color: white; font-size: 13px; font-weight: 500; cursor: pointer; transition: background 0.15s; }
.mb-btn-primary:hover { background: var(--gm); border-color: var(--gm); }
.mb-btn-primary svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }

/* ─── CSV / Excel toolbar ─── */
.mb-header-actions { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.mb-split { position: relative; }
.mb-caret { width: 11px !important; height: 11px !important; margin-left: 1px; }
.mb-btn-csv { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 9px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 12.5px; font-weight: 500; cursor: pointer; transition: background 0.15s, color 0.15s, border-color 0.15s; }
.mb-btn-csv svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.mb-btn-csv:hover:not(:disabled) { background: var(--bs); color: var(--td); border-color: var(--ga); }
.mb-btn-csv:disabled { opacity: 0.45; cursor: not-allowed; }
.mb-menu { position: absolute; top: calc(100% + 4px); left: 0; z-index: 50; background: var(--bc); border: 1px solid var(--gb); border-radius: 9px; box-shadow: 0 8px 24px rgba(0,0,0,0.14); padding: 4px; min-width: 140px; display: flex; flex-direction: column; gap: 2px; }
.mb-menu button { text-align: left; padding: 7px 10px; border: none; background: transparent; border-radius: 6px; font-size: 12px; color: var(--td); cursor: pointer; }
.mb-menu button:hover { background: var(--gl); color: var(--ga); }

.mb-filters { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; padding: 0.6rem 0.8rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; }
.mb-filter-label { font-size: 12px; color: var(--tm); font-weight: 500; margin-right: 4px; }
.mb-chip { padding: 5px 12px; border-radius: 999px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 11.5px; cursor: pointer; font-weight: 500; transition: background 0.15s, border-color 0.15s, color 0.15s; }
.mb-chip:hover { background: var(--gl); border-color: var(--ga); color: var(--td); }
.mb-chip.active { background: var(--ga); border-color: var(--ga); color: white; }

.mb-badge-system { font-size: 9.5px; font-weight: 700; letter-spacing: 0.05em; padding: 2px 6px; border-radius: 4px; background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); margin-left: 6px; }

.mb-name-link { font-weight: 500; color: var(--td); cursor: pointer; text-decoration: none; }
.mb-name-link:hover { text-decoration: underline; color: var(--ga); }
.mb-mono { font-family: 'Geist Mono', monospace; font-size: 12px; color: var(--tm); }

.mb-type-pill { display: inline-block; padding: 3px 9px; border-radius: 6px; font-size: 11px; font-weight: 600; letter-spacing: 0.03em; }
.mb-type-pill[data-t="UMUM"]       { background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.mb-type-pill[data-t="BPJS"]       { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.mb-type-pill[data-t="ASURANSI"]   { background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.mb-type-pill[data-t="PERUSAHAAN"] { background: var(--pb); color: var(--pt); border: 1px solid var(--pbd); }
.mb-type-pill[data-t="SOSIAL"]     { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

.mb-parent-cell { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.mb-parent-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; background: var(--pb); color: var(--pt); border: 1px solid var(--pbd); font-weight: 500; }
.mb-children-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); font-weight: 500; }
.mb-tpa-empty-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; background: var(--bs); color: var(--tm); border: 1px dashed var(--gb); font-weight: 500; }
.mb-dim { color: var(--tu); font-size: 12px; }

.mb-status { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 500; }
.mb-status.on { background: var(--sb); color: var(--st); }
.mb-status.off { background: var(--eb); color: var(--et); }

.mb-icon-btn { width: 28px; height: 28px; border-radius: 7px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; margin-left: 4px; transition: background 0.15s, color 0.15s, border-color 0.15s; }
.mb-icon-btn svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.mb-icon-btn:hover { background: var(--gl); color: var(--td); border-color: var(--ga); }
.mb-icon-danger:hover { background: var(--eb); color: var(--et); border-color: var(--ebd); }

.mb-confirm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 9100; backdrop-filter: blur(3px); padding: 1rem; }
.mb-confirm { background: var(--bc); border-radius: 16px; width: 420px; max-width: 95vw; border: 1px solid var(--gb); padding: 1.6rem 1.5rem 1.3rem; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.7rem; box-shadow: 0 20px 60px rgba(0,0,0,0.22); }
.mb-confirm-icon { width: 48px; height: 48px; border-radius: 50%; background: var(--eb); display: flex; align-items: center; justify-content: center; }
.mb-confirm-icon svg { width: 24px; height: 24px; fill: none; stroke: var(--et); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.mb-confirm h3 { font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--td); margin: 0; }
.mb-confirm p { font-size: 13px; color: var(--tm); margin: 0; line-height: 1.5; }
.mb-confirm-actions { display: flex; gap: 0.6rem; margin-top: 0.5rem; width: 100%; justify-content: center; }
.mb-btn-secondary { padding: 8px 16px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 13px; cursor: pointer; font-weight: 500; }
.mb-btn-secondary:hover { background: var(--bs); }
.mb-btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }
.mb-btn-danger { padding: 8px 18px; border-radius: 8px; border: 1px solid var(--et); background: var(--et); color: white; font-size: 13px; cursor: pointer; font-weight: 500; }
.mb-btn-danger:hover:not(:disabled) { background: #b91c1c; border-color: #b91c1c; }
.mb-btn-danger:disabled { opacity: 0.6; cursor: not-allowed; }

.mb-toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
.mb-toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; animation: mb-toast-in 0.2s ease; }
.mb-toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.mb-toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.mb-toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.mb-toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
@keyframes mb-toast-in { from { transform: translateY(-8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
</style>
