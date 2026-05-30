<script setup>
/**
 * TarifPenjaminView — generic tarif per penjamin untuk type tertentu.
 *
 * Route: /tarif-paket/tarif/:type  (type = tindakan|obat|bhp|iol)
 *
 * Fitur:
 *  - Tabel paginated: item name + insurer + classification + price + status
 *  - Filter chip klasifikasi (UMUM/BPJS/ASURANSI/PERUSAHAAN/SOSIAL)
 *  - Tambah / Edit / Hapus tarif
 *  - CSV export/import (tarif tidak punya template)
 *  - Dropdown item & penjamin diambil dari masterApi (cache 1x load)
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useTarifPaketStore } from '@/stores/tarifPaketStore'
import { masterApi } from '@/services/api'
import MasterTable from '@/components/master-data/MasterTable.vue'
import MasterFormModal from '@/components/master-data/MasterFormModal.vue'

const route = useRoute()
const store = useTarifPaketStore()

// type aktif dari URL — bisa berubah saat user pindah tab di sidebar
const type = computed(() => route.params.type || 'tindakan')
const slot = computed(() => store.tarif[type.value])

// Metadata per type
const TYPE_META = {
  tindakan: { label: 'Tindakan', fkKey: 'procedure_id',  fkLabel: 'Tindakan', api: () => masterApi.tindakan.list({ per_page: 500, active: 1 }), itemRel: 'procedure' },
  obat:     { label: 'Obat',     fkKey: 'medication_id', fkLabel: 'Obat',     api: () => masterApi.obat.list({ per_page: 500, active: 1 }),     itemRel: 'medication' },
  bhp:      { label: 'BHP',      fkKey: 'bhp_item_id',   fkLabel: 'BHP',      api: () => masterApi.bhp.list({ per_page: 500, active: 1 }),      itemRel: 'bhpItem' },
  iol:      { label: 'IOL',      fkKey: 'iol_item_id',   fkLabel: 'IOL',      api: () => masterApi.iol.list({ per_page: 500, active: 1 }),      itemRel: 'iolItem' },
}

const meta = computed(() => TYPE_META[type.value])

const classifications = ['UMUM', 'BPJS', 'ASURANSI', 'PERUSAHAAN', 'SOSIAL']

// Dropdown items per type (cache di local)
const items = ref([])      // → procedures / medications / bhp_items / iol_items
const insurers = ref([])

const filterClassification = ref('')
const filterInsurer = ref('')

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

function showToast(t, msg) {
  toast.value = { type: t, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

function emptyForm() {
  return {
    [meta.value?.fkKey ?? 'procedure_id']: '',
    insurer_id: '',
    classification: 'UMUM',
    price: 0,
    is_active: true,
  }
}

// ─── Table ───────────────────────────────────────────────────────────────
const columns = computed(() => [
  { key: 'item_name',      label: meta.value.label },
  { key: 'insurer_name',   label: 'Penjamin',   width: '170px' },
  { key: 'classification', label: 'Klasifikasi', width: '120px' },
  { key: 'price',          label: 'Tarif',       width: '140px', align: 'right' },
  { key: 'is_active',      label: 'Status',      width: '100px', align: 'center' },
])

function getItemName(row) {
  const rel = row[meta.value.itemRel]
  if (!rel) return '—'
  return rel.name ?? rel.brand ?? '—'
}
function getItemCode(row) {
  const rel = row[meta.value.itemRel]
  return rel?.code ?? rel?.serial_number ?? ''
}

const rows = computed(() => {
  return (slot.value?.items ?? []).map((r) => ({
    ...r,
    item_name:    getItemName(r),
    item_code:    getItemCode(r),
    insurer_name: r.insurer?.name ?? 'SEMUA',
  }))
})

const formatRp = (v) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID')

async function refresh() {
  const params = {}
  if (filterClassification.value) params.classification = filterClassification.value
  if (filterInsurer.value) params.insurer_id = filterInsurer.value
  await store.fetchTarif(type.value, { page: 1, ...params })
}

function onPageChange(p) {
  store.fetchTarif(type.value, { page: p, classification: filterClassification.value || undefined, insurer_id: filterInsurer.value || undefined })
}

async function loadDropdowns() {
  try {
    const [itRes, insRes] = await Promise.all([
      meta.value.api(),
      masterApi.penjamin(),
    ])
    items.value = itRes.data?.data?.data ?? itRes.data?.data ?? []
    insurers.value = insRes.data?.data?.data ?? insRes.data?.data ?? []
  } catch (e) {
    showToast('e', 'Gagal memuat dropdown')
  }
}

// ─── CRUD ─────────────────────────────────────────────────────────────────
function openCreate() {
  modal.value = {
    open: true, mode: 'create',
    payload: emptyForm(),
    errors: null, submitting: false, editingId: null,
  }
}

function openEdit(row) {
  modal.value = {
    open: true, mode: 'edit',
    payload: {
      [meta.value.fkKey]: row[meta.value.fkKey],
      insurer_id:        row.insurer_id ?? '',
      classification:    row.classification,
      price:             row.price,
      is_active:         row.is_active,
    },
    errors: null, submitting: false, editingId: row.id,
  }
}

async function onSubmit(payload) {
  modal.value.submitting = true
  modal.value.errors = null
  try {
    if (modal.value.mode === 'create') {
      const data = { ...payload, insurer_id: payload.insurer_id || null }
      await store.createTarif(type.value, data)
      showToast('s', 'Tarif disimpan')
    } else {
      await store.updateTarif(type.value, modal.value.editingId, {
        price: payload.price,
        is_active: payload.is_active,
      })
      showToast('s', 'Tarif diperbarui')
    }
    modal.value.open = false
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
    await store.removeTarif(type.value, confirmDelete.value.row.id)
    showToast('s', 'Tarif dihapus')
    confirmDelete.value.open = false
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus')
  } finally {
    confirmDelete.value.busy = false
  }
}

// ─── CSV ──────────────────────────────────────────────────────────────────
const importFileInput = ref(null)

async function doExport() {
  try {
    await store.exportTarifCsv(type.value)
    showToast('s', 'Export CSV berhasil diunduh')
  } catch (e) {
    showToast('e', errOf(e, 'Gagal export CSV'))
  }
}

function pickImportFile() { importFileInput.value?.click() }

async function onImportFileSelected(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  try {
    const r = await store.importTarifCsv(type.value, file)
    showToast('s', `Import: ${r.imported ?? 0} baris, ${r.skipped ?? 0} dilewati`)
  } catch (err) {
    showToast('e', errOf(err, 'Gagal import CSV'))
  }
}

function errOf(e, fallback) {
  return e.response?.data?.message ?? e.message ?? fallback
}

// ─── Modal fields ─────────────────────────────────────────────────────────
const modalFields = computed(() => {
  const itemOpts = items.value.map((it) => {
    const labelMain = it.name ?? `${it.brand ?? ''} ${it.model ?? ''}`.trim()
    const labelSub  = it.code ?? it.serial_number ?? ''
    return { value: it.id, label: `${labelSub ? labelSub + ' · ' : ''}${labelMain}` }
  })
  const insurerOpts = [
    { value: '', label: 'SEMUA (default)' },
    ...insurers.value.map((i) => ({ value: i.id, label: `${i.name}${i.code ? ' [' + i.code + ']' : ''}` })),
  ]
  const classOpts = classifications.map((c) => ({ value: c, label: c }))

  if (modal.value.mode === 'create') {
    return [
      { key: meta.value.fkKey, label: meta.value.fkLabel,    type: 'select',   options: itemOpts,    required: true, cols: 2 },
      { key: 'insurer_id',     label: 'Penjamin',            type: 'select',   options: insurerOpts, required: false, cols: 1, hint: 'Kosongkan = berlaku untuk semua penjamin' },
      { key: 'classification', label: 'Klasifikasi',         type: 'select',   options: classOpts,   required: true, cols: 1 },
      { key: 'price',          label: 'Tarif (Rp)',          type: 'number',   required: true, min: 0, cols: 1 },
      { key: 'is_active',      label: 'Aktif',               type: 'checkbox', cols: 1 },
    ]
  }
  // Edit — FK & klasifikasi immutable (unique constraint)
  return [
    { key: meta.value.fkKey, label: meta.value.fkLabel, type: 'select', options: itemOpts,    disabled: true, cols: 2 },
    { key: 'insurer_id',     label: 'Penjamin',         type: 'select', options: insurerOpts, disabled: true, cols: 1 },
    { key: 'classification', label: 'Klasifikasi',      type: 'select', options: classOpts,   disabled: true, cols: 1 },
    { key: 'price',          label: 'Tarif (Rp)',       type: 'number', required: true, min: 0, cols: 1 },
    { key: 'is_active',      label: 'Aktif',            type: 'checkbox', cols: 1 },
  ]
})

// ─── Lifecycle: re-load saat ganti type ──────────────────────────────────
watch(type, async () => {
  filterClassification.value = ''
  filterInsurer.value = ''
  await Promise.all([refresh(), loadDropdowns()])
}, { immediate: false })

onMounted(async () => {
  await Promise.all([refresh(), loadDropdowns()])
})
</script>

<template>
  <div class="tpv-wrap">
    <div class="tpv-head">
      <div>
        <h2>Tarif {{ meta.label }} per Penjamin</h2>
        <p>Daftar tarif per item, penjamin, dan klasifikasi pasien.</p>
      </div>
      <div class="tpv-actions">
        <button class="tpv-btn-ghost" @click="doExport" title="Export CSV">
          <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Export
        </button>
        <button class="tpv-btn-ghost" @click="pickImportFile" title="Import CSV">
          <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Import
        </button>
        <input ref="importFileInput" type="file" accept=".csv,text/csv" style="display:none" @change="onImportFileSelected" />
        <button class="tpv-btn-primary" @click="openCreate">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Tarif
        </button>
      </div>
    </div>

    <!-- Filter chips -->
    <div class="tpv-filters">
      <span class="tpv-filter-label">Klasifikasi:</span>
      <button class="tpv-chip" :class="{ active: !filterClassification }" @click="filterClassification = ''; refresh()">Semua</button>
      <button v-for="c in classifications" :key="c" class="tpv-chip" :class="{ active: filterClassification === c }" @click="filterClassification = c; refresh()">{{ c }}</button>

      <span class="tpv-filter-label" style="margin-left:.8rem">Penjamin:</span>
      <select class="tpv-filter-select" v-model="filterInsurer" @change="refresh">
        <option value="">Semua</option>
        <option v-for="ins in insurers" :key="ins.id" :value="ins.id">{{ ins.name }}</option>
      </select>
    </div>

    <MasterTable
      :columns="columns"
      :rows="rows"
      :loading="slot.loading"
      :error="slot.error"
      :meta="slot.meta"
      :show-search="false"
      :empty-text="`Belum ada tarif ${meta.label.toLowerCase()}. Klik “Tambah Tarif” atau import CSV.`"
      @page-change="onPageChange"
      @refresh="refresh"
    >
      <template #cell-item_name="{ row }">
        <div class="tpv-cell-name">
          <strong>{{ row.item_name }}</strong>
          <span class="tpv-cell-sub">{{ row.item_code || '—' }}</span>
        </div>
      </template>

      <template #cell-insurer_name="{ row }">
        <span class="tpv-insurer-pill" :class="{ all: !row.insurer_id }">{{ row.insurer_name }}</span>
      </template>

      <template #cell-classification="{ value }">
        <span class="tpv-class-pill" :data-c="value">{{ value }}</span>
      </template>

      <template #cell-price="{ value }">
        <span class="tpv-price">{{ formatRp(value) }}</span>
      </template>

      <template #cell-is_active="{ value }">
        <span class="tpv-status" :class="value ? 'on' : 'off'">{{ value ? 'Aktif' : 'Nonaktif' }}</span>
      </template>

      <template #actions="{ row }">
        <button class="tpv-icon-btn" title="Edit" @click="openEdit(row)">
          <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
        </button>
        <button class="tpv-icon-btn tpv-icon-danger" title="Hapus" @click="askDelete(row)">
          <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </button>
      </template>
    </MasterTable>

    <MasterFormModal
      v-model:open="modal.open"
      v-model="modal.payload"
      :title="modal.mode === 'create' ? `Tambah Tarif ${meta.label}` : `Edit Tarif`"
      :fields="modalFields"
      :submitting="modal.submitting"
      :errors="modal.errors"
      :submit-label="modal.mode === 'create' ? 'Simpan Tarif' : 'Simpan Perubahan'"
      width="560px"
      @submit="onSubmit"
    />

    <Teleport to="body">
      <div v-if="confirmDelete.open" class="tpv-confirm-overlay" @click.self="confirmDelete.open = false">
        <div class="tpv-confirm">
          <div class="tpv-confirm-icon">
            <svg viewBox="0 0 24 24"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
          </div>
          <h3>Hapus tarif?</h3>
          <p>
            <strong>{{ confirmDelete.row?.item_name }}</strong> · {{ confirmDelete.row?.insurer_name }} · {{ confirmDelete.row?.classification }} akan dihapus permanen.
          </p>
          <div class="tpv-confirm-actions">
            <button class="tpv-btn-secondary" :disabled="confirmDelete.busy" @click="confirmDelete.open = false">Batal</button>
            <button class="tpv-btn-danger" :disabled="confirmDelete.busy" @click="doDelete">{{ confirmDelete.busy ? 'Menghapus…' : 'Hapus' }}</button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="toast" class="tpv-toast-wrap">
        <div class="tpv-toast" :class="`tpv-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.tpv-wrap { display: flex; flex-direction: column; gap: 1rem; }
.tpv-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.tpv-head h2 { font-family: 'Space Grotesk', serif; font-size: 20px; color: var(--td); margin: 0; }
.tpv-head p { font-size: 13px; color: var(--tm); margin: 4px 0 0; }
.tpv-actions { display: flex; gap: .5rem; align-items: center; }

.tpv-btn-primary { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; border: 1px solid var(--ga); background: var(--ga); color: white; font-size: 13px; font-weight: 500; cursor: pointer; }
.tpv-btn-primary:hover { background: var(--gm); border-color: var(--gm); }
.tpv-btn-primary svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }

.tpv-btn-ghost { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 9px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 12.5px; font-weight: 500; cursor: pointer; }
.tpv-btn-ghost:hover { background: var(--bs); color: var(--td); border-color: var(--ga); }
.tpv-btn-ghost svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

.tpv-filters { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; padding: 0.55rem 0.8rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; }
.tpv-filter-label { font-size: 12px; color: var(--tm); font-weight: 500; margin-right: 4px; }
.tpv-chip { padding: 5px 12px; border-radius: 999px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 11.5px; cursor: pointer; font-weight: 500; transition: background 0.15s, border-color 0.15s, color 0.15s; }
.tpv-chip:hover { background: var(--gl); border-color: var(--ga); color: var(--td); }
.tpv-chip.active { background: var(--ga); border-color: var(--ga); color: white; }
.tpv-filter-select { padding: 5px 9px; border: 1px solid var(--gb); border-radius: 8px; background: var(--bc); color: var(--td); font-size: 12px; cursor: pointer; }
.tpv-filter-select:focus { outline: none; border-color: var(--ga); }

.tpv-cell-name { display: flex; flex-direction: column; gap: 1px; }
.tpv-cell-name strong { font-weight: 500; color: var(--td); }
.tpv-cell-sub { font-size: 11px; color: var(--tu); font-family: 'Geist Mono', monospace; }

.tpv-insurer-pill { display: inline-block; padding: 3px 9px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.tpv-insurer-pill.all { background: var(--bs); color: var(--tm); border-color: var(--gb); }

.tpv-class-pill { display: inline-block; padding: 3px 9px; border-radius: 6px; font-size: 11px; font-weight: 600; letter-spacing: 0.03em; }
.tpv-class-pill[data-c="UMUM"] { background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.tpv-class-pill[data-c="BPJS"] { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.tpv-class-pill[data-c="ASURANSI"] { background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.tpv-class-pill[data-c="PERUSAHAAN"] { background: var(--pb); color: var(--pt); border: 1px solid var(--pbd); }
.tpv-class-pill[data-c="SOSIAL"] { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

.tpv-price { font-weight: 600; color: var(--td); font-variant-numeric: tabular-nums; }
.tpv-status { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 500; }
.tpv-status.on { background: var(--sb); color: var(--st); }
.tpv-status.off { background: var(--eb); color: var(--et); }

.tpv-icon-btn { width: 28px; height: 28px; border-radius: 7px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; margin-left: 4px; transition: background 0.15s, color 0.15s, border-color 0.15s; }
.tpv-icon-btn svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.tpv-icon-btn:hover { background: var(--gl); color: var(--td); border-color: var(--ga); }
.tpv-icon-danger:hover { background: var(--eb); color: var(--et); border-color: var(--ebd); }

.tpv-confirm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 9100; backdrop-filter: blur(3px); padding: 1rem; }
.tpv-confirm { background: var(--bc); border-radius: 16px; width: 420px; max-width: 95vw; border: 1px solid var(--gb); padding: 1.6rem 1.5rem 1.3rem; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.7rem; box-shadow: 0 20px 60px rgba(0,0,0,0.22); }
.tpv-confirm-icon { width: 48px; height: 48px; border-radius: 50%; background: var(--eb); display: flex; align-items: center; justify-content: center; }
.tpv-confirm-icon svg { width: 24px; height: 24px; fill: none; stroke: var(--et); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.tpv-confirm h3 { font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--td); margin: 0; }
.tpv-confirm p { font-size: 13px; color: var(--tm); margin: 0; line-height: 1.5; }
.tpv-confirm-actions { display: flex; gap: 0.6rem; margin-top: 0.5rem; width: 100%; justify-content: center; }
.tpv-btn-secondary { padding: 8px 16px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 13px; cursor: pointer; font-weight: 500; }
.tpv-btn-secondary:hover { background: var(--bs); }
.tpv-btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }
.tpv-btn-danger { padding: 8px 18px; border-radius: 8px; border: 1px solid var(--et); background: var(--et); color: white; font-size: 13px; cursor: pointer; font-weight: 500; }
.tpv-btn-danger:hover:not(:disabled) { background: #b91c1c; border-color: #b91c1c; }
.tpv-btn-danger:disabled { opacity: 0.6; cursor: not-allowed; }

.tpv-toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
.tpv-toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; animation: tpv-toast-in 0.2s ease; }
.tpv-toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.tpv-toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
@keyframes tpv-toast-in { from { transform: translateY(-8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
</style>
