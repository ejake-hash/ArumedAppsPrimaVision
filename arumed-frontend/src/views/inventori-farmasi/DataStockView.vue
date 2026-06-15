<script setup>
/**
 * DataStockView — halaman penuh "Data Stock" (section Operasional).
 *
 * Versi tabel-lebar dari InventoriStockSidebar: menampilkan item stok per
 * tipe (Obat/BHP/IOL) dan lokasi (Gudang/Farmasi/Bedah) dengan rincian batch
 * yang bisa dibuka. Aksi tulis (gate inventori_farmasi.write): "+ Stok" (tambah
 * batch aditif) + Import CSV/Excel (set-total opname). Koreksi hitung-fisik penuh
 * tetap lewat menu Stock Opname (Berita Acara). Sumber data: inventoriStockApi.list
 * (GET /inventori-farmasi/stock/{type}?location=&search=).
 */
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { inventoriStockApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'

const auth = useAuthStore()
const canWrite = computed(() => auth.can('inventori_farmasi.write'))

const STOCK_TABS = [
  { key: 'MEDICATION', label: 'Obat', csvType: 'obat', csvable: true },
  { key: 'BHP',        label: 'BHP',  csvType: 'bhp',  csvable: true },
  { key: 'IOL',        label: 'IOL',  csvType: null,   csvable: false },
]

// Lokasi stok: INVENTORI (gudang induk), FARMASI, BEDAH.
const STOCK_LOCATIONS = [
  { key: 'INVENTORI', label: 'Gudang' },
  { key: 'FARMASI',   label: 'Farmasi' },
  { key: 'BEDAH',     label: 'Bedah' },
]

const stockTab = ref('MEDICATION')
const stockLocation = ref('INVENTORI')
const stockSearch = ref('')
const stockList = ref([])
const loading = ref(false)
const errorMsg = ref('')
const expanded = ref(new Set())
let searchTimer = null

// ─── Toast ringan ───────────────────────────────────────────────────────
const toast = ref(null)
function showToast(type, msg) {
  toast.value = { type, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

const activeTab = computed(() => STOCK_TABS.find((t) => t.key === stockTab.value))
const activeLocationLabel = computed(() => STOCK_LOCATIONS.find((l) => l.key === stockLocation.value)?.label ?? stockLocation.value)
// Lokasi unit (non-gudang) hanya menampilkan item yang pernah dikirim gudang.
const isUnitLocation = computed(() => stockLocation.value !== 'INVENTORI')

// ─── Ringkasan ──────────────────────────────────────────────────────────
const summary = computed(() => {
  const list = stockList.value
  let withStock = 0, empty = 0, expSoon = 0, expired = 0
  for (const r of list) {
    if ((Number(r.total_qty) || 0) > 0) withStock++; else empty++
    const days = expiryDays(r.nearest_expiry)
    if (days !== null && days < 0) expired++
    else if (days !== null && days <= 30) expSoon++
  }
  return { total: list.length, withStock, empty, expSoon, expired }
})

async function refreshStock() {
  loading.value = true
  errorMsg.value = ''
  try {
    const params = { location: stockLocation.value }
    if (stockSearch.value.trim()) params.search = stockSearch.value.trim()
    const res = await inventoriStockApi.list(stockTab.value, params)
    stockList.value = Array.isArray(res.data?.data) ? res.data.data : []
  } catch (e) {
    errorMsg.value = e.response?.data?.message ?? 'Gagal memuat stok'
    stockList.value = []
  } finally {
    loading.value = false
  }
}

function switchTab(k) {
  if (stockTab.value === k) return
  stockTab.value = k
  stockSearch.value = ''
  expanded.value = new Set()
  importResult.value = null   // hasil import lama tak relevan utk tab baru
  refreshStock()
}

function switchLocation(k) {
  if (stockLocation.value === k) return
  stockLocation.value = k
  expanded.value = new Set()
  importResult.value = null   // panel hasil jangan tampil dgn label lokasi keliru
  refreshStock()
}

function toggleRow(id) {
  const s = new Set(expanded.value)
  s.has(id) ? s.delete(id) : s.add(id)
  expanded.value = s
}

watch(stockSearch, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(refreshStock, 300)
})
onUnmounted(() => {
  clearTimeout(searchTimer)
  document.removeEventListener('click', onMenuDocClick)
})

// ─── Util tanggal/expiry ────────────────────────────────────────────────
const formatDate = (v) => v ? new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

function expiryDays(dateStr) {
  if (!dateStr) return null
  return Math.ceil((new Date(dateStr).getTime() - Date.now()) / 86_400_000)
}

function expiryClass(dateStr) {
  const days = expiryDays(dateStr)
  if (days === null) return 'ds-exp-none'
  if (days < 0)  return 'ds-exp-expired'
  if (days <= 30) return 'ds-exp-soon'
  if (days <= 90) return 'ds-exp-warn'
  return 'ds-exp-ok'
}

// ─── Toolbar CSV/Excel (pola CsvActionBar master-data) ──────────────────
const csvFileInput = ref(null)
const busy = ref({ template: false, export: false, import: false })
const importResult = ref(null)   // { applied, skipped, errors[] }

// Dropdown format: 'template' | 'export' | null
const openMenu = ref(null)
function toggleMenu(which) { openMenu.value = openMenu.value === which ? null : which }
function closeMenu() { openMenu.value = null }
function onMenuDocClick(e) { if (!e.target.closest?.('.csv-split')) closeMenu() }

function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}

// format: 'xlsx' | 'csv'
async function exportStock(format = 'csv') {
  closeMenu()
  if (!activeTab.value?.csvable) return
  busy.value.export = true
  try {
    const res = await inventoriStockApi.exportCsv(activeTab.value.csvType, stockLocation.value, format)
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '')
    const ext = format === 'xlsx' ? 'xlsx' : 'csv'
    downloadBlob(res.data, `stok-${activeTab.value.csvType}-${stockLocation.value}-${today}.${ext}`)
  } catch {
    showToast('e', 'Gagal mengekspor stok')
  } finally {
    busy.value.export = false
  }
}

async function downloadTemplate(format = 'csv') {
  closeMenu()
  if (!activeTab.value?.csvable) return
  busy.value.template = true
  try {
    const res = await inventoriStockApi.templateCsv(activeTab.value.csvType, format)
    const ext = format === 'xlsx' ? 'xlsx' : 'csv'
    downloadBlob(res.data, `template-stok-${activeTab.value.csvType}.${ext}`)
  } catch {
    showToast('e', 'Gagal mengunduh template')
  } finally {
    busy.value.template = false
  }
}

function triggerImport() {
  importResult.value = null
  csvFileInput.value?.click()
}

async function onImportFile(e) {
  const file = e.target.files?.[0]
  if (csvFileInput.value) csvFileInput.value.value = ''
  if (!file || !activeTab.value?.csvable) return
  busy.value.import = true
  importResult.value = null
  try {
    const res = await inventoriStockApi.importCsv(activeTab.value.csvType, file, stockLocation.value)
    importResult.value = res.data?.data ?? null
    showToast('s', res.data?.message ?? 'Import selesai')
    await refreshStock()
  } catch (err) {
    showToast('e', err.response?.data?.message ?? 'Gagal mengimport file')
  } finally {
    busy.value.import = false
  }
}

// ─── Tambah Stok (opname mode batch aditif) ─────────────────────────────
const addOpen = ref(false)
const addItem = ref(null)        // row { id, code, name, unit, total_qty }
const addForm = ref({ qty: null, batch_no: '', expiry_date: '', reason: '' })
const addSaving = ref(false)

function openAdd(row) {
  if (!canWrite.value || stockTab.value === 'IOL') return
  addItem.value = row
  addForm.value = { qty: null, batch_no: '', expiry_date: '', reason: '' }
  addOpen.value = true
}

function closeAdd() {
  addOpen.value = false
  addItem.value = null
}

async function submitAdd() {
  if (!addItem.value || addSaving.value) return
  const qty = Number(addForm.value.qty) || 0
  if (qty <= 0) { showToast('e', 'Jumlah harus lebih dari 0'); return }
  addSaving.value = true
  try {
    // Mode batch aditif: kirim 1 batch baru (tanpa stock_id) → ditambahkan,
    // batch existing tidak tersentuh. Beda dari import (set-total/opname).
    await inventoriStockApi.opname({
      item_type: stockTab.value,
      item_id: addItem.value.id,
      location: stockLocation.value,
      batches: [{
        batch_no: addForm.value.batch_no?.trim() || null,
        expiry_date: addForm.value.expiry_date || null,
        qty_physical: qty,
      }],
      reason: addForm.value.reason?.trim() || 'Tambah stok manual via Data Stock',
    })
    showToast('s', `Stok ditambahkan: +${qty} ${addItem.value.unit}`)
    closeAdd()
    await refreshStock()
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menambah stok')
  } finally {
    addSaving.value = false
  }
}

onMounted(() => {
  refreshStock()
  document.addEventListener('click', onMenuDocClick)
})
</script>

<template>
  <div class="ds-wrap">
    <header class="ds-head">
      <div>
        <h2>Data Stock</h2>
        <p class="ds-sub">
          Item stok di {{ activeLocationLabel }} — {{ activeTab?.label }}
          <span v-if="isUnitLocation" class="ds-sub-note">· hanya item kiriman gudang</span>
        </p>
      </div>
      <button class="ds-refresh" :disabled="loading" @click="refreshStock" title="Muat ulang">
        <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Refresh
      </button>
    </header>

    <!-- Toolbar Template / Import / Export (pola CsvActionBar master-data) -->
    <div v-if="activeTab?.csvable" class="csv-bar">
      <div class="csv-actions">
        <!-- Template: split-button CSV/Excel -->
        <div class="csv-split">
          <button class="csv-btn csv-btn-template" :disabled="busy.template" @click="toggleMenu('template')">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 11v6M9 14h6"/></svg>
            {{ busy.template ? 'Mengunduh…' : 'Template' }}
            <svg class="csv-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div v-if="openMenu === 'template'" class="csv-menu">
            <button @click="downloadTemplate('csv')">Format CSV</button>
            <button @click="downloadTemplate('xlsx')">Format Excel (.xlsx)</button>
          </div>
        </div>

        <button v-if="canWrite" class="csv-btn csv-btn-import" :disabled="busy.import" @click="triggerImport">
          <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
          {{ busy.import ? 'Mengimpor…' : 'Import CSV/Excel' }}
        </button>

        <!-- Export: split-button CSV/Excel -->
        <div class="csv-split">
          <button class="csv-btn csv-btn-export" :disabled="busy.export" @click="toggleMenu('export')">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
            {{ busy.export ? 'Mengekspor…' : 'Export' }}
            <svg class="csv-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div v-if="openMenu === 'export'" class="csv-menu">
            <button @click="exportStock('csv')">Format CSV</button>
            <button @click="exportStock('xlsx')">Format Excel (.xlsx)</button>
          </div>
        </div>

        <input
          ref="csvFileInput" type="file"
          accept=".csv,.xlsx,.xls,.ods,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
          style="display:none" @change="onImportFile"
        />
      </div>

      <!-- Result panel import -->
      <div v-if="importResult" class="csv-result" :class="{ 'csv-result-warn': importResult.errors?.length }">
        <div class="csv-result-summary">
          <strong>Import selesai:</strong>
          <span class="csv-pill csv-pill-ok">{{ importResult.applied ?? 0 }} disesuaikan</span>
          <span v-if="(importResult.skipped ?? 0) > 0" class="csv-pill csv-pill-warn">{{ importResult.skipped }} dilewati</span>
        </div>
        <ul v-if="importResult.errors?.length" class="csv-errors">
          <li v-for="(er, i) in importResult.errors" :key="i">{{ er }}</li>
        </ul>
      </div>
      <p class="csv-hint">Import bersifat <strong>set total stok</strong> (opname) untuk lokasi <strong>{{ activeLocationLabel }}</strong>. Kolom: <code>code, name, qty</code> — <code>code</code> opsional. Untuk menambah stok pakai tombol <strong>+ Stok</strong> per baris.</p>
    </div>

    <!-- Tipe item -->
    <nav class="ds-tabs">
      <button
        v-for="t in STOCK_TABS" :key="t.key"
        class="ds-tab" :class="{ active: stockTab === t.key }"
        @click="switchTab(t.key)"
      >{{ t.label }}</button>
    </nav>

    <!-- Lokasi + search -->
    <div class="ds-filters">
      <div class="ds-loc">
        <span class="ds-loc-lbl">Lokasi</span>
        <div class="ds-loc-seg">
          <button
            v-for="l in STOCK_LOCATIONS" :key="l.key"
            class="ds-loc-btn" :class="{ active: stockLocation === l.key }"
            @click="switchLocation(l.key)"
          >{{ l.label }}</button>
        </div>
      </div>
      <input
        v-model="stockSearch"
        class="ds-search"
        :placeholder="`Cari ${activeTab?.label.toLowerCase()} (nama / kode)…`"
      />
    </div>

    <!-- Ringkasan -->
    <div class="ds-stats">
      <div class="ds-stat"><span class="ds-stat-n">{{ summary.total }}</span><span class="ds-stat-l">Item</span></div>
      <div class="ds-stat"><span class="ds-stat-n ok">{{ summary.withStock }}</span><span class="ds-stat-l">Ada stok</span></div>
      <div class="ds-stat"><span class="ds-stat-n muted">{{ summary.empty }}</span><span class="ds-stat-l">Stok 0</span></div>
      <div class="ds-stat"><span class="ds-stat-n warn">{{ summary.expSoon }}</span><span class="ds-stat-l">Exp ≤30 hari</span></div>
      <div class="ds-stat"><span class="ds-stat-n danger">{{ summary.expired }}</span><span class="ds-stat-l">Kadaluwarsa</span></div>
    </div>

    <!-- Tabel -->
    <div class="ds-tablewrap">
      <div v-if="loading" class="ds-state">Memuat…</div>
      <div v-else-if="errorMsg" class="ds-state ds-err">{{ errorMsg }}</div>
      <div v-else-if="!stockList.length" class="ds-state">
        <template v-if="stockSearch">Tidak ada hasil</template>
        <template v-else-if="isUnitLocation">Belum ada barang yang dikirim gudang ke {{ activeLocationLabel }}</template>
        <template v-else>Belum ada data stok di lokasi ini</template>
      </div>
      <table v-else class="ds-table">
        <thead>
          <tr>
            <th class="ds-col-no">No.</th>
            <th class="ds-col-exp"></th>
            <th>Kode</th>
            <th>Nama</th>
            <th class="num">Total Stok</th>
            <th class="num">Batch</th>
            <th>Exp Terdekat</th>
            <th v-if="canWrite && stockTab !== 'IOL'" class="ds-col-act"></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="(row, idx) in stockList" :key="row.id">
            <tr class="ds-row" :class="{ 'is-empty': (Number(row.total_qty) || 0) === 0 }" @click="toggleRow(row.id)">
              <td class="ds-col-no">{{ idx + 1 }}</td>
              <td class="ds-col-exp">
                <button v-if="row.batches?.length" class="ds-caret" :class="{ open: expanded.has(row.id) }">▸</button>
              </td>
              <td class="ds-code">{{ row.code }}</td>
              <td class="ds-name">{{ row.name }}</td>
              <td class="num ds-qty">{{ row.total_qty }} <small>{{ row.unit }}</small></td>
              <td class="num">{{ row.batches?.length || 0 }}</td>
              <td>
                <span class="ds-exp-pill" :class="expiryClass(row.nearest_expiry)">{{ formatDate(row.nearest_expiry) }}</span>
              </td>
              <td v-if="canWrite && stockTab !== 'IOL'" class="ds-col-act">
                <button class="ds-add-btn" title="Tambah stok" @click.stop="openAdd(row)">+ Stok</button>
              </td>
            </tr>
            <tr v-if="expanded.has(row.id) && row.batches?.length" class="ds-detail">
              <td colspan="2"></td>
              <td :colspan="canWrite && stockTab !== 'IOL' ? 6 : 5">
                <div class="ds-batches">
                  <div class="ds-batch ds-batch-head">
                    <span>No. Batch</span><span>Kadaluwarsa</span><span class="num">Qty</span>
                  </div>
                  <div
                    v-for="(b, i) in row.batches" :key="i"
                    class="ds-batch" :class="expiryClass(b.expiry_date)"
                  >
                    <span class="ds-batchno">{{ b.batch_no || '—' }}</span>
                    <span>{{ formatDate(b.expiry_date) }}</span>
                    <span class="num">{{ b.qty }}</span>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- Modal Tambah Stok -->
    <Teleport to="body">
      <div v-if="addOpen" class="ds-modal-backdrop" @click.self="closeAdd">
        <div class="ds-modal">
          <header class="ds-modal-head">
            <div>
              <strong>Tambah Stok</strong>
              <div class="ds-modal-sub">{{ addItem?.code }} · {{ addItem?.name }} — {{ activeLocationLabel }}</div>
            </div>
            <button class="ds-modal-close" @click="closeAdd">✕</button>
          </header>

          <div class="ds-modal-body">
            <div class="ds-field">
              <label>Jumlah ditambah <span class="req">*</span></label>
              <div class="ds-qty-row">
                <input type="number" min="0" step="0.01" v-model.number="addForm.qty" placeholder="0" />
                <span class="ds-unit">{{ addItem?.unit }}</span>
              </div>
              <small class="ds-hint">Stok saat ini: <strong>{{ addItem?.total_qty }}</strong> {{ addItem?.unit }} → menjadi <strong>{{ (Number(addItem?.total_qty) || 0) + (Number(addForm.qty) || 0) }}</strong></small>
            </div>
            <div class="ds-field-2">
              <div class="ds-field">
                <label>No. Batch</label>
                <input type="text" v-model="addForm.batch_no" placeholder="opsional" />
              </div>
              <div class="ds-field">
                <label>Kadaluwarsa</label>
                <input type="date" v-model="addForm.expiry_date" />
              </div>
            </div>
            <div class="ds-field">
              <label>Catatan</label>
              <textarea rows="2" v-model="addForm.reason" placeholder="mis. stok awal, koreksi, hibah"></textarea>
            </div>
            <p class="ds-modal-note">
              Penambahan ini membuat <strong>batch baru</strong> tanpa mengubah batch lama. Untuk pemasukan barang
              resmi dari supplier (dengan harga &amp; faktur), gunakan menu <strong>Penerimaan</strong>.
            </p>
          </div>

          <footer class="ds-modal-foot">
            <button class="ds-btn" @click="closeAdd" :disabled="addSaving">Batal</button>
            <button class="ds-btn primary" @click="submitAdd" :disabled="addSaving || !(Number(addForm.qty) > 0)">
              {{ addSaving ? 'Menyimpan…' : 'Tambah Stok' }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <!-- Toast -->
    <Teleport to="body">
      <div v-if="toast" class="ds-toast" :class="`t-${toast.type}`">{{ toast.msg }}</div>
    </Teleport>
  </div>
</template>

<style scoped>
.ds-wrap { display: flex; flex-direction: column; gap: 1rem; }

.ds-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.ds-head h2 { font-family: 'Space Grotesk', serif; font-size: 20px; color: var(--td); margin: 0; }
.ds-sub { font-size: 12.5px; color: var(--tm); margin: 3px 0 0; }
.ds-sub-note { color: var(--ga); font-weight: 500; }
.ds-btn { padding: 7px 12px; font-size: 12.5px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--td); cursor: pointer; white-space: nowrap; }
.ds-btn:hover:not(:disabled) { background: var(--gl); color: var(--ga); border-color: var(--ga); }
.ds-btn:disabled { opacity: .5; cursor: not-allowed; }
.ds-btn.primary { background: var(--ga); color: #fff; border-color: var(--ga); }
.ds-btn.primary:hover:not(:disabled) { filter: brightness(.95); color: #fff; }

.ds-refresh { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 9px; border: 1px solid var(--gb); background: var(--bc); color: var(--td); font-size: 12.5px; font-weight: 500; cursor: pointer; }
.ds-refresh svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.ds-refresh:hover:not(:disabled) { background: var(--gl); border-color: var(--ga); color: var(--ga); }
.ds-refresh:disabled { opacity: .55; cursor: not-allowed; }

/* Toolbar CSV/Excel — selaras CsvActionBar (master-data) */
.csv-bar { display: flex; flex-direction: column; gap: 0.7rem; }
.csv-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.csv-split { position: relative; display: inline-flex; }
.csv-caret { width: 12px !important; height: 12px !important; margin-left: 1px; opacity: 0.7; }
.csv-menu { position: absolute; top: calc(100% + 4px); left: 0; z-index: 50; min-width: 170px; background: var(--bc); border: 1px solid var(--gb); border-radius: 9px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); padding: 4px; display: flex; flex-direction: column; }
.csv-menu button { text-align: left; background: transparent; border: none; padding: 8px 11px; border-radius: 6px; font-size: 12.5px; color: var(--td); cursor: pointer; }
.csv-menu button:hover { background: var(--bs); }
.csv-btn { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 9px; border: 1px solid var(--gb); background: var(--bc); color: var(--td); font-size: 12.5px; font-weight: 500; cursor: pointer; transition: background 0.15s, color 0.15s, border-color 0.15s; }
.csv-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.csv-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.csv-btn-template:hover:not(:disabled) { background: var(--gl); border-color: var(--ga); color: var(--td); }
.csv-btn-import:hover:not(:disabled) { background: var(--ib); border-color: var(--ibd); color: var(--it); }
.csv-btn-export:hover:not(:disabled) { background: var(--sb); border-color: var(--sbd); color: var(--st); }

.csv-result { padding: 0.8rem 1rem; background: var(--sb); border: 1px solid var(--sbd); border-radius: 10px; display: flex; flex-direction: column; gap: 0.6rem; }
.csv-result-warn { background: var(--wb); border-color: var(--wbd); }
.csv-result-summary { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; font-size: 13px; color: var(--td); }
.csv-pill { padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.csv-pill-ok { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.csv-pill-warn { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }
.csv-errors { margin: 0; padding-left: 1.2rem; font-size: 12px; color: var(--wt); max-height: 200px; overflow-y: auto; }
.csv-errors li { margin: 2px 0; }
.csv-hint { font-size: 11.5px; color: var(--tu); margin: 0; }
.csv-hint code { background: var(--bs); padding: 1px 5px; border-radius: 4px; font-size: 11px; }

.ds-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); }
.ds-tab { padding: 8px 16px; font-size: 13px; font-weight: 600; color: var(--tm); background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -1px; cursor: pointer; }
.ds-tab:hover { color: var(--td); }
.ds-tab.active { color: var(--ga); border-bottom-color: var(--ga); }

.ds-filters { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.ds-loc { display: flex; align-items: center; gap: 8px; }
.ds-loc-lbl { font-size: 12px; font-weight: 600; color: var(--tm); }
.ds-loc-seg { display: flex; gap: 4px; }
.ds-loc-btn { padding: 5px 12px; font-size: 12px; font-weight: 600; color: var(--tm); background: var(--bc); border: 1px solid var(--gb); border-radius: 7px; cursor: pointer; }
.ds-loc-btn:hover { background: var(--gl); color: var(--td); }
.ds-loc-btn.active { background: var(--ga); color: #fff; border-color: var(--ga); }
.ds-search { flex: 1; min-width: 200px; max-width: 360px; padding: 7px 10px; font-size: 13px; border: 1px solid var(--gb); border-radius: 8px; background: var(--bc); color: var(--td); }
.ds-search:focus { outline: none; border-color: var(--ga); }

.ds-stats { display: flex; gap: 10px; flex-wrap: wrap; }
.ds-stat { display: flex; flex-direction: column; gap: 1px; padding: 8px 14px; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; min-width: 80px; }
.ds-stat-n { font-size: 18px; font-weight: 700; color: var(--td); }
.ds-stat-n.ok { color: #15803d; }
.ds-stat-n.muted { color: var(--tu); }
.ds-stat-n.warn { color: #b45309; }
.ds-stat-n.danger { color: #b91c1c; }
.ds-stat-l { font-size: 11px; color: var(--tm); }

.ds-tablewrap { border: 1px solid var(--gb); border-radius: 10px; overflow: hidden; }
.ds-state { text-align: center; color: var(--tu); font-size: 13px; padding: 40px 0; }
.ds-err { color: #dc2626; }

.ds-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ds-table thead th { background: var(--bs); text-align: left; padding: 9px 12px; font-size: 11.5px; font-weight: 600; color: var(--tm); text-transform: uppercase; letter-spacing: .03em; border-bottom: 1px solid var(--gb); }
.ds-table th.num, .ds-table td.num { text-align: right; }
.ds-col-no { width: 44px; text-align: right; color: var(--tu); font-size: 12px; }
.ds-col-exp { width: 34px; }
.ds-col-act { width: 78px; text-align: right; }
.ds-add-btn { padding: 4px 9px; font-size: 11.5px; font-weight: 600; border-radius: 6px; border: 1px solid var(--ga); background: var(--bc); color: var(--ga); cursor: pointer; white-space: nowrap; }
.ds-add-btn:hover { background: var(--ga); color: #fff; }
.ds-row { cursor: pointer; border-bottom: 1px solid var(--gb); }
.ds-row:hover { background: var(--bs); }
.ds-row td { padding: 9px 12px; color: var(--td); vertical-align: middle; }
.ds-row.is-empty .ds-qty { color: var(--tu); }
.ds-caret { background: none; border: none; cursor: pointer; color: var(--tm); font-size: 12px; transition: transform .15s; display: inline-block; }
.ds-caret.open { transform: rotate(90deg); color: var(--ga); }
.ds-code { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; color: var(--tu); white-space: nowrap; }
.ds-name { font-weight: 500; }
.ds-qty { font-weight: 700; white-space: nowrap; }
.ds-qty small { font-size: 10.5px; color: var(--tu); font-weight: 500; margin-left: 2px; }

.ds-exp-pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 500; }
.ds-exp-expired { background: #fee2e2; color: #991b1b; }
.ds-exp-soon    { background: #fef3c7; color: #92400e; }
.ds-exp-warn    { background: #fef9c3; color: #854d0e; }
.ds-exp-ok      { background: var(--bs); color: var(--tm); }
.ds-exp-none    { background: var(--bs); color: var(--tu); }

.ds-detail td { background: var(--bs); padding: 6px 12px 10px; }
.ds-batches { display: flex; flex-direction: column; gap: 3px; }
.ds-batch { display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: center; font-size: 12px; padding: 5px 10px; border-radius: 6px; }
.ds-batch .num { text-align: right; min-width: 50px; }
.ds-batch-head { font-size: 10.5px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: .03em; background: transparent; padding-bottom: 2px; }
.ds-batchno { font-weight: 600; color: var(--td); }
.ds-batch.ds-exp-expired { background: #fee2e2; }
.ds-batch.ds-exp-soon { background: #fef3c7; }
.ds-batch.ds-exp-warn { background: #fef9c3; }
.ds-batch.ds-exp-ok, .ds-batch.ds-exp-none { background: var(--bc); }

/* Modal Tambah Stok */
.ds-modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.4); z-index: 100; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.ds-modal { background: var(--bc); border-radius: 14px; max-width: 460px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(15,23,42,.3); overflow: hidden; }
.ds-modal-head { padding: 14px 18px; border-bottom: 1px solid var(--gb); display: flex; justify-content: space-between; align-items: flex-start; background: var(--bs); }
.ds-modal-head strong { font-size: 15px; color: var(--td); }
.ds-modal-sub { font-size: 12px; color: var(--tm); margin-top: 2px; }
.ds-modal-close { width: 28px; height: 28px; border-radius: 6px; border: none; background: transparent; color: var(--tm); cursor: pointer; font-size: 14px; }
.ds-modal-close:hover { background: var(--gl); color: var(--ga); }
.ds-modal-body { padding: 16px 18px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }
.ds-modal-foot { padding: 12px 18px; border-top: 1px solid var(--gb); display: flex; gap: 8px; justify-content: flex-end; background: var(--bs); }

.ds-field { display: flex; flex-direction: column; gap: 5px; }
.ds-field > label { font-size: 12px; font-weight: 600; color: var(--tm); }
.ds-field > label .req { color: #dc2626; }
.ds-field input, .ds-field textarea { padding: 7px 9px; font-size: 13px; border: 1px solid var(--gb); border-radius: 7px; background: var(--bc); color: var(--td); font-family: inherit; resize: vertical; }
.ds-field input:focus, .ds-field textarea:focus { outline: none; border-color: var(--ga); }
.ds-field-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.ds-qty-row { display: flex; align-items: center; gap: 8px; }
.ds-qty-row input { flex: 1; }
.ds-unit { font-size: 12.5px; color: var(--tm); font-weight: 500; }
.ds-hint { font-size: 11.5px; color: var(--tu); }
.ds-modal-note { font-size: 11.5px; color: var(--tu); margin: 0; padding: 8px 10px; background: var(--bs); border-radius: 7px; border-left: 3px solid var(--ga); }

/* Toast */
.ds-toast { position: fixed; bottom: 24px; right: 24px; padding: 10px 16px; border-radius: 8px; font-size: 13px; color: white; box-shadow: 0 6px 20px rgba(15,23,42,.2); z-index: 110; max-width: 320px; }
.ds-toast.t-s { background: #15803d; }
.ds-toast.t-e { background: #b91c1c; }
</style>
