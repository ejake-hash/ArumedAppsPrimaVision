<script setup>
import { ref, watch, onMounted, computed } from 'vue'
import { inventoriStockApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'

const auth = useAuthStore()
const canWrite = computed(() => auth.can('inventori_farmasi.write'))

const STOCK_TABS = [
  { key: 'MEDICATION', label: 'Obat', csvType: 'obat', csvable: true },
  { key: 'BHP',        label: 'BHP',  csvType: 'bhp',  csvable: true },
  { key: 'IOL',        label: 'IOL',  csvType: null,   csvable: false },
]

// Lokasi stok: INVENTORI (gudang induk), BEDAH, FARMASI.
const STOCK_LOCATIONS = [
  { key: 'INVENTORI', label: 'Gudang' },
  { key: 'FARMASI',   label: 'Farmasi' },
  { key: 'BEDAH',     label: 'Bedah' },
]

const stockTab = ref('MEDICATION')
const stockLocation = ref('INVENTORI')
const stockSearch = ref('')
const stockList = ref([])
const stockLoading = ref(false)
const errorMsg = ref('')
let stockSearchTimer = null

const activeTab = computed(() => STOCK_TABS.find((t) => t.key === stockTab.value))
const activeLocationLabel = computed(() => STOCK_LOCATIONS.find((l) => l.key === stockLocation.value)?.label ?? stockLocation.value)

async function refreshStock() {
  stockLoading.value = true
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
    stockLoading.value = false
  }
}

function switchLocation(k) {
  if (stockLocation.value === k) return
  stockLocation.value = k
  refreshStock()
}

function switchStockTab(k) {
  if (stockTab.value === k) return
  stockTab.value = k
  stockSearch.value = ''
  refreshStock()
}

watch(stockSearch, () => {
  clearTimeout(stockSearchTimer)
  stockSearchTimer = setTimeout(refreshStock, 300)
})

const formatDate = (v) => v ? new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

function expiryClass(dateStr) {
  if (!dateStr) return 'iss-exp-none'
  const days = Math.ceil((new Date(dateStr).getTime() - Date.now()) / 86_400_000)
  if (days < 0)  return 'iss-exp-expired'
  if (days <= 30) return 'iss-exp-soon'
  if (days <= 90) return 'iss-exp-warn'
  return 'iss-exp-ok'
}

// ─── Toast (lightweight) ────────────────────────────────────────────────
const toast = ref(null)
function showToast(type, msg) {
  toast.value = { type, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

// ─── Opname modal ───────────────────────────────────────────────────────
const opnameOpen = ref(false)
const opnameItem = ref(null)   // { id, code, name, unit, batches:[{batch_no, expiry_date, qty}] }
const opnameForm = ref({ reason: '', batches: [], newBatches: [] })
const opnameSaving = ref(false)

function openOpname(row) {
  if (!canWrite.value) return
  if (stockTab.value === 'IOL') {
    showToast('e', 'Opname IOL belum didukung di rilis ini')
    return
  }
  opnameItem.value = row
  opnameForm.value = {
    reason: '',
    batches: (row.batches ?? []).map((b, i) => ({
      // Backend butuh stock_id untuk update batch existing. Sidebar tidak punya
      // stock_id (snapshot hanya kirim batch_no+expiry+qty). Workaround: kirim
      // batch_no+expiry, backend lookup; tapi karena service kita pakai stock_id,
      // di sini kita anggap "edit existing batch" = ubah qty pakai mode set-total
      // per delta (lihat catatan submit di bawah).
      idx: i,
      batch_no: b.batch_no ?? '',
      expiry_date: b.expiry_date ?? null,
      qty_current: Number(b.qty) || 0,
      qty_physical: Number(b.qty) || 0,
    })),
    newBatches: [],
  }
  opnameOpen.value = true
}

function closeOpname() {
  opnameOpen.value = false
  opnameItem.value = null
}

function addNewBatch() {
  opnameForm.value.newBatches.push({
    batch_no: '',
    expiry_date: '',
    qty_physical: 0,
  })
}

function removeNewBatch(idx) {
  opnameForm.value.newBatches.splice(idx, 1)
}

const opnameTotalNew = computed(() => {
  const a = opnameForm.value.batches.reduce((s, b) => s + (Number(b.qty_physical) || 0), 0)
  const b = opnameForm.value.newBatches.reduce((s, x) => s + (Number(x.qty_physical) || 0), 0)
  return a + b
})

const opnameTotalCurrent = computed(() =>
  opnameForm.value.batches.reduce((s, b) => s + (Number(b.qty_current) || 0), 0)
)

const opnameDelta = computed(() => opnameTotalNew.value - opnameTotalCurrent.value)

async function submitOpname() {
  if (!opnameItem.value || opnameSaving.value) return
  opnameSaving.value = true
  try {
    // Karena sidebar tidak punya stock_id per batch, kita pakai mode `new_qty`
    // (set total) — backend akan auto-adjust via delta FEFO/OPNAME batch.
    // Batch baru yang user tambahkan diakumulasi ke total target.
    await inventoriStockApi.opname({
      item_type: stockTab.value,
      item_id: opnameItem.value.id,
      location: stockLocation.value,
      new_qty: opnameTotalNew.value,
      reason: opnameForm.value.reason || 'Opname manual via sidebar',
    })
    showToast('s', 'Opname tersimpan')
    closeOpname()
    await refreshStock()
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan opname')
  } finally {
    opnameSaving.value = false
  }
}

// ─── CSV (template / export / import) ───────────────────────────────────
const csvOpen = ref(false)
const csvImporting = ref(false)
const csvResult = ref(null)
const csvFileInput = ref(null)

function toggleCsv() {
  csvOpen.value = !csvOpen.value
  csvResult.value = null
}

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

async function downloadTemplate() {
  if (!activeTab.value?.csvable) return
  try {
    const res = await inventoriStockApi.templateCsv(activeTab.value.csvType)
    downloadBlob(res.data, `template-stok-${activeTab.value.csvType}.csv`)
  } catch {
    showToast('e', 'Gagal mengunduh template')
  }
}

async function downloadExport() {
  if (!activeTab.value?.csvable) return
  try {
    const res = await inventoriStockApi.exportCsv(activeTab.value.csvType, stockLocation.value)
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '')
    downloadBlob(res.data, `stok-${activeTab.value.csvType}-${stockLocation.value}-${today}.csv`)
  } catch {
    showToast('e', 'Gagal mengekspor stok')
  }
}

function triggerImport() {
  csvFileInput.value?.click()
}

async function onCsvFile(e) {
  const file = e.target.files?.[0]
  if (!file || !activeTab.value?.csvable) return
  csvImporting.value = true
  csvResult.value = null
  try {
    const res = await inventoriStockApi.importCsv(activeTab.value.csvType, file, stockLocation.value)
    csvResult.value = res.data?.data ?? null
    showToast('s', res.data?.message ?? 'Import selesai')
    await refreshStock()
  } catch (err) {
    showToast('e', err.response?.data?.message ?? 'Gagal mengimport CSV')
  } finally {
    csvImporting.value = false
    if (csvFileInput.value) csvFileInput.value.value = ''
  }
}

onMounted(refreshStock)
</script>

<template>
  <div class="iss-card">
    <header class="iss-head">
      <h3>Data Stock</h3>
      <div class="iss-head-actions">
        <button v-if="activeTab?.csvable" class="iss-refresh" :class="{ active: csvOpen }" @click="toggleCsv" title="Template / Export / Import CSV">⇅</button>
        <button class="iss-refresh" :disabled="stockLoading" @click="refreshStock" title="Refresh">↻</button>
      </div>
    </header>

    <nav class="iss-tabs">
      <button
        v-for="t in STOCK_TABS" :key="t.key"
        class="iss-tab" :class="{ active: stockTab === t.key }"
        @click="switchStockTab(t.key)"
      >{{ t.label }}</button>
    </nav>

    <!-- Lokasi stok -->
    <div class="iss-loc">
      <span class="iss-loc-lbl">Lokasi</span>
      <div class="iss-loc-seg">
        <button
          v-for="l in STOCK_LOCATIONS" :key="l.key"
          class="iss-loc-btn" :class="{ active: stockLocation === l.key }"
          @click="switchLocation(l.key)"
        >{{ l.label }}</button>
      </div>
    </div>

    <!-- CSV toolbar (collapsible) -->
    <div v-if="csvOpen && activeTab?.csvable" class="iss-csv">
      <div class="iss-csv-row">
        <button class="iss-csv-btn" @click="downloadTemplate">📄 Template</button>
        <button class="iss-csv-btn" @click="downloadExport">⬇ Export</button>
        <button
          v-if="canWrite"
          class="iss-csv-btn primary"
          :disabled="csvImporting"
          @click="triggerImport"
        >{{ csvImporting ? '…' : '⬆ Import' }}</button>
      </div>
      <p class="iss-csv-hint">Kolom: <code>code, name, qty</code>. <code>code</code> opsional (fallback ke nama). Berlaku untuk lokasi <strong>{{ activeLocationLabel }}</strong>.</p>
      <div v-if="csvResult" class="iss-csv-result">
        <strong>{{ csvResult.applied }}</strong> item diopname,
        <strong>{{ csvResult.skipped }}</strong> dilewati.
        <details v-if="csvResult.errors?.length">
          <summary>{{ csvResult.errors.length }} error</summary>
          <ul><li v-for="(er, i) in csvResult.errors" :key="i">{{ er }}</li></ul>
        </details>
      </div>
      <input ref="csvFileInput" type="file" accept=".csv,text/csv" class="iss-file-hidden" @change="onCsvFile" />
    </div>

    <div class="iss-search">
      <input
        v-model="stockSearch"
        class="iss-inp"
        :placeholder="`Cari ${activeTab?.label.toLowerCase()}…`"
      />
    </div>

    <div class="iss-list">
      <div v-if="stockLoading" class="iss-state">Memuat…</div>
      <div v-else-if="errorMsg" class="iss-state iss-err">{{ errorMsg }}</div>
      <div v-else-if="!stockList.length" class="iss-state">
        {{ stockSearch ? 'Tidak ada hasil' : 'Belum ada data stok' }}
      </div>
      <div v-for="row in stockList" :key="row.id" class="iss-row">
        <div class="iss-rowhead">
          <div class="iss-name">
            <span class="iss-code">{{ row.code }}</span>
            <span>{{ row.name }}</span>
          </div>
          <div class="iss-rowright">
            <div class="iss-qty">{{ row.total_qty }} <small>{{ row.unit }}</small></div>
            <button
              v-if="canWrite && stockTab !== 'IOL'"
              class="iss-edit"
              title="Opname stok"
              @click="openOpname(row)"
            >✎</button>
          </div>
        </div>
        <div v-if="row.batches?.length" class="iss-batches">
          <div
            v-for="(b, i) in row.batches" :key="i"
            class="iss-batch" :class="expiryClass(b.expiry_date)"
          >
            <span class="iss-batchno">{{ b.batch_no || '—' }}</span>
            <span class="iss-batchexp">exp {{ formatDate(b.expiry_date) }}</span>
            <span class="iss-batchqty">{{ b.qty }}</span>
          </div>
        </div>
        <div v-else class="iss-nobatch">Belum ada batch terdaftar</div>
      </div>
    </div>

    <!-- Opname modal -->
    <Teleport to="body">
      <div v-if="opnameOpen" class="iss-modal-backdrop" @click.self="closeOpname">
        <div class="iss-modal">
          <header class="iss-modal-head">
            <div>
              <strong>Opname Stok</strong>
              <div class="iss-modal-sub">{{ opnameItem?.code }} · {{ opnameItem?.name }}</div>
            </div>
            <button class="iss-modal-close" @click="closeOpname">✕</button>
          </header>

          <div class="iss-modal-body">
            <table class="iss-opn-table">
              <thead>
                <tr><th>Batch</th><th>Kadaluwarsa</th><th class="num">Sistem</th><th class="num">Fisik</th></tr>
              </thead>
              <tbody>
                <tr v-for="b in opnameForm.batches" :key="b.idx">
                  <td><code>{{ b.batch_no || '—' }}</code></td>
                  <td>{{ formatDate(b.expiry_date) }}</td>
                  <td class="num">{{ b.qty_current }}</td>
                  <td class="num"><input type="number" min="0" step="0.01" v-model.number="b.qty_physical" /></td>
                </tr>
                <tr v-for="(b, i) in opnameForm.newBatches" :key="`new-${i}`" class="new-row">
                  <td><input type="text" v-model="b.batch_no" :placeholder="`OPNAME-${new Date().toISOString().slice(0,10)}`" /></td>
                  <td><input type="date" v-model="b.expiry_date" /></td>
                  <td class="num">—</td>
                  <td class="num">
                    <input type="number" min="0" step="0.01" v-model.number="b.qty_physical" />
                    <button class="iss-opn-x" @click="removeNewBatch(i)" title="Hapus">✕</button>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="2"><button class="iss-opn-add" @click="addNewBatch">+ Batch baru</button></td>
                  <td class="num"><strong>{{ opnameTotalCurrent }}</strong></td>
                  <td class="num">
                    <strong>{{ opnameTotalNew }}</strong>
                    <span class="iss-opn-delta" :class="opnameDelta === 0 ? '' : (opnameDelta > 0 ? 'pos' : 'neg')">
                      ({{ opnameDelta > 0 ? '+' : '' }}{{ opnameDelta }})
                    </span>
                  </td>
                </tr>
              </tfoot>
            </table>

            <label class="iss-opn-reason">
              <span>Alasan</span>
              <textarea v-model="opnameForm.reason" rows="2" placeholder="mis. Opname bulanan, koreksi salah catat, expired disposal"></textarea>
            </label>

            <p class="iss-opn-note">
              Sistem akan menerapkan selisih: positif → batch baru <code>OPNAME-{tgl}</code>,
              negatif → kurangi batch existing secara FEFO.
            </p>
          </div>

          <footer class="iss-modal-foot">
            <button class="iss-btn-ghost" @click="closeOpname" :disabled="opnameSaving">Batal</button>
            <button class="iss-btn-primary" @click="submitOpname" :disabled="opnameSaving || opnameDelta === 0 && !opnameForm.reason">
              {{ opnameSaving ? 'Menyimpan…' : 'Simpan opname' }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <!-- Toast -->
    <Teleport to="body">
      <div v-if="toast" class="iss-toast" :class="`t-${toast.type}`">{{ toast.msg }}</div>
    </Teleport>
  </div>
</template>

<style scoped>
.iss-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; max-height: calc(100vh - 90px); position: sticky; top: 1.5rem; }
.iss-head { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid var(--gb); background: var(--bs); }
.iss-head h3 { font-family: 'Space Grotesk', serif; font-size: 15px; color: var(--td); margin: 0; }
.iss-head-actions { display: flex; gap: 4px; }
.iss-refresh { width: 26px; height: 26px; border-radius: 6px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); cursor: pointer; font-size: 14px; line-height: 1; }
.iss-refresh:hover:not(:disabled) { background: var(--bs); color: var(--ga); }
.iss-refresh.active { background: var(--gl); color: var(--ga); border-color: var(--ga); }
.iss-refresh:disabled { opacity: .4; cursor: not-allowed; }

.iss-tabs { display: flex; padding: 8px 10px 0; gap: 4px; border-bottom: 1px solid var(--gb); }
.iss-tab { flex: 1; padding: 6px 8px; font-size: 12px; font-weight: 600; color: var(--tm); background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -1px; cursor: pointer; }
.iss-tab:hover { color: var(--td); }
.iss-tab.active { color: var(--ga); border-bottom-color: var(--ga); }

.iss-loc { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-bottom: 1px solid var(--gb); background: var(--bs); }
.iss-loc-lbl { font-size: 11px; font-weight: 600; color: var(--tm); }
.iss-loc-seg { display: flex; flex: 1; gap: 4px; }
.iss-loc-btn { flex: 1; padding: 4px 6px; font-size: 11px; font-weight: 600; color: var(--tm); background: var(--bc); border: 1px solid var(--gb); border-radius: 6px; cursor: pointer; }
.iss-loc-btn:hover { background: var(--gl); color: var(--td); }
.iss-loc-btn.active { background: var(--ga); color: #fff; border-color: var(--ga); }

.iss-csv { padding: 10px 12px; border-bottom: 1px solid var(--gb); background: var(--bs); }
.iss-csv-row { display: flex; gap: 6px; }
.iss-csv-btn { flex: 1; padding: 6px 8px; font-size: 11.5px; border-radius: 6px; border: 1px solid var(--gb); background: var(--bc); color: var(--td); cursor: pointer; }
.iss-csv-btn:hover:not(:disabled) { background: var(--gl); color: var(--ga); border-color: var(--ga); }
.iss-csv-btn.primary { background: var(--ga); color: white; border-color: var(--ga); }
.iss-csv-btn.primary:hover { filter: brightness(.95); }
.iss-csv-btn:disabled { opacity: .5; cursor: not-allowed; }
.iss-csv-hint { font-size: 10.5px; color: var(--tu); margin: 6px 0 0; }
.iss-csv-hint code { background: var(--bc); padding: 1px 4px; border-radius: 3px; font-size: 10px; }
.iss-csv-result { font-size: 11px; margin-top: 6px; padding: 6px 8px; background: var(--bc); border-radius: 6px; border: 1px solid var(--gb); }
.iss-csv-result details { margin-top: 4px; }
.iss-csv-result summary { cursor: pointer; color: var(--tm); }
.iss-csv-result ul { margin: 4px 0 0 16px; padding: 0; color: #b91c1c; }
.iss-file-hidden { display: none; }

.iss-search { padding: 10px 12px 6px; }
.iss-inp { padding: 5px 7px; font-size: 12px; width: 100%; border: 1px solid var(--gb); border-radius: 6px; background: var(--bc); color: var(--td); }
.iss-inp:focus { outline: none; border-color: var(--ga); }

.iss-list { padding: 4px 12px 12px; overflow-y: auto; flex: 1; }
.iss-state { text-align: center; color: var(--tu); font-size: 12px; padding: 24px 0; }
.iss-err { color: #dc2626; }

.iss-row { padding: 8px 0; border-bottom: 1px solid var(--gb); }
.iss-row:last-child { border-bottom: none; }
.iss-rowhead { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.iss-name { font-size: 12.5px; color: var(--td); display: flex; flex-direction: column; gap: 1px; flex: 1; min-width: 0; }
.iss-code { font-size: 10.5px; color: var(--tu); font-weight: 600; letter-spacing: .03em; }
.iss-rowright { display: flex; align-items: center; gap: 6px; }
.iss-qty { font-size: 13px; font-weight: 700; color: var(--td); white-space: nowrap; }
.iss-qty small { font-size: 10px; color: var(--tu); font-weight: 500; margin-left: 2px; }
.iss-edit { width: 22px; height: 22px; border-radius: 5px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); cursor: pointer; font-size: 11px; }
.iss-edit:hover { background: var(--gl); color: var(--ga); border-color: var(--ga); }

.iss-batches { display: flex; flex-direction: column; gap: 3px; margin-top: 5px; padding-left: 6px; }
.iss-batch { display: grid; grid-template-columns: 1fr auto auto; gap: 8px; align-items: center; font-size: 10.5px; padding: 3px 7px; border-radius: 4px; }
.iss-batchno { color: var(--tm); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.iss-batchexp { color: var(--tu); }
.iss-batchqty { color: var(--td); font-weight: 700; min-width: 28px; text-align: right; }
.iss-nobatch { font-size: 10.5px; color: var(--tu); font-style: italic; margin-top: 4px; padding-left: 6px; }

.iss-exp-expired { background: #fee2e2; }
.iss-exp-expired .iss-batchexp { color: #991b1b; font-weight: 600; }
.iss-exp-soon    { background: #fef3c7; }
.iss-exp-soon    .iss-batchexp { color: #92400e; font-weight: 600; }
.iss-exp-warn    { background: #fef9c3; }
.iss-exp-ok      { background: var(--bs); }
.iss-exp-none    { background: var(--bs); }

/* Modal */
.iss-modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.4); z-index: 100; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.iss-modal { background: var(--bc); border-radius: 14px; max-width: 640px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(15,23,42,.3); overflow: hidden; }
.iss-modal-head { padding: 14px 18px; border-bottom: 1px solid var(--gb); display: flex; justify-content: space-between; align-items: flex-start; background: var(--bs); }
.iss-modal-head strong { font-size: 15px; color: var(--td); }
.iss-modal-sub { font-size: 12px; color: var(--tm); margin-top: 2px; }
.iss-modal-close { width: 28px; height: 28px; border-radius: 6px; border: none; background: transparent; color: var(--tm); cursor: pointer; font-size: 14px; }
.iss-modal-close:hover { background: var(--gl); color: var(--ga); }
.iss-modal-body { padding: 16px 18px; overflow-y: auto; flex: 1; }
.iss-modal-foot { padding: 12px 18px; border-top: 1px solid var(--gb); display: flex; gap: 8px; justify-content: flex-end; background: var(--bs); }

.iss-opn-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.iss-opn-table th, .iss-opn-table td { padding: 6px 8px; border-bottom: 1px solid var(--gb); text-align: left; }
.iss-opn-table th { background: var(--bs); font-size: 11px; color: var(--tm); font-weight: 600; }
.iss-opn-table .num { text-align: right; }
.iss-opn-table input[type=number], .iss-opn-table input[type=text], .iss-opn-table input[type=date] { width: 100%; padding: 4px 6px; font-size: 12.5px; border: 1px solid var(--gb); border-radius: 5px; background: var(--bc); }
.iss-opn-table .num input { text-align: right; }
.iss-opn-table tfoot td { background: var(--bs); font-weight: 600; border-bottom: none; }
.iss-opn-table .new-row td { background: #f0fdf4; }
.iss-opn-add { font-size: 11.5px; color: var(--ga); background: none; border: 1px dashed var(--ga); border-radius: 5px; padding: 3px 8px; cursor: pointer; }
.iss-opn-add:hover { background: var(--gl); }
.iss-opn-x { width: 20px; height: 20px; border: none; background: transparent; color: #dc2626; cursor: pointer; font-size: 11px; margin-left: 4px; }
.iss-opn-delta { font-size: 11px; margin-left: 4px; }
.iss-opn-delta.pos { color: #15803d; }
.iss-opn-delta.neg { color: #b91c1c; }

.iss-opn-reason { display: flex; flex-direction: column; gap: 4px; margin-top: 14px; font-size: 12px; color: var(--tm); }
.iss-opn-reason textarea { padding: 6px 8px; font-size: 12.5px; border: 1px solid var(--gb); border-radius: 6px; background: var(--bc); color: var(--td); font-family: inherit; resize: vertical; }
.iss-opn-note { font-size: 11px; color: var(--tu); margin: 10px 0 0; padding: 8px 10px; background: var(--bs); border-radius: 6px; border-left: 3px solid var(--ga); }
.iss-opn-note code { background: var(--bc); padding: 1px 4px; border-radius: 3px; font-size: 10.5px; }

.iss-btn-ghost { padding: 7px 14px; border: 1px solid var(--gb); background: var(--bc); border-radius: 7px; cursor: pointer; font-size: 13px; color: var(--tm); }
.iss-btn-ghost:hover:not(:disabled) { background: var(--bs); }
.iss-btn-primary { padding: 7px 14px; border: none; background: var(--ga); color: white; border-radius: 7px; cursor: pointer; font-size: 13px; font-weight: 500; }
.iss-btn-primary:hover:not(:disabled) { filter: brightness(.95); }
.iss-btn-primary:disabled, .iss-btn-ghost:disabled { opacity: .5; cursor: not-allowed; }

/* Toast */
.iss-toast { position: fixed; bottom: 24px; right: 24px; padding: 10px 16px; border-radius: 8px; font-size: 13px; color: white; box-shadow: 0 6px 20px rgba(15,23,42,.2); z-index: 110; max-width: 320px; }
.iss-toast.t-s { background: #15803d; }
.iss-toast.t-e { background: #b91c1c; }
</style>
