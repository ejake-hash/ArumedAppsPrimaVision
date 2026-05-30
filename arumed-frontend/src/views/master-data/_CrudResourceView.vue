<script setup>
/**
 * _CrudResourceView — generic CRUD scaffold untuk master data sederhana.
 *
 * Dipakai 5 view tipis (Obat, BHP, IOL, ICD-10, ICD-9). Setiap view tipis
 * tinggal pass `config` lewat prop dan kelola transformasi field-spesifik
 * lewat slot kalau perlu.
 *
 * Config shape:
 *   {
 *     resourceKey: string,            — key di masterDataStore.REGISTRY
 *     title:       string,
 *     description: string,
 *     columns:     Array<{key,label,formatter?,width?,align?}>,
 *     fields:      Array<FieldConfig>  — utk MasterFormModal (create)
 *     editFields:  Array<FieldConfig> | null — utk edit (null = sama dgn create, kecuali key 'code' di-disable kalau ada)
 *     defaults:    object              — initial form values
 *     searchPlaceholder: string,
 *     csvShowTemplate: boolean (default true),
 *     extraSearchParam: string | null  — kalau backend punya param search, mis 'search'
 *     deleteLabel: (row) => string     — apa yg ditampilkan di modal konfirm hapus
 *   }
 */
import { ref, onMounted, computed } from 'vue'
import { useMasterDataStore } from '@/stores/masterDataStore'
import MasterTable from '@/components/master-data/MasterTable.vue'
import MasterFormModal from '@/components/master-data/MasterFormModal.vue'
import CsvActionBar from '@/components/master-data/CsvActionBar.vue'

const props = defineProps({
  config: { type: Object, required: true },
})

const emit = defineEmits(['field-action'])

const store = useMasterDataStore()
const KEY = computed(() => props.config.resourceKey)

const modal = ref({
  open: false,
  mode: 'create',
  payload: { ...props.config.defaults },
  errors: null,
  submitting: false,
  editingId: null,
})

const confirmDelete = ref({ open: false, row: null, busy: false })
const toast = ref(null)

function showToast(type, msg) {
  toast.value = { type, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

const searchValue = ref('')
const extraFilters = ref({})  // diisi parent lewat slot #filters via updateFilter()

function buildParams() {
  const params = { ...extraFilters.value }
  if (searchValue.value && props.config.extraSearchParam) {
    params[props.config.extraSearchParam] = searchValue.value
  }
  return params
}

async function refresh() {
  await store.fetchList(KEY.value, { page: 1, ...buildParams() })
}

function onSearchUpdate(v) {
  searchValue.value = v
  refresh()
}

function onPageChange(p) {
  store.fetchList(KEY.value, { page: p, ...buildParams() })
}

// Dipanggil oleh slot #filters dari parent. Pass null/'' untuk reset key tertentu.
function updateFilter(key, value) {
  if (value === null || value === undefined || value === '') {
    delete extraFilters.value[key]
  } else {
    extraFilters.value[key] = value
  }
  refresh()
}

/** Set satu field di payload modal yang sedang terbuka (dipakai parent, mis. isi kfa_code). */
function setModalField(key, value) {
  modal.value.payload = { ...modal.value.payload, [key]: value }
}

defineExpose({ refresh, updateFilter, setModalField })

function openCreate() {
  modal.value = {
    open: true,
    mode: 'create',
    payload: { ...props.config.defaults },
    errors: null,
    submitting: false,
    editingId: null,
  }
}

function openEdit(row) {
  // Pre-fill modal pakai field yg ada di config (deep-pick supaya tidak bawa relasi)
  const payload = {}
  const fields = (props.config.editFields ?? props.config.fields) ?? []
  for (const f of fields) {
    payload[f.key] = row[f.key] ?? props.config.defaults[f.key] ?? ''
  }
  modal.value = {
    open: true,
    mode: 'edit',
    payload,
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
      await store.create(KEY.value, payload)
      showToast('s', `${props.config.title} dibuat`)
    } else {
      await store.update(KEY.value, modal.value.editingId, payload)
      showToast('s', `${props.config.title} diperbarui`)
    }
    modal.value.open = false
  } catch (e) {
    if (e.response?.status === 422) {
      modal.value.errors = e.response.data?.errors ?? null
    }
    showToast('e', e.response?.data?.message ?? `Gagal menyimpan ${props.config.title}`)
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
    await store.remove(KEY.value, confirmDelete.value.row.id)
    showToast('s', `${props.config.title} dihapus`)
    confirmDelete.value.open = false
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus')
  } finally {
    confirmDelete.value.busy = false
  }
}

// Modal fields: editFields override fields kalau mode = edit
const modalFields = computed(() => {
  if (modal.value.mode === 'edit' && props.config.editFields) {
    return props.config.editFields
  }
  return props.config.fields
})

function onImported(result) {
  showToast('s', `Import: ${result.inserted ?? 0} baru, ${result.updated ?? 0} update`)
  refresh()
}

onMounted(refresh)
</script>

<template>
  <div class="crv-wrap">
    <!-- Section header -->
    <div class="crv-section-head">
      <div>
        <h2>{{ config.title }}</h2>
        <p>{{ config.description }}</p>
      </div>
      <button v-if="!config.readOnly" class="crv-btn-primary" @click="openCreate">
        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Tambah {{ config.title }}
      </button>
    </div>

    <!-- CSV bar -->
    <CsvActionBar
      v-if="!config.readOnly"
      :resource-key="config.resourceKey"
      :show-template="config.csvShowTemplate ?? true"
      @imported="onImported"
      @error="(m) => showToast('e', m)"
    />

    <!-- Slot filters: parent render chip/dropdown custom + panggil updateFilter() -->
    <div v-if="$slots.filters" class="crv-filter-bar">
      <slot name="filters" :update-filter="updateFilter" />
    </div>

    <!-- Main table -->
    <MasterTable
      :columns="config.columns"
      :rows="store.byResource[config.resourceKey].items"
      :loading="store.byResource[config.resourceKey].loading"
      :error="store.byResource[config.resourceKey].error"
      :meta="store.byResource[config.resourceKey].meta"
      :search-value="searchValue"
      :search-placeholder="config.searchPlaceholder ?? 'Cari…'"
      :show-search="!!config.extraSearchParam"
      :hide-actions="!!config.readOnly"
      :empty-text="`Belum ada data ${config.title}. Klik tombol di atas atau import CSV.`"
      @update:search="onSearchUpdate"
      @page-change="onPageChange"
      @refresh="refresh"
    >
      <!-- Forward semua cell slots ke parent (5 view tipis) -->
      <template
        v-for="col in config.columns"
        :key="col.key"
        #[`cell-${col.key}`]="slotProps"
      >
        <slot :name="`cell-${col.key}`" v-bind="slotProps">
          <span v-if="slotProps.value === null || slotProps.value === undefined || slotProps.value === ''">—</span>
          <span v-else-if="typeof slotProps.value === 'boolean'" class="crv-bool" :class="slotProps.value ? 'on' : 'off'">
            {{ slotProps.value ? 'Ya' : 'Tidak' }}
          </span>
          <span v-else>{{ col.formatter ? col.formatter(slotProps.value, slotProps.row) : slotProps.value }}</span>
        </slot>
      </template>

      <template #actions="{ row }">
        <button class="crv-icon-btn" title="Edit" @click="openEdit(row)">
          <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
        </button>
        <button class="crv-icon-btn crv-icon-danger" title="Hapus" @click="askDelete(row)">
          <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </button>
      </template>
    </MasterTable>

    <!-- Modal CRUD -->
    <MasterFormModal
      v-model:open="modal.open"
      v-model="modal.payload"
      :title="modal.mode === 'create' ? `Tambah ${config.title}` : `Edit ${config.title}`"
      :fields="modalFields"
      :submitting="modal.submitting"
      :errors="modal.errors"
      :submit-label="modal.mode === 'create' ? 'Simpan' : 'Simpan Perubahan'"
      width="560px"
      @submit="onSubmit"
      @field-action="(e) => emit('field-action', e)"
    />

    <!-- Confirm delete -->
    <Teleport to="body">
      <div v-if="confirmDelete.open" class="crv-confirm-overlay" @click.self="confirmDelete.open = false">
        <div class="crv-confirm">
          <div class="crv-confirm-icon">
            <svg viewBox="0 0 24 24"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          </div>
          <h3>Hapus {{ config.title }}?</h3>
          <p>
            <strong>{{ config.deleteLabel ? config.deleteLabel(confirmDelete.row) : (confirmDelete.row?.name ?? confirmDelete.row?.code) }}</strong>
            akan dihapus permanen.
          </p>
          <div class="crv-confirm-actions">
            <button class="crv-btn-secondary" :disabled="confirmDelete.busy" @click="confirmDelete.open = false">Batal</button>
            <button class="crv-btn-danger" :disabled="confirmDelete.busy" @click="doDelete">
              {{ confirmDelete.busy ? 'Menghapus…' : 'Hapus' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Toast -->
    <Teleport to="body">
      <div v-if="toast" class="crv-toast-wrap">
        <div class="crv-toast" :class="`crv-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.crv-wrap { display: flex; flex-direction: column; gap: 1rem; }

.crv-filter-bar { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; padding: 0.55rem 0.8rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; }
.crv-filter-bar :deep(.crv-filter-label) { font-size: 12px; color: var(--tm); font-weight: 500; margin-right: 4px; }
.crv-filter-bar :deep(.crv-chip) { padding: 5px 12px; border-radius: 999px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 11.5px; cursor: pointer; font-weight: 500; transition: background 0.15s, border-color 0.15s, color 0.15s; }
.crv-filter-bar :deep(.crv-chip:hover) { background: var(--gl); border-color: var(--ga); color: var(--gd); }
.crv-filter-bar :deep(.crv-chip.active) { background: var(--ga); border-color: var(--ga); color: white; }
.crv-filter-bar :deep(select.crv-filter-select) { padding: 5px 9px; border: 1px solid var(--gb); border-radius: 8px; background: var(--bc); color: var(--td); font-size: 12px; cursor: pointer; }
.crv-filter-bar :deep(select.crv-filter-select:focus) { outline: none; border-color: var(--ga); }

.crv-section-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; }
.crv-section-head h2 { font-family: 'DM Serif Display', serif; font-size: 20px; color: var(--td); margin: 0; }
.crv-section-head p { font-size: 13px; color: var(--tm); margin: 4px 0 0; }

.crv-btn-primary { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; border: 1px solid var(--ga); background: var(--ga); color: white; font-size: 13px; font-weight: 500; cursor: pointer; transition: background 0.15s; flex-shrink: 0; }
.crv-btn-primary:hover { background: var(--gm); border-color: var(--gm); }
.crv-btn-primary svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }

.crv-bool { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 500; }
.crv-bool.on { background: var(--sb); color: var(--st); }
.crv-bool.off { background: var(--bs); color: var(--tu); }

.crv-icon-btn { width: 28px; height: 28px; border-radius: 7px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; margin-left: 4px; transition: background 0.15s, color 0.15s, border-color 0.15s; }
.crv-icon-btn svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.crv-icon-btn:hover { background: var(--gl); color: var(--gd); border-color: var(--ga); }
.crv-icon-danger:hover { background: var(--eb); color: var(--et); border-color: var(--ebd); }

.crv-confirm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 9100; backdrop-filter: blur(3px); padding: 1rem; }
.crv-confirm { background: var(--bc); border-radius: 16px; width: 420px; max-width: 95vw; border: 1px solid var(--gb); padding: 1.6rem 1.5rem 1.3rem; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.7rem; box-shadow: 0 20px 60px rgba(0,0,0,0.22); }
.crv-confirm-icon { width: 48px; height: 48px; border-radius: 50%; background: var(--eb); display: flex; align-items: center; justify-content: center; }
.crv-confirm-icon svg { width: 24px; height: 24px; fill: none; stroke: var(--et); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.crv-confirm h3 { font-family: 'DM Serif Display', serif; font-size: 18px; color: var(--td); margin: 0; }
.crv-confirm p { font-size: 13px; color: var(--tm); margin: 0; line-height: 1.5; }
.crv-confirm-actions { display: flex; gap: 0.6rem; margin-top: 0.5rem; width: 100%; justify-content: center; }
.crv-btn-secondary { padding: 8px 16px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 13px; cursor: pointer; font-weight: 500; }
.crv-btn-secondary:hover { background: var(--bs); }
.crv-btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }
.crv-btn-danger { padding: 8px 18px; border-radius: 8px; border: 1px solid var(--et); background: var(--et); color: white; font-size: 13px; cursor: pointer; font-weight: 500; }
.crv-btn-danger:hover:not(:disabled) { background: #b91c1c; border-color: #b91c1c; }
.crv-btn-danger:disabled { opacity: 0.6; cursor: not-allowed; }

.crv-toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
.crv-toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; animation: crv-toast-in 0.2s ease; }
.crv-toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.crv-toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
@keyframes crv-toast-in { from { transform: translateY(-8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
</style>
