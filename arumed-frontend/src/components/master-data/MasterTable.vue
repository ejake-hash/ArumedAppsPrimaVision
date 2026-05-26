<script setup>
/**
 * MasterTable — generic table untuk halaman master data.
 *
 * Props:
 *   columns       : Array<{ key, label, formatter?, width?, align? }>
 *   rows          : Array<object>
 *   loading       : boolean
 *   error         : string | null
 *   meta          : { current_page, last_page, total, per_page }   — paginate state
 *   searchValue   : string  (v-model:search)
 *   searchPlaceholder : string
 *   showSearch    : boolean (default true)
 *   showPagination: boolean (default true)
 *   emptyText     : string
 *   rowKey        : string  (default 'id')
 *
 * Slots:
 *   cell-<columnKey> : custom render per cell, receive { row, value }
 *   actions          : tombol per row, receive { row }
 *   toolbar          : ekstra di header (di kanan search box)
 *
 * Emits:
 *   update:search   (v) — saat user ketik di search box (debounced)
 *   page-change     (n) — saat user klik page navigation
 *   refresh         () — saat user klik tombol refresh
 */
import { computed, ref, watch } from 'vue'

const props = defineProps({
  columns:           { type: Array,  required: true },
  rows:              { type: Array,  default: () => [] },
  loading:           { type: Boolean, default: false },
  error:             { type: String, default: null },
  meta:              { type: Object, default: () => ({ current_page: 1, last_page: 1, total: 0, per_page: 25 }) },
  searchValue:       { type: String, default: '' },
  searchPlaceholder: { type: String, default: 'Cari…' },
  showSearch:        { type: Boolean, default: true },
  showPagination:    { type: Boolean, default: true },
  emptyText:         { type: String, default: 'Belum ada data.' },
  rowKey:            { type: String, default: 'id' },
})

const emit = defineEmits(['update:search', 'page-change', 'refresh'])

const localSearch = ref(props.searchValue)
watch(() => props.searchValue, (v) => { localSearch.value = v })

// Debounce search 300ms
let debounceTimer = null
function onSearchInput(e) {
  localSearch.value = e.target.value
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    emit('update:search', localSearch.value)
  }, 300)
}

const pages = computed(() => {
  const last = props.meta.last_page ?? 1
  const cur  = props.meta.current_page ?? 1
  // Window 5 page sekitar current
  const start = Math.max(1, cur - 2)
  const end   = Math.min(last, start + 4)
  const out = []
  for (let i = start; i <= end; i++) out.push(i)
  return out
})

function goPage(n) {
  if (n < 1 || n > (props.meta.last_page ?? 1) || n === props.meta.current_page) return
  emit('page-change', n)
}

function renderCell(col, row) {
  const raw = row[col.key]
  if (col.formatter) return col.formatter(raw, row)
  if (raw === null || raw === undefined || raw === '') return '—'
  return raw
}
</script>

<template>
  <div class="mt-wrap">
    <!-- Toolbar: search + slot toolbar + refresh -->
    <div v-if="showSearch || $slots.toolbar" class="mt-toolbar">
      <div v-if="showSearch" class="mt-search">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input
          type="text"
          :value="localSearch"
          :placeholder="searchPlaceholder"
          @input="onSearchInput"
        />
      </div>
      <div v-else class="mt-search-spacer"></div>
      <div class="mt-toolbar-right">
        <slot name="toolbar" />
        <button class="mt-btn-icon" title="Muat ulang" @click="emit('refresh')">
          <svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 0 1 15.5-6.3M21 4v5h-5"/><path d="M21 12a9 9 0 0 1-15.5 6.3M3 20v-5h5"/></svg>
        </button>
      </div>
    </div>

    <!-- Error banner -->
    <div v-if="error" class="mt-error">{{ error }}</div>

    <!-- Table -->
    <div class="mt-scroll">
      <table class="mt-table">
        <thead>
          <tr>
            <th
              v-for="col in columns"
              :key="col.key"
              :style="{ width: col.width, textAlign: col.align ?? 'left' }"
            >
              {{ col.label }}
            </th>
            <th v-if="$slots.actions" class="mt-actions-col">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading && rows.length === 0">
            <td :colspan="columns.length + ($slots.actions ? 1 : 0)" class="mt-state">
              <span class="mt-spinner"></span> Memuat…
            </td>
          </tr>
          <tr v-else-if="!loading && rows.length === 0">
            <td :colspan="columns.length + ($slots.actions ? 1 : 0)" class="mt-state">
              {{ emptyText }}
            </td>
          </tr>
          <tr v-for="row in rows" :key="row[rowKey]" v-else>
            <td
              v-for="col in columns"
              :key="col.key"
              :style="{ textAlign: col.align ?? 'left' }"
            >
              <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                {{ renderCell(col, row) }}
              </slot>
            </td>
            <td v-if="$slots.actions" class="mt-actions">
              <slot name="actions" :row="row" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination footer -->
    <div v-if="showPagination && meta.last_page > 1" class="mt-pagination">
      <div class="mt-page-info">
        Halaman {{ meta.current_page }} dari {{ meta.last_page }}
        · {{ meta.total }} baris total
      </div>
      <div class="mt-page-nav">
        <button :disabled="meta.current_page <= 1" @click="goPage(1)">«</button>
        <button :disabled="meta.current_page <= 1" @click="goPage(meta.current_page - 1)">‹</button>
        <button
          v-for="p in pages"
          :key="p"
          :class="{ active: p === meta.current_page }"
          @click="goPage(p)"
        >{{ p }}</button>
        <button :disabled="meta.current_page >= meta.last_page" @click="goPage(meta.current_page + 1)">›</button>
        <button :disabled="meta.current_page >= meta.last_page" @click="goPage(meta.last_page)">»</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.mt-wrap { display: flex; flex-direction: column; gap: 0.85rem; }

/* Toolbar */
.mt-toolbar { display: flex; align-items: center; gap: 1rem; }
.mt-search { flex: 1; position: relative; max-width: 360px; }
.mt-search svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; fill: none; stroke: var(--tu); stroke-width: 2; stroke-linecap: round; }
.mt-search input { width: 100%; padding: 9px 12px 9px 36px; border: 1px solid var(--gb); border-radius: 10px; background: var(--bc); font-size: 13px; color: var(--td); transition: border-color 0.15s; }
.mt-search input:focus { outline: none; border-color: var(--ga); box-shadow: 0 0 0 3px rgba(31,125,74,0.12); }
.mt-search-spacer { flex: 1; }
.mt-toolbar-right { display: flex; align-items: center; gap: 0.6rem; }
.mt-btn-icon { width: 34px; height: 34px; border-radius: 9px; background: var(--bc); border: 1px solid var(--gb); cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--tm); transition: background 0.15s, color 0.15s; }
.mt-btn-icon:hover { background: var(--gl); color: var(--gd); }
.mt-btn-icon svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

.mt-error { padding: 0.7rem 1rem; background: var(--eb); border: 1px solid var(--ebd); border-radius: 10px; color: var(--et); font-size: 13px; }

/* Table */
.mt-scroll { overflow-x: auto; border: 1px solid var(--gb); border-radius: 12px; background: var(--bc); }
.mt-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mt-table thead th { background: var(--bs); padding: 11px 14px; text-align: left; font-weight: 600; color: var(--tm); border-bottom: 1px solid var(--gb); white-space: nowrap; font-size: 12px; text-transform: uppercase; letter-spacing: 0.03em; }
.mt-table tbody tr { transition: background 0.1s; }
.mt-table tbody tr:hover { background: var(--bs); }
.mt-table tbody td { padding: 11px 14px; border-bottom: 1px solid var(--gb); color: var(--td); vertical-align: middle; }
.mt-table tbody tr:last-child td { border-bottom: none; }
.mt-actions-col { width: 1%; white-space: nowrap; text-align: right; }
.mt-actions { white-space: nowrap; text-align: right; }
.mt-state { padding: 2.2rem 1rem; text-align: center; color: var(--tu); font-size: 13px; }
.mt-spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid var(--gb); border-top-color: var(--ga); border-radius: 50%; animation: mt-spin 0.7s linear infinite; vertical-align: middle; margin-right: 8px; }
@keyframes mt-spin { to { transform: rotate(360deg); } }

/* Pagination */
.mt-pagination { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.mt-page-info { font-size: 12px; color: var(--tu); }
.mt-page-nav { display: flex; gap: 4px; }
.mt-page-nav button { min-width: 32px; height: 32px; padding: 0 8px; border: 1px solid var(--gb); background: var(--bc); border-radius: 7px; cursor: pointer; font-size: 12px; color: var(--td); transition: background 0.15s, color 0.15s, border-color 0.15s; }
.mt-page-nav button:hover:not(:disabled) { background: var(--gl); border-color: var(--ga); }
.mt-page-nav button.active { background: var(--ga); color: white; border-color: var(--ga); }
.mt-page-nav button:disabled { opacity: 0.4; cursor: not-allowed; }
</style>
