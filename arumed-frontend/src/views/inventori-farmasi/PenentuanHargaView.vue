<script setup>
/**
 * PenentuanHargaView — set HPP & HJA per item Obat / BHP / IOL.
 *
 * Formula: HJA = HPP × (1 + margin%) × (ppn_enabled ? 1 + ppn_rate% : 1)
 * PPN rate = global setting (bisa diubah satu kali, semua HJA otomatis recompute).
 *
 * UX: 3 tab (Obat/BHP/IOL), inline-edit per baris, save per baris.
 * Item yang belum di-price tetap tampil sebagai baris kosong.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { inventoriHargaApi } from '@/services/api'

const TABS = [
  { key: 'MEDICATION', label: 'Obat' },
  { key: 'BHP',        label: 'BHP' },
  { key: 'IOL',        label: 'IOL' },
]

const activeTab = ref('MEDICATION')
const items = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 })
const loading = ref(false)
const error = ref(null)

const search = ref('')
let searchDebounce = null

const ppnRate = ref(11)
const ppnLoading = ref(false)
const ppnInput = ref(11)
const ppnEditing = ref(false)

const toast = ref(null)

function showToast(type, msg) {
  toast.value = { type, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

const formatRp = (v) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID')

// ─── Fetch ──────────────────────────────────────────────────────────────
async function fetchSettings() {
  try {
    const res = await inventoriHargaApi.settings.get()
    ppnRate.value = Number(res.data?.data?.ppn_rate ?? 11)
    ppnInput.value = ppnRate.value
  } catch (e) {
    showToast('e', 'Gagal memuat PPN rate')
  }
}

async function refresh(page = 1) {
  loading.value = true
  error.value = null
  try {
    const params = { page, per_page: 25 }
    if (search.value) params.search = search.value
    const res = await inventoriHargaApi.list(activeTab.value, params)
    const payload = res.data?.data
    if (payload && Array.isArray(payload.data)) {
      items.value = payload.data.map(enrichRow)
      meta.value = {
        current_page: payload.current_page ?? 1,
        last_page:    payload.last_page ?? 1,
        total:        payload.total ?? payload.data.length,
        per_page:     payload.per_page ?? 25,
      }
    } else {
      items.value = []
    }
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal memuat data'
    showToast('e', error.value)
  } finally {
    loading.value = false
  }
}

function enrichRow(r) {
  const hasPrice = r.price_id != null
  return {
    ...r,
    _hpp:    hasPrice ? Number(r.hpp ?? 0)            : null,
    _margin: hasPrice ? Number(r.margin_percent ?? 0) : null,
    _ppn:    hasPrice ? !!r.ppn_enabled               : true,
    _hja:    hasPrice ? Number(r.hja ?? 0)            : null,
    _dirty:  false,
    _saving: false,
    _hasPrice: hasPrice,
  }
}

function computeHja(hpp, margin, ppnEnabled) {
  const base = Number(hpp || 0) * (1 + Number(margin || 0) / 100)
  const result = ppnEnabled ? base * (1 + Number(ppnRate.value) / 100) : base
  return Math.round(result * 100) / 100
}

function onFieldChange(row) {
  row._dirty = true
  // recompute HJA preview
  row._hja = computeHja(row._hpp ?? 0, row._margin ?? 0, row._ppn)
}

function getItemId(row) {
  return row.id
}

async function saveRow(row) {
  if (!row._dirty) return
  if (row._hpp == null || row._hpp === '' || Number(row._hpp) < 0) {
    showToast('w', 'HPP wajib diisi (>= 0)')
    return
  }
  if (row._margin == null || row._margin === '' || Number(row._margin) < 0) {
    showToast('w', 'Margin% wajib diisi (>= 0)')
    return
  }
  row._saving = true
  try {
    await inventoriHargaApi.upsert(activeTab.value, getItemId(row), {
      hpp:            Number(row._hpp),
      margin_percent: Number(row._margin),
      ppn_enabled:    !!row._ppn,
    })
    row._dirty = false
    row._hasPrice = true
    showToast('s', 'Harga tersimpan')
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan')
  } finally {
    row._saving = false
  }
}

async function clearRow(row) {
  if (!row._hasPrice) return
  if (!confirm(`Hapus harga untuk "${displayName(row)}"? Item kembali ke status belum diset.`)) return
  try {
    await inventoriHargaApi.remove(activeTab.value, getItemId(row))
    Object.assign(row, {
      _hpp: null, _margin: null, _ppn: true, _hja: null,
      _dirty: false, _hasPrice: false,
    })
    showToast('s', 'Harga dihapus')
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus')
  }
}

function displayName(row) {
  if (activeTab.value === 'IOL') {
    return `${row.brand ?? ''} ${row.model ?? ''} ${row.power != null ? '(' + row.power + 'D)' : ''}`.trim()
  }
  return row.name
}

function displaySub(row) {
  if (activeTab.value === 'MEDICATION') {
    const parts = []
    if (row.generic_name) parts.push(row.generic_name)
    if (row.form_sediaan) parts.push(row.form_sediaan)
    return parts.join(' · ')
  }
  if (activeTab.value === 'BHP') return row.category ?? ''
  if (activeTab.value === 'IOL') return row.serial_number ? `SN: ${row.serial_number}` : ''
  return ''
}

// ─── PPN settings ──────────────────────────────────────────────────────
async function savePpn() {
  if (ppnInput.value == null || ppnInput.value < 0 || ppnInput.value > 100) {
    showToast('w', 'PPN harus 0–100')
    return
  }
  ppnLoading.value = true
  try {
    await inventoriHargaApi.settings.update({ ppn_rate: Number(ppnInput.value) })
    ppnRate.value = Number(ppnInput.value)
    ppnEditing.value = false
    showToast('s', `PPN diubah ke ${ppnRate.value}% — semua HJA dihitung ulang`)
    await refresh(meta.value.current_page)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan PPN')
  } finally {
    ppnLoading.value = false
  }
}

function cancelPpnEdit() {
  ppnInput.value = ppnRate.value
  ppnEditing.value = false
}

// ─── Search & tab ──────────────────────────────────────────────────────
function onSearchInput(v) {
  search.value = v
  if (searchDebounce) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => refresh(1), 300)
}

function switchTab(key) {
  if (activeTab.value === key) return
  const dirty = items.value.some((r) => r._dirty)
  if (dirty && !confirm('Ada perubahan belum disimpan di tab ini. Lanjut ganti tab?')) return
  activeTab.value = key
  search.value = ''
  csvResult.value = null
  refresh(1)
}

function goPage(p) {
  if (p < 1 || p > meta.value.last_page) return
  refresh(p)
}

// ─── CSV Import / Export ─────────────────────────────────────────────────
const csvBusy = ref({ template: false, export: false, import: false })
const csvResult = ref(null) // { inserted, updated, skipped, errors[] }
const csvFileInput = ref(null)

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

const tabLabel = computed(() => TABS.find((t) => t.key === activeTab.value)?.label ?? activeTab.value)

async function onCsvTemplate() {
  csvResult.value = null
  csvBusy.value.template = true
  try {
    const res = await inventoriHargaApi.templateCsv(activeTab.value)
    triggerDownload(res.data, `template-harga-${activeTab.value.toLowerCase()}.csv`)
  } catch (e) {
    showToast('e', 'Gagal mengunduh template')
  } finally {
    csvBusy.value.template = false
  }
}

async function onCsvExport() {
  csvResult.value = null
  csvBusy.value.export = true
  try {
    const res = await inventoriHargaApi.exportCsv(activeTab.value)
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '')
    triggerDownload(res.data, `harga-${activeTab.value.toLowerCase()}-${today}.csv`)
  } catch (e) {
    showToast('e', 'Gagal mengekspor CSV')
  } finally {
    csvBusy.value.export = false
  }
}

function pickCsvFile() {
  csvResult.value = null
  csvFileInput.value?.click()
}

async function onCsvFilePicked(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  csvBusy.value.import = true
  try {
    const { data } = await inventoriHargaApi.importCsv(activeTab.value, file)
    csvResult.value = data?.data ?? data
    showToast('s', data?.message ?? 'Import selesai')
    await refresh(1)
  } catch (err) {
    showToast('e', err.response?.data?.message ?? 'Gagal mengimpor CSV')
  } finally {
    csvBusy.value.import = false
  }
}

// ─── Init ──────────────────────────────────────────────────────────────
onMounted(async () => {
  await fetchSettings()
  await refresh()
})

watch(activeTab, () => { /* handled in switchTab */ })
</script>

<template>
  <div class="ph-wrap">
    <header class="ph-head">
      <div>
        <h2>Penentuan Harga Obat &amp; Alkes</h2>
        <p>Set HPP &amp; HJA per item. HJA = HPP × (1 + margin%) × PPN (jika aktif).</p>
      </div>

      <div class="ph-ppn-card">
        <span class="ph-ppn-label">PPN Rate Global</span>
        <div v-if="!ppnEditing" class="ph-ppn-display">
          <span class="ph-ppn-value">{{ ppnRate }}%</span>
          <button class="ph-ppn-edit" @click="ppnEditing = true" title="Ubah PPN">
            <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
          </button>
        </div>
        <div v-else class="ph-ppn-edit-row">
          <input
            type="number" min="0" max="100" step="0.01"
            class="ph-ppn-input"
            v-model.number="ppnInput"
            :disabled="ppnLoading"
          />
          <span class="ph-ppn-suffix">%</span>
          <button class="ph-btn-primary ph-btn-sm" :disabled="ppnLoading" @click="savePpn">{{ ppnLoading ? '…' : 'Simpan' }}</button>
          <button class="ph-btn-secondary ph-btn-sm" :disabled="ppnLoading" @click="cancelPpnEdit">Batal</button>
        </div>
        <p class="ph-ppn-hint">Ubah PPN otomatis recompute HJA semua item.</p>
      </div>
    </header>

    <!-- Tabs -->
    <div class="ph-tabs">
      <button
        v-for="t in TABS" :key="t.key"
        class="ph-tab" :class="{ active: activeTab === t.key }"
        @click="switchTab(t.key)"
      >{{ t.label }}</button>
    </div>

    <!-- CSV Import / Export (per tab) -->
    <div class="ph-csv-bar">
      <div class="ph-csv-actions">
        <button class="ph-csv-btn ph-csv-template" :disabled="csvBusy.template" @click="onCsvTemplate">
          <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 11v6M9 14h6"/></svg>
          {{ csvBusy.template ? 'Mengunduh…' : 'Template CSV' }}
        </button>
        <button class="ph-csv-btn ph-csv-import" :disabled="csvBusy.import" @click="pickCsvFile">
          <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
          {{ csvBusy.import ? 'Mengimpor…' : 'Import CSV' }}
        </button>
        <button class="ph-csv-btn ph-csv-export" :disabled="csvBusy.export" @click="onCsvExport">
          <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
          {{ csvBusy.export ? 'Mengekspor…' : 'Export CSV' }}
        </button>
        <span class="ph-csv-hint">
          Mengelola harga <strong>{{ tabLabel }}</strong> via CSV. Kunci: kolom
          <code>kode</code>{{ activeTab === 'IOL' ? ' (= serial number)' : '' }}.
          Item harus sudah ada di master data.
        </span>
        <input ref="csvFileInput" type="file" accept=".csv,.xlsx,.xls,.ods,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" @change="onCsvFilePicked" style="display:none" />
      </div>

      <div v-if="csvResult" class="ph-csv-result" :class="{ warn: csvResult.errors?.length }">
        <div class="ph-csv-summary">
          <strong>Import selesai:</strong>
          <span class="ph-csv-pill ok">{{ csvResult.inserted ?? 0 }} baru</span>
          <span class="ph-csv-pill info">{{ csvResult.updated ?? 0 }} diperbarui</span>
          <span v-if="(csvResult.skipped ?? 0) > 0" class="ph-csv-pill warn">{{ csvResult.skipped }} dilewati</span>
        </div>
        <ul v-if="csvResult.errors?.length" class="ph-csv-errors">
          <li v-for="(err, i) in csvResult.errors" :key="i">{{ err }}</li>
        </ul>
      </div>
    </div>

    <!-- Toolbar: search -->
    <div class="ph-toolbar">
      <div class="ph-search">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input
          type="text"
          :value="search"
          placeholder="Cari nama / kode / brand…"
          @input="(e) => onSearchInput(e.target.value)"
        />
      </div>
      <span class="ph-meta">{{ meta.total }} item</span>
    </div>

    <!-- Table -->
    <div class="ph-table-wrap">
      <div v-if="loading" class="ph-loading">Memuat…</div>
      <div v-else-if="error" class="ph-error">{{ error }}</div>
      <div v-else-if="!items.length" class="ph-empty">Belum ada item.</div>

      <table v-else class="ph-table">
        <thead>
          <tr>
            <th class="r col-no">No</th>
            <th class="col-name">Item</th>
            <th class="r col-num">HPP (Rp)</th>
            <th class="r col-num">Margin %</th>
            <th class="c col-ppn">PPN</th>
            <th class="r col-hja">HJA (Rp)</th>
            <th class="c col-status">Status</th>
            <th class="r col-aksi">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in items" :key="row.id" :class="{ dirty: row._dirty, 'no-price': !row._hasPrice }">
            <td class="r col-no">{{ (meta.current_page - 1) * meta.per_page + i + 1 }}</td>
            <td class="col-name">
              <div class="ph-name-cell">
                <strong>{{ displayName(row) }}</strong>
                <span v-if="displaySub(row)" class="ph-name-sub">{{ displaySub(row) }}</span>
              </div>
            </td>
            <td class="r col-num">
              <input
                type="number" min="0" step="any"
                class="ph-input-num"
                v-model.number="row._hpp"
                @input="onFieldChange(row)"
                :placeholder="row._hasPrice ? '0' : '—'"
              />
            </td>
            <td class="r col-num">
              <input
                type="number" min="0" max="1000" step="any"
                class="ph-input-num ph-input-margin"
                v-model.number="row._margin"
                @input="onFieldChange(row)"
                :placeholder="row._hasPrice ? '0' : '—'"
              />
            </td>
            <td class="c col-ppn">
              <label class="ph-switch">
                <input type="checkbox" v-model="row._ppn" @change="onFieldChange(row)" />
                <span class="ph-switch-slider"></span>
              </label>
            </td>
            <td class="r col-hja">
              <span v-if="row._hja != null" class="ph-hja" :class="{ preview: row._dirty }">{{ formatRp(row._hja) }}</span>
              <span v-else class="ph-dim">—</span>
            </td>
            <td class="c col-status">
              <span v-if="!row._hasPrice" class="ph-badge ph-badge-warn">Belum diset</span>
              <span v-else-if="row._dirty" class="ph-badge ph-badge-info">Perubahan</span>
              <span v-else class="ph-badge ph-badge-ok">Tersimpan</span>
            </td>
            <td class="r col-aksi">
              <button
                class="ph-btn-primary ph-btn-xs"
                :disabled="!row._dirty || row._saving"
                @click="saveRow(row)"
              >{{ row._saving ? '…' : 'Simpan' }}</button>
              <button
                v-if="row._hasPrice"
                class="ph-btn-danger ph-btn-xs"
                :disabled="row._saving"
                @click="clearRow(row)"
                title="Hapus harga (kembali ke belum diset)"
              >
                <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="meta.last_page > 1" class="ph-pagination">
      <button class="ph-page-btn" :disabled="meta.current_page <= 1" @click="goPage(meta.current_page - 1)">‹ Sebelumnya</button>
      <span class="ph-page-info">Halaman {{ meta.current_page }} / {{ meta.last_page }}</span>
      <button class="ph-page-btn" :disabled="meta.current_page >= meta.last_page" @click="goPage(meta.current_page + 1)">Selanjutnya ›</button>
    </div>

    <Teleport to="body">
      <div v-if="toast" class="ph-toast-wrap">
        <div class="ph-toast" :class="`ph-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.ph-wrap { display: flex; flex-direction: column; gap: 1rem; }

.ph-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1.5rem; }
.ph-head h2 { font-family: 'Space Grotesk', serif; font-size: 20px; color: var(--td); margin: 0; }
.ph-head p { font-size: 13px; color: var(--tm); margin: 4px 0 0; max-width: 600px; }

.ph-ppn-card { background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; padding: 0.7rem 0.9rem; min-width: 240px; display: flex; flex-direction: column; gap: 4px; }
.ph-ppn-label { font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--tu); }
.ph-ppn-display { display: flex; align-items: center; gap: 8px; }
.ph-ppn-value { font-family: 'Space Grotesk', serif; font-size: 22px; color: var(--td); }
.ph-ppn-edit { width: 26px; height: 26px; border-radius: 6px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
.ph-ppn-edit svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.ph-ppn-edit:hover { background: var(--gl); color: var(--td); }
.ph-ppn-edit-row { display: flex; align-items: center; gap: 6px; }
.ph-ppn-input { width: 80px; padding: 5px 8px; border-radius: 6px; border: 1px solid var(--gb); font-size: 13px; }
.ph-ppn-suffix { font-size: 13px; color: var(--tm); }
.ph-ppn-hint { font-size: 11px; color: var(--tu); margin: 2px 0 0; }

.ph-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); }
.ph-tab { padding: 9px 18px; border: none; background: transparent; color: var(--tm); font-size: 13px; font-weight: 500; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.ph-tab:hover { color: var(--td); }
.ph-tab.active { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }

.ph-csv-bar { display: flex; flex-direction: column; gap: 0.7rem; }
.ph-csv-actions { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.ph-csv-btn { display: inline-flex; align-items: center; gap: 7px; padding: 7px 13px; border-radius: 9px; border: 1px solid var(--gb); background: var(--bc); color: var(--td); font-size: 12.5px; font-weight: 500; cursor: pointer; transition: background 0.15s, color 0.15s, border-color 0.15s; }
.ph-csv-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.ph-csv-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.ph-csv-template:hover:not(:disabled) { background: var(--gl); border-color: var(--ga); color: var(--td); }
.ph-csv-import:hover:not(:disabled) { background: var(--ib); border-color: var(--ibd); color: var(--it); }
.ph-csv-export:hover:not(:disabled) { background: var(--sb); border-color: var(--sbd); color: var(--st); }
.ph-csv-hint { font-size: 11.5px; color: var(--tu); margin-left: 6px; }
.ph-csv-hint code { background: var(--bs); border: 1px solid var(--gb); border-radius: 4px; padding: 0 4px; font-size: 11px; color: var(--td); }

.ph-csv-result { padding: 0.7rem 0.9rem; background: var(--sb); border: 1px solid var(--sbd); border-radius: 10px; display: flex; flex-direction: column; gap: 0.5rem; }
.ph-csv-result.warn { background: var(--wb); border-color: var(--wbd); }
.ph-csv-summary { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; font-size: 12.5px; color: var(--td); }
.ph-csv-pill { padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; border: 1px solid; }
.ph-csv-pill.ok { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.ph-csv-pill.info { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.ph-csv-pill.warn { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.ph-csv-errors { margin: 0; padding-left: 1.2rem; font-size: 11.5px; color: var(--wt); max-height: 180px; overflow-y: auto; }
.ph-csv-errors li { margin: 2px 0; }

.ph-toolbar { display: flex; align-items: center; gap: 0.8rem; }
.ph-search { position: relative; flex: 1; max-width: 380px; }
.ph-search svg { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; fill: none; stroke: var(--tu); stroke-width: 2; }
.ph-search input { width: 100%; padding: 8px 10px 8px 32px; border-radius: 8px; border: 1px solid var(--gb); font-size: 13px; }
.ph-search input:focus { outline: none; border-color: var(--ga); }
.ph-meta { font-size: 12px; color: var(--tm); }

.ph-table-wrap { background: var(--bc); border: 1px solid var(--gb); border-radius: 10px; overflow: hidden; }
.ph-loading, .ph-error, .ph-empty { padding: 2rem; text-align: center; font-size: 13px; color: var(--tm); }
.ph-error { color: var(--et); }

.ph-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ph-table thead { background: var(--bs); }
.ph-table th { padding: 9px 10px; text-align: left; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--tu); border-bottom: 1px solid var(--gb); }
.ph-table th.r, .ph-table td.r { text-align: right; }
.ph-table th.c, .ph-table td.c { text-align: center; }
.ph-table td { padding: 8px 10px; border-bottom: 1px solid var(--gb); vertical-align: middle; }
.ph-table tr:last-child td { border-bottom: none; }
.ph-table tr.dirty td { background: rgba(250, 204, 21, 0.06); }
.ph-table tr.no-price td { background: rgba(0,0,0,0.015); }

.col-no { width: 48px; color: var(--tu); font-variant-numeric: tabular-nums; }
.col-name { min-width: 220px; }
.col-num { width: 130px; }
.col-ppn { width: 70px; }
.col-hja { width: 140px; }
.col-status { width: 100px; }
.col-aksi { width: 130px; }

.ph-name-cell { display: flex; flex-direction: column; gap: 1px; }
.ph-name-cell strong { font-weight: 500; color: var(--td); }
.ph-name-sub { font-size: 11px; color: var(--tu); }

.ph-input-num { width: 110px; padding: 5px 8px; border-radius: 6px; border: 1px solid var(--gb); font-size: 13px; text-align: right; font-variant-numeric: tabular-nums; }
.ph-input-num:focus { outline: none; border-color: var(--ga); }
.ph-input-margin { width: 70px; }

.ph-switch { position: relative; display: inline-block; width: 36px; height: 20px; }
.ph-switch input { opacity: 0; width: 0; height: 0; }
.ph-switch-slider { position: absolute; cursor: pointer; inset: 0; background: var(--gb); border-radius: 20px; transition: 0.2s; }
.ph-switch-slider:before { position: absolute; content: ''; height: 14px; width: 14px; left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: 0.2s; }
.ph-switch input:checked + .ph-switch-slider { background: var(--ga); }
.ph-switch input:checked + .ph-switch-slider:before { transform: translateX(16px); }

.ph-hja { font-weight: 600; color: var(--td); font-variant-numeric: tabular-nums; }
.ph-hja.preview { color: var(--ga); font-style: italic; }
.ph-dim { color: var(--tu); }

.ph-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.03em; }
.ph-badge-ok   { background: var(--sb); color: var(--st); }
.ph-badge-warn { background: var(--wb); color: var(--wt); }
.ph-badge-info { background: var(--ib); color: var(--it); }

.ph-btn-primary { padding: 6px 12px; border-radius: 7px; border: 1px solid var(--ga); background: var(--ga); color: white; font-size: 12px; font-weight: 500; cursor: pointer; transition: background 0.15s; }
.ph-btn-primary:hover:not(:disabled) { background: var(--gm); border-color: var(--gm); }
.ph-btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
.ph-btn-secondary { padding: 6px 12px; border-radius: 7px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 12px; cursor: pointer; }
.ph-btn-secondary:hover:not(:disabled) { background: var(--bs); }
.ph-btn-danger { padding: 5px 9px; border-radius: 7px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; margin-left: 4px; }
.ph-btn-danger svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.ph-btn-danger:hover:not(:disabled) { background: var(--eb); color: var(--et); border-color: var(--ebd); }

.ph-btn-sm { padding: 5px 11px; font-size: 12px; }
.ph-btn-xs { padding: 4px 10px; font-size: 11.5px; }

.ph-pagination { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 0.5rem 0; }
.ph-page-btn { padding: 6px 14px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 12px; cursor: pointer; }
.ph-page-btn:hover:not(:disabled) { background: var(--bs); }
.ph-page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.ph-page-info { font-size: 12px; color: var(--tm); }

.ph-toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; }
.ph-toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; }
.ph-toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.ph-toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.ph-toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.ph-toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
</style>
