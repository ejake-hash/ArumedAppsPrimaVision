<script setup>
/**
 * PenerimaanView — manajemen Goods Receipt (GRN).
 *
 * 2 mode terima:
 *   1. Dari PO existing — pilih PO, auto-fill items dengan qty_remaining
 *   2. Direct receipt (no PO) — manual item picker (cash & carry)
 *
 * Setiap baris item butuh: qty_received, batch_no, expiry_date (wajib > today).
 */
import { ref, computed, onMounted, watch } from 'vue'
import { penerimaanApi, pembelianApi, masterApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'
import ScanBarcodeModal from '@/components/common/ScanBarcodeModal.vue'

const auth = useAuthStore()

// ─── State list ─────────────────────────────────────────────────────────
const items = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 })
const loading = ref(false)
const error = ref(null)

const filters = ref({ search: '', supplier_id: '', date_from: '', date_to: '' })
const suppliers = ref([])

const formatRp = (v) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID')
const formatDate = (v) => v ? new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

// Tanggal kalender LOKAL (WIB) 'YYYY-MM-DD'. JANGAN pakai toISOString().slice(0,10):
// itu tanggal UTC → di WIB jam 00:00–07:00 jadi mundur 1 hari (GRN salah tanggal,
// minExpiry off-by-one). Lihat memory feedback-timezone-wib.
function localDateStr(d = new Date()) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

// ─── Toast ──────────────────────────────────────────────────────────────
const toast = ref(null)
function showToast(type, msg) {
  toast.value = { type, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

// ─── Fetch list ─────────────────────────────────────────────────────────
let searchDebounce = null

async function refresh(page = 1) {
  loading.value = true
  error.value = null
  try {
    const params = { page, per_page: 25 }
    if (filters.value.search) params.search = filters.value.search
    if (filters.value.supplier_id) params.supplier_id = filters.value.supplier_id
    if (filters.value.date_from) params.date_from = filters.value.date_from
    if (filters.value.date_to) params.date_to = filters.value.date_to
    const res = await penerimaanApi.list(params)
    const payload = res.data?.data
    if (payload && Array.isArray(payload.data)) {
      items.value = payload.data
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
    error.value = e.response?.data?.message ?? 'Gagal memuat penerimaan'
    showToast('e', error.value)
  } finally {
    loading.value = false
  }
}

async function fetchSuppliers() {
  try {
    const res = await masterApi.supplier.list({ per_page: 200, active: 1 })
    suppliers.value = res.data?.data?.data ?? []
  } catch (e) {
    showToast('e', 'Gagal memuat supplier')
  }
}

function onFilterChange() {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => refresh(1), 250)
}

// ─── Modal: choose mode (PO vs Direct) ──────────────────────────────────
const modeModal = ref({ open: false })
function openModeSelect() { modeModal.value.open = true }
function closeModeSelect() { modeModal.value.open = false }

// ─── Modal: pick PO list ────────────────────────────────────────────────
const poPicker = ref({ open: false, pos: [], loading: false, search: '' })
let poPickerDebounce = null

async function openPoPicker() {
  closeModeSelect()
  poPicker.value = { open: true, pos: [], loading: true, search: '' }
  await loadPoList()
}

async function loadPoList() {
  poPicker.value.loading = true
  try {
    const params = { per_page: 30 }
    if (poPicker.value.search) params.search = poPicker.value.search
    // Hanya PO yang bisa diterima: SENT atau PARTIAL
    const res = await pembelianApi.list(params)
    const all = res.data?.data?.data ?? []
    poPicker.value.pos = all.filter((po) => ['SENT', 'PARTIAL', 'DRAFT'].includes(po.status))
  } catch (e) {
    showToast('e', 'Gagal memuat daftar PO')
  } finally {
    poPicker.value.loading = false
  }
}

function onPoSearchChange() {
  clearTimeout(poPickerDebounce)
  poPickerDebounce = setTimeout(loadPoList, 250)
}

function closePoPicker() { poPicker.value.open = false }

// ─── Modal: receipt form (create) ──────────────────────────────────────
const receipt = ref({
  open: false,
  mode: 'po', // 'po' | 'direct' | 'view'
  submitting: false,
  fromPo: null, // { po_number, po_id }
  form: blankReceipt(),
})

function blankReceipt() {
  return {
    id: null,
    grn_number: '',
    po_id: null,
    supplier_id: '',
    receipt_date: localDateStr(),
    invoice_number: '',
    notes: '',
    total_amount: 0,
    items: [],
  }
}

async function startReceiptFromPo(po) {
  closePoPicker()
  receipt.value = { open: true, mode: 'po', submitting: false, fromPo: { po_id: po.id, po_number: po.po_number }, form: blankReceipt() }
  try {
    const res = await penerimaanApi.prepareFromPo(po.id)
    const data = res.data?.data
    receipt.value.form.po_id = data.po_id
    receipt.value.form.supplier_id = data.supplier_id
    receipt.value.form.items = data.items
      .filter((it) => it.qty_remaining > 0)
      .map((it) => ({
        _key: crypto.randomUUID(),
        po_item_id: it.po_item_id,
        item_type: it.item_type,
        item_id: it.item_id,
        item_code: it.item_code,
        item_name: it.item_name,
        item_unit: it.item_unit,
        qty_remaining: it.qty_remaining,
        qty_received: it.qty_remaining, // default = sisa penuh
        batch_no: '',
        expiry_date: '',
        unit_price: it.unit_price,
        subtotal: it.qty_remaining * it.unit_price,
        notes: '',
        _include: true, // checkbox apakah baris ini diterima
      }))
    if (receipt.value.form.items.length === 0) {
      showToast('w', 'PO ini sudah diterima semua.')
      closeReceipt()
    }
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal memuat detail PO')
    closeReceipt()
  }
}

function startReceiptDirect() {
  closeModeSelect()
  receipt.value = { open: true, mode: 'direct', submitting: false, fromPo: null, form: blankReceipt() }
}

async function openReceiptView(row) {
  receipt.value = { open: true, mode: 'view', submitting: false, fromPo: null, form: blankReceipt() }
  try {
    const res = await penerimaanApi.show(row.id)
    const grn = res.data?.data
    receipt.value.form = {
      id: grn.id,
      grn_number: grn.grn_number,
      po_id: grn.po_id,
      po_number: grn.po_number,
      supplier_id: grn.supplier_id,
      receipt_date: grn.receipt_date,
      invoice_number: grn.invoice_number ?? '',
      notes: grn.notes ?? '',
      total_amount: grn.total_amount,
      items: grn.items.map((it) => ({ ...it, _key: crypto.randomUUID() })),
    }
  } catch (e) {
    showToast('e', 'Gagal memuat detail GRN')
    closeReceipt()
  }
}

function closeReceipt() {
  receipt.value.open = false
}

// ─── Direct mode: line items ────────────────────────────────────────────
function addDirectLine() {
  receipt.value.form.items.push({
    _key: crypto.randomUUID(),
    po_item_id: null,
    item_type: 'MEDICATION',
    item_id: '',
    item_code: '',
    item_name: '',
    item_unit: '',
    qty_received: 1,
    batch_no: '',
    expiry_date: '',
    unit_price: 0,
    subtotal: 0,
    notes: '',
    _include: true,
  })
}

function removeLine(idx) {
  receipt.value.form.items.splice(idx, 1)
  recalcTotal()
}

function recalcLine(line) {
  line.subtotal = Math.round(Number(line.qty_received || 0) * Number(line.unit_price || 0) * 100) / 100
  recalcTotal()
}

function recalcTotal() {
  receipt.value.form.total_amount = receipt.value.form.items
    .filter((it) => it._include !== false)
    .reduce((sum, it) => sum + Number(it.subtotal || 0), 0)
}

// ─── Item picker (untuk direct mode) ────────────────────────────────────
const itemPicker = ref({ open: false, lineKey: null, type: 'MEDICATION', search: '', results: [], loading: false })
let pickerDebounce = null

function openItemPicker(line) {
  itemPicker.value = { open: true, lineKey: line._key, type: line.item_type || 'MEDICATION', search: '', results: [], loading: false }
  searchItems()
}

function closeItemPicker() { itemPicker.value.open = false }

async function searchItems() {
  clearTimeout(pickerDebounce)
  pickerDebounce = setTimeout(async () => {
    itemPicker.value.loading = true
    try {
      const params = { per_page: 20 }
      if (itemPicker.value.search) params.search = itemPicker.value.search
      const apiMap = { MEDICATION: masterApi.obat, BHP: masterApi.bhp, IOL: masterApi.iol }
      const res = await apiMap[itemPicker.value.type].list(params)
      itemPicker.value.results = res.data?.data?.data ?? []
    } catch (e) {
      showToast('e', 'Gagal mencari item')
    } finally {
      itemPicker.value.loading = false
    }
  }, 200)
}

function selectItem(masterRow) {
  const line = receipt.value.form.items.find((it) => it._key === itemPicker.value.lineKey)
  if (!line) return
  line.item_type = itemPicker.value.type
  line.item_id = masterRow.id
  line.item_code = masterRow.code ?? ''
  line.item_name = masterRow.name ?? masterRow.brand ?? '-'
  line.item_unit = masterRow.unit_kecil ?? masterRow.unit ?? ''
  recalcLine(line)
  closeItemPicker()
}

function displayMasterName(row) {
  if (itemPicker.value.type === 'IOL') {
    return `${row.brand ?? ''} ${row.model ?? ''}${row.power != null ? ' (' + row.power + 'D)' : ''}`.trim()
  }
  return row.name ?? '-'
}

function displayMasterSub(row) {
  if (itemPicker.value.type === 'MEDICATION') {
    return [row.generic_name, row.form_sediaan].filter(Boolean).join(' · ')
  }
  if (itemPicker.value.type === 'BHP') return row.category ?? ''
  if (itemPicker.value.type === 'IOL') return row.serial_number ? `SN: ${row.serial_number}` : ''
  return ''
}

watch(() => itemPicker.value.type, searchItems)

// ─── Scan GTIN IOL (DataMatrix/UDI) ─────────────────────────────────────
const scan = ref({ open: false, lineKey: null, busy: false })

function openScan(line) {
  scan.value = { open: true, lineKey: line._key, busy: false }
}

async function onScanDecoded(rawCode) {
  const line = receipt.value.form.items.find((it) => it._key === scan.value.lineKey)
  if (!line) return
  scan.value.busy = true
  try {
    const res = await masterApi.iol.scan(rawCode)
    const d = res.data?.data ?? {}
    const p = d.parsed ?? {}

    // Lot & expiry dari hasil parse → auto-isi batch_no & expiry_date.
    if (p.lot_number) line.batch_no = p.lot_number
    if (p.expiry_date) line.expiry_date = p.expiry_date

    if (d.matched && d.iol_item) {
      const m = d.iol_item
      line.item_type = 'IOL'
      line.item_id = m.id
      line.item_code = m.code ?? m.gtin ?? ''
      line.item_name = `${m.brand ?? ''} ${m.model ?? ''}${m.power != null ? ' (' + m.power + 'D)' : ''}`.trim()
      line.item_unit = 'pcs'
      recalcLine(line)
      showToast('s', `IOL cocok: ${line.item_name}`)
    } else {
      // Belum terdaftar → arahkan user pilih/buat master. Batch & expiry sudah terisi.
      showToast('w', `GTIN ${p.gtin ?? '-'} belum terdaftar di master IOL. Pilih item manual atau tambahkan dulu.`)
    }
    if (Array.isArray(p.errors) && p.errors.length) {
      showToast('w', 'Catatan scan: ' + p.errors.join('; '))
    }
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal memproses barcode')
  } finally {
    scan.value.busy = false
    scan.value.open = false
  }
}

// ─── Submit ─────────────────────────────────────────────────────────────
function todayPlus1() {
  const d = new Date(); d.setDate(d.getDate() + 1)
  return localDateStr(d)
}

async function submit() {
  const minExpiry = todayPlus1()
  const included = receipt.value.form.items.filter((it) => it._include !== false)

  if (included.length === 0) {
    showToast('w', 'Pilih minimal 1 item untuk diterima.')
    return
  }
  if (!receipt.value.form.supplier_id) {
    showToast('w', 'Supplier wajib dipilih.')
    return
  }
  for (const [idx, it] of included.entries()) {
    if (!it.item_id) { showToast('w', `Baris #${idx + 1}: item belum dipilih`); return }
    if (Number(it.qty_received) <= 0) { showToast('w', `Baris #${idx + 1}: qty harus > 0`); return }
    if (receipt.value.mode === 'po' && Number(it.qty_received) > Number(it.qty_remaining) + 0.001) {
      showToast('w', `Baris #${idx + 1}: qty melebihi sisa PO (${it.qty_remaining})`); return
    }
    if (!it.expiry_date) { showToast('w', `Baris #${idx + 1}: tanggal expiry wajib diisi`); return }
    if (it.expiry_date < minExpiry) { showToast('w', `Baris #${idx + 1}: expiry harus setelah hari ini`); return }
  }

  receipt.value.submitting = true
  try {
    const payload = {
      po_id: receipt.value.form.po_id || null,
      supplier_id: receipt.value.form.supplier_id,
      receipt_date: receipt.value.form.receipt_date,
      invoice_number: receipt.value.form.invoice_number || null,
      notes: receipt.value.form.notes || null,
      items: included.map((it) => ({
        po_item_id: it.po_item_id || null,
        item_type: it.item_type,
        item_id: it.item_id,
        qty_received: Number(it.qty_received),
        batch_no: it.batch_no || null,
        expiry_date: it.expiry_date,
        unit_price: Number(it.unit_price || 0),
        notes: it.notes || null,
      })),
    }
    await penerimaanApi.create(payload)
    showToast('s', 'Penerimaan berhasil dicatat')
    closeReceipt()
    refresh(1)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal mencatat penerimaan')
  } finally {
    receipt.value.submitting = false
  }
}

// ─── Delete ─────────────────────────────────────────────────────────────
const confirmDelete = ref({ open: false, row: null, busy: false })
function askDelete(row) { confirmDelete.value = { open: true, row, busy: false } }
async function doDelete() {
  confirmDelete.value.busy = true
  try {
    await penerimaanApi.remove(confirmDelete.value.row.id)
    showToast('s', 'Penerimaan dihapus & stok dikembalikan')
    confirmDelete.value.open = false
    refresh(meta.value.current_page)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus')
  } finally {
    confirmDelete.value.busy = false
  }
}

// ─── Init ───────────────────────────────────────────────────────────────
onMounted(() => {
  refresh()
  fetchSuppliers()
})

const canWrite = computed(() => auth.can('inventori_farmasi.write'))
const canDelete = computed(() => auth.can('inventori_farmasi.delete'))
</script>

<template>
  <div class="grn-wrap">
    <div class="grn-section-head">
      <div>
        <h2>Penerimaan Barang</h2>
        <p>Catat barang masuk dari supplier. Stok bertambah otomatis per batch.</p>
      </div>
      <button v-if="canWrite" class="grn-btn-primary" @click="openModeSelect">
        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Catat Penerimaan
      </button>
    </div>

    <div class="grn-filter-bar">
      <input type="text" v-model="filters.search" @input="onFilterChange" placeholder="Cari no. GRN / PO / supplier / invoice…" class="grn-search" />

      <span class="grn-filter-label">Supplier:</span>
      <select v-model="filters.supplier_id" @change="onFilterChange" class="grn-filter-select">
        <option value="">Semua</option>
        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} · {{ s.name }}</option>
      </select>

      <span class="grn-filter-label">Tanggal:</span>
      <input type="date" v-model="filters.date_from" @change="onFilterChange" class="grn-filter-select" />
      <span style="color:var(--tu);font-size:11px">s/d</span>
      <input type="date" v-model="filters.date_to" @change="onFilterChange" class="grn-filter-select" />
    </div>

    <div class="grn-table-wrap">
      <table class="grn-table">
        <thead>
          <tr>
            <th class="c" style="width:48px">No</th>
            <th>No. GRN</th>
            <th>Tanggal</th>
            <th>Supplier</th>
            <th>PO Terkait</th>
            <th>Invoice</th>
            <th class="r">Total</th>
            <th class="c">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading"><td colspan="8" class="grn-state">Memuat…</td></tr>
          <tr v-else-if="error"><td colspan="8" class="grn-state grn-state-error">{{ error }}</td></tr>
          <tr v-else-if="items.length === 0"><td colspan="8" class="grn-state">Belum ada penerimaan.</td></tr>
          <tr v-for="(grn, idx) in items" :key="grn.id" v-else>
            <td class="c grn-rownum">{{ (meta.current_page - 1) * meta.per_page + idx + 1 }}</td>
            <td><strong>{{ grn.grn_number }}</strong></td>
            <td>{{ formatDate(grn.receipt_date) }}</td>
            <td>{{ grn.supplier?.name ?? '—' }}</td>
            <td>
              <span v-if="grn.purchase_order" class="grn-po-link">{{ grn.purchase_order.po_number }}</span>
              <span v-else class="grn-direct-tag">Direct</span>
            </td>
            <td>{{ grn.invoice_number ?? '—' }}</td>
            <td class="r">{{ formatRp(grn.total_amount) }}</td>
            <td class="c grn-actions-cell">
              <button class="grn-icon-btn" title="Lihat detail" @click="openReceiptView(grn)">
                <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
              <button v-if="canDelete" class="grn-icon-btn grn-icon-danger" title="Hapus (stok dikembalikan)" @click="askDelete(grn)">
                <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="meta.last_page > 1" class="grn-pagination">
      <button :disabled="meta.current_page <= 1" @click="refresh(meta.current_page - 1)">‹ Sebelumnya</button>
      <span>Halaman {{ meta.current_page }} / {{ meta.last_page }} · {{ meta.total }} GRN</span>
      <button :disabled="meta.current_page >= meta.last_page" @click="refresh(meta.current_page + 1)">Berikutnya ›</button>
    </div>

    <!-- Modal: pilih mode terima -->
    <Teleport to="body">
      <div v-if="modeModal.open" class="grn-modal-overlay" @click.self="closeModeSelect">
        <div class="grn-mode-modal">
          <div class="grn-modal-head">
            <h3>Pilih Cara Penerimaan</h3>
            <button class="grn-close" @click="closeModeSelect">×</button>
          </div>
          <div class="grn-mode-options">
            <button class="grn-mode-card" @click="openPoPicker">
              <svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
              <div>
                <strong>Dari PO</strong>
                <p>Pilih PO yang sudah dikirim, sistem auto-fill daftar item & sisa qty.</p>
              </div>
            </button>
            <button class="grn-mode-card" @click="startReceiptDirect">
              <svg viewBox="0 0 24 24"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
              <div>
                <strong>Direct (tanpa PO)</strong>
                <p>Catat barang masuk manual — cocok untuk cash & carry / pembelian darurat.</p>
              </div>
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Modal: pilih PO -->
    <Teleport to="body">
      <div v-if="poPicker.open" class="grn-modal-overlay" @click.self="closePoPicker">
        <div class="grn-po-picker-modal">
          <div class="grn-modal-head">
            <h3>Pilih PO untuk Diterima</h3>
            <button class="grn-close" @click="closePoPicker">×</button>
          </div>
          <input
            type="text"
            v-model="poPicker.search"
            @input="onPoSearchChange"
            placeholder="Cari no. PO atau supplier…"
            class="grn-po-search"
            autofocus
          />
          <div class="grn-po-list">
            <div v-if="poPicker.loading" class="grn-state">Memuat…</div>
            <div v-else-if="poPicker.pos.length === 0" class="grn-state">
              Tidak ada PO yang bisa diterima. PO harus berstatus Draft/Dikirim/Sebagian.
            </div>
            <button v-for="po in poPicker.pos" :key="po.id" class="grn-po-item" @click="startReceiptFromPo(po)">
              <div class="grn-po-item-main">
                <strong>{{ po.po_number }}</strong>
                <span class="grn-po-status" :class="`status-${po.status.toLowerCase()}`">{{ po.status }}</span>
              </div>
              <div class="grn-po-item-sub">
                {{ po.supplier?.name ?? '—' }} · {{ formatDate(po.po_date) }} · {{ formatRp(po.total_amount) }}
              </div>
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Modal: receipt form -->
    <Teleport to="body">
      <div v-if="receipt.open" class="grn-modal-overlay" @click.self="closeReceipt">
        <div class="grn-modal">
          <div class="grn-modal-head">
            <h3>
              <span v-if="receipt.mode === 'view'">Detail Penerimaan — {{ receipt.form.grn_number }}</span>
              <span v-else-if="receipt.mode === 'po'">Terima dari PO — {{ receipt.fromPo?.po_number }}</span>
              <span v-else>Penerimaan Direct (tanpa PO)</span>
            </h3>
            <button class="grn-close" @click="closeReceipt">×</button>
          </div>

          <div class="grn-modal-body">
            <div class="grn-form-grid">
              <div class="grn-field">
                <label>Supplier <span class="req">*</span></label>
                <select v-model="receipt.form.supplier_id" :disabled="receipt.mode !== 'direct'">
                  <option value="">— Pilih supplier —</option>
                  <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} · {{ s.name }}</option>
                </select>
              </div>
              <div class="grn-field">
                <label>Tanggal Penerimaan <span class="req">*</span></label>
                <input type="date" v-model="receipt.form.receipt_date" :disabled="receipt.mode === 'view'" />
              </div>
              <div class="grn-field">
                <label>No. Invoice / Faktur</label>
                <input type="text" v-model="receipt.form.invoice_number" :disabled="receipt.mode === 'view'" placeholder="INV-2026-001" />
              </div>
              <div class="grn-field">
                <label>PO Terkait</label>
                <input type="text" :value="receipt.fromPo?.po_number || receipt.form.po_number || '— Direct —'" disabled />
              </div>
              <div class="grn-field grn-field-full">
                <label>Catatan</label>
                <textarea v-model="receipt.form.notes" :disabled="receipt.mode === 'view'" rows="2"></textarea>
              </div>
            </div>

            <div class="grn-items-head">
              <h4>Item Diterima</h4>
              <button v-if="receipt.mode === 'direct'" class="grn-btn-secondary grn-btn-sm" @click="addDirectLine">+ Tambah Baris</button>
            </div>

            <table class="grn-items-table">
              <thead>
                <tr>
                  <th v-if="receipt.mode === 'po'" style="width:36px" class="c">✓</th>
                  <th style="width:80px">Tipe</th>
                  <th>Item</th>
                  <th v-if="receipt.mode === 'po'" style="width:70px" class="r">Sisa</th>
                  <th style="width:80px" class="r">Qty</th>
                  <th style="width:60px">Satuan</th>
                  <th style="width:120px">No. Batch</th>
                  <th style="width:140px">Tgl. Expiry <span class="req">*</span></th>
                  <th style="width:120px" class="r">Harga Sat.</th>
                  <th style="width:120px" class="r">Subtotal</th>
                  <th v-if="receipt.mode === 'direct'" style="width:36px"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="receipt.form.items.length === 0">
                  <td :colspan="receipt.mode === 'po' ? 10 : receipt.mode === 'direct' ? 9 : 8" class="grn-state">
                    Belum ada item.
                  </td>
                </tr>
                <tr v-for="(line, idx) in receipt.form.items" :key="line._key" :class="{ 'grn-row-excluded': line._include === false }">
                  <td v-if="receipt.mode === 'po'" class="c">
                    <input type="checkbox" v-model="line._include" @change="recalcTotal" />
                  </td>
                  <td>
                    <span class="grn-type-tag" :data-type="line.item_type">{{ line.item_type }}</span>
                  </td>
                  <td>
                    <button v-if="receipt.mode === 'direct'" class="grn-item-picker-btn" @click="openItemPicker(line)">
                      <span v-if="line.item_id"><strong>{{ line.item_code }}</strong> · {{ line.item_name }}</span>
                      <span v-else class="grn-item-placeholder">— Pilih item —</span>
                    </button>
                    <span v-else>
                      <strong>{{ line.item_code }}</strong> · {{ line.item_name }}
                    </span>
                  </td>
                  <td v-if="receipt.mode === 'po'" class="r grn-remaining">{{ line.qty_remaining }}</td>
                  <td class="r">
                    <input
                      v-if="receipt.mode !== 'view'"
                      type="number" min="0.01" step="0.01"
                      :max="receipt.mode === 'po' ? line.qty_remaining : undefined"
                      v-model.number="line.qty_received"
                      @input="recalcLine(line)"
                      :disabled="line._include === false"
                      class="grn-input-inline grn-input-r"
                    />
                    <span v-else>{{ line.qty_received }}</span>
                  </td>
                  <td>{{ line.item_unit || '—' }}</td>
                  <td>
                    <input
                      v-if="receipt.mode !== 'view'"
                      type="text"
                      v-model="line.batch_no"
                      :disabled="line._include === false"
                      placeholder="BATCH-001"
                      class="grn-input-inline"
                    />
                    <span v-else>{{ line.batch_no || '—' }}</span>
                  </td>
                  <td>
                    <input
                      v-if="receipt.mode !== 'view'"
                      type="date"
                      v-model="line.expiry_date"
                      :disabled="line._include === false"
                      :min="todayPlus1()"
                      class="grn-input-inline"
                    />
                    <span v-else>{{ formatDate(line.expiry_date) }}</span>
                  </td>
                  <td class="r">
                    <input
                      v-if="receipt.mode !== 'view'"
                      type="number" min="0" step="100"
                      v-model.number="line.unit_price"
                      @input="recalcLine(line)"
                      :disabled="line._include === false"
                      class="grn-input-inline grn-input-r"
                    />
                    <span v-else>{{ formatRp(line.unit_price) }}</span>
                  </td>
                  <td class="r"><strong>{{ formatRp(line.subtotal) }}</strong></td>
                  <td v-if="receipt.mode === 'direct'" class="c">
                    <button class="grn-icon-btn grn-icon-danger" @click="removeLine(idx)" title="Hapus baris">×</button>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td :colspan="receipt.mode === 'po' ? 8 : 7" class="r"><strong>Total Penerimaan</strong></td>
                  <td class="r"><strong>{{ formatRp(receipt.form.total_amount) }}</strong></td>
                  <td v-if="receipt.mode === 'direct'"></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div class="grn-modal-foot">
            <button class="grn-btn-secondary" @click="closeReceipt" :disabled="receipt.submitting">
              {{ receipt.mode === 'view' ? 'Tutup' : 'Batal' }}
            </button>
            <button v-if="receipt.mode !== 'view'" class="grn-btn-primary" @click="submit" :disabled="receipt.submitting">
              {{ receipt.submitting ? 'Menyimpan…' : 'Catat Penerimaan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Item picker (direct mode) -->
    <Teleport to="body">
      <div v-if="itemPicker.open" class="grn-modal-overlay grn-modal-overlay-2" @click.self="closeItemPicker">
        <div class="grn-picker">
          <div class="grn-modal-head">
            <h3>Pilih Item</h3>
            <button class="grn-close" @click="closeItemPicker">×</button>
          </div>
          <div class="grn-picker-tabs">
            <button v-for="t in ['MEDICATION','BHP','IOL']" :key="t" :class="['grn-picker-tab', { active: itemPicker.type === t }]" @click="itemPicker.type = t">
              {{ t === 'MEDICATION' ? 'Obat' : t }}
            </button>
          </div>
          <input type="text" v-model="itemPicker.search" @input="searchItems" placeholder="Cari kode/nama…" class="grn-picker-search" autofocus />
          <div class="grn-picker-results">
            <div v-if="itemPicker.loading" class="grn-state">Memuat…</div>
            <div v-else-if="itemPicker.results.length === 0" class="grn-state">Tidak ada hasil.</div>
            <button v-for="r in itemPicker.results" :key="r.id" class="grn-picker-item" @click="selectItem(r)">
              <div class="grn-picker-main"><strong>{{ r.code }}</strong> · {{ displayMasterName(r) }}</div>
              <div v-if="displayMasterSub(r)" class="grn-picker-sub">{{ displayMasterSub(r) }}</div>
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Confirm delete -->
    <Teleport to="body">
      <div v-if="confirmDelete.open" class="grn-modal-overlay" @click.self="confirmDelete.open = false">
        <div class="grn-confirm">
          <h3>Hapus Penerimaan?</h3>
          <p>
            <strong>{{ confirmDelete.row?.grn_number }}</strong> akan dihapus & stok terkait akan dikembalikan.
            Jika terkait PO, status PO akan otomatis diperbarui.
          </p>
          <div class="grn-confirm-actions">
            <button class="grn-btn-secondary" :disabled="confirmDelete.busy" @click="confirmDelete.open = false">Batal</button>
            <button class="grn-btn-danger" :disabled="confirmDelete.busy" @click="doDelete">
              {{ confirmDelete.busy ? 'Memproses…' : 'Hapus' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Toast -->
    <Teleport to="body">
      <div v-if="toast" class="grn-toast-wrap">
        <div class="grn-toast" :class="`grn-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.grn-wrap { display: flex; flex-direction: column; gap: 1.1rem; }

.grn-section-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.grn-section-head h2 { font-family: 'Space Grotesk', serif; font-size: 22px; color: var(--td); margin: 0; }
.grn-section-head p { font-size: 12.5px; color: var(--tm); margin: 4px 0 0; }

.grn-btn-primary { display: inline-flex; align-items: center; gap: 6px; background: var(--ga); color: white; border: 0; border-radius: 8px; padding: 8px 14px; font-size: 13px; font-weight: 500; cursor: pointer; }
.grn-btn-primary svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.5; }
.grn-btn-primary:hover { background: var(--gd); }
.grn-btn-primary:disabled { opacity: .5; cursor: not-allowed; }

.grn-btn-secondary { background: var(--bs); color: var(--td); border: 1px solid var(--gb); border-radius: 8px; padding: 8px 14px; font-size: 13px; cursor: pointer; }
.grn-btn-secondary:hover { background: var(--bc); }
.grn-btn-sm { padding: 5px 10px; font-size: 12px; }

.grn-btn-danger { background: var(--eb); color: var(--et); border: 1px solid var(--ebd); border-radius: 8px; padding: 8px 14px; font-size: 13px; font-weight: 500; cursor: pointer; }
.grn-btn-danger:hover { filter: brightness(.95); }

.grn-filter-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; padding: 8px 12px; }
.grn-search { flex: 1 1 240px; min-width: 200px; padding: 6px 10px; border: 1px solid var(--gb); border-radius: 6px; font-size: 12.5px; background: var(--bc); }
.grn-filter-label { font-size: 11.5px; color: var(--tm); font-weight: 500; }
.grn-filter-select { padding: 5px 8px; border: 1px solid var(--gb); border-radius: 6px; font-size: 12.5px; background: var(--bc); color: var(--td); }

.grn-table-wrap { background: var(--bc); border: 1px solid var(--gb); border-radius: 8px; overflow-x: auto; }
.grn-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.grn-table th, .grn-table td { padding: 9px 12px; text-align: left; border-bottom: 1px solid var(--gb); }
.grn-table th { background: var(--bs); font-weight: 600; color: var(--tm); font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; }
.grn-table td.r, .grn-table th.r { text-align: right; }
.grn-table td.c, .grn-table th.c { text-align: center; }
.grn-table tbody tr:hover { background: var(--bs); }
.grn-rownum { color: var(--tu); font-variant-numeric: tabular-nums; }

.grn-po-link { color: var(--ga); font-weight: 500; }
.grn-direct-tag { font-size: 10px; padding: 1px 6px; border-radius: 4px; background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

.grn-state { text-align: center; padding: 24px; color: var(--tu); font-size: 12.5px; }
.grn-state-error { color: var(--et); }

.grn-actions-cell { display: flex; gap: 4px; justify-content: center; }
.grn-icon-btn { background: transparent; border: 1px solid var(--gb); border-radius: 5px; padding: 4px 6px; cursor: pointer; color: var(--tm); }
.grn-icon-btn:hover { background: var(--bs); color: var(--td); }
.grn-icon-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; }
.grn-icon-danger { color: var(--et); }
.grn-icon-danger:hover { background: var(--eb); }

.grn-pagination { display: flex; align-items: center; justify-content: space-between; padding: 0 4px; font-size: 12.5px; color: var(--tm); }
.grn-pagination button { background: var(--bc); border: 1px solid var(--gb); border-radius: 6px; padding: 5px 10px; font-size: 12px; cursor: pointer; }
.grn-pagination button:disabled { opacity: .4; cursor: not-allowed; }

/* Modal overlay shared */
.grn-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 1rem; }
.grn-modal-overlay-2 { z-index: 1010; background: rgba(0,0,0,.2); }

/* Mode select modal */
.grn-mode-modal { background: var(--bc); border-radius: 12px; max-width: 540px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.grn-mode-options { padding: 16px 20px 20px; display: flex; flex-direction: column; gap: 10px; }
.grn-mode-card { display: flex; gap: 14px; align-items: flex-start; text-align: left; background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; padding: 14px 16px; cursor: pointer; }
.grn-mode-card:hover { border-color: var(--ga); background: var(--bc); }
.grn-mode-card svg { width: 28px; height: 28px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; margin-top: 2px; }
.grn-mode-card strong { font-size: 14px; color: var(--td); display: block; }
.grn-mode-card p { font-size: 12px; color: var(--tm); margin: 4px 0 0; }

/* PO picker modal */
.grn-po-picker-modal { background: var(--bc); border-radius: 12px; max-width: 600px; width: 100%; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.grn-po-search { margin: 12px 20px 8px; padding: 8px 12px; border: 1px solid var(--gb); border-radius: 6px; font-size: 13px; background: var(--bc); }
.grn-po-list { flex: 1; overflow-y: auto; padding: 0 12px 12px; }
.grn-po-item { display: block; width: 100%; text-align: left; background: transparent; border: 1px solid transparent; padding: 10px 12px; border-radius: 6px; cursor: pointer; }
.grn-po-item:hover { background: var(--bs); border-color: var(--gb); }
.grn-po-item-main { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--td); }
.grn-po-item-sub { font-size: 11.5px; color: var(--tu); margin-top: 3px; }
.grn-po-status { font-size: 10px; padding: 1px 6px; border-radius: 4px; font-weight: 500; }
.status-draft { background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.status-sent { background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.status-partial { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

/* Main receipt modal */
.grn-modal { background: var(--bc); border-radius: 12px; max-width: 1100px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.grn-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--gb); }
.grn-modal-head h3 { margin: 0; font-size: 16px; color: var(--td); }
.grn-close { background: transparent; border: 0; font-size: 22px; color: var(--tu); cursor: pointer; line-height: 1; padding: 0 6px; }
.grn-close:hover { color: var(--td); }
.grn-modal-body { padding: 18px 20px; overflow-y: auto; flex: 1; }
.grn-modal-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 20px; border-top: 1px solid var(--gb); }

.grn-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; margin-bottom: 18px; }
.grn-field { display: flex; flex-direction: column; gap: 4px; }
.grn-field-full { grid-column: 1 / -1; }
.grn-field label { font-size: 11.5px; font-weight: 500; color: var(--tm); }
.grn-field .req { color: var(--et); }
.grn-field input, .grn-field select, .grn-field textarea { padding: 7px 10px; border: 1px solid var(--gb); border-radius: 6px; font-size: 13px; background: var(--bc); color: var(--td); font-family: inherit; }
.grn-field input:disabled, .grn-field select:disabled, .grn-field textarea:disabled { background: var(--bs); color: var(--tu); }

.grn-items-head { display: flex; align-items: center; justify-content: space-between; margin: 12px 0 8px; }
.grn-items-head h4 { margin: 0; font-size: 14px; color: var(--td); }

.grn-items-table { width: 100%; border-collapse: collapse; font-size: 12.5px; border: 1px solid var(--gb); border-radius: 6px; overflow: hidden; }
.grn-items-table th, .grn-items-table td { padding: 7px 8px; border-bottom: 1px solid var(--gb); text-align: left; vertical-align: middle; }
.grn-items-table th { background: var(--bs); font-weight: 600; color: var(--tm); font-size: 10.5px; text-transform: uppercase; }
.grn-items-table th .req { color: var(--et); }
.grn-items-table td.r, .grn-items-table th.r { text-align: right; }
.grn-items-table td.c, .grn-items-table th.c { text-align: center; }
.grn-items-table tfoot td { background: var(--bs); font-size: 13px; padding: 9px 10px; }
.grn-row-excluded { opacity: .45; }
.grn-remaining { font-weight: 500; color: var(--ga); }

.grn-input-inline { width: 100%; padding: 4px 6px; border: 1px solid var(--gb); border-radius: 4px; font-size: 12px; background: var(--bc); }
.grn-input-r { text-align: right; }

.grn-type-tag { display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.grn-type-tag[data-type="MEDICATION"] { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.grn-type-tag[data-type="BHP"] { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.grn-type-tag[data-type="IOL"] { background: var(--wb); color: var(--wt); border-color: var(--wbd); }

.grn-item-picker-btn { width: 100%; text-align: left; background: var(--bc); border: 1px solid var(--gb); border-radius: 4px; padding: 5px 10px; font-size: 12.5px; cursor: pointer; color: var(--td); }
.grn-item-picker-btn:hover { border-color: var(--ga); background: var(--bs); }
.grn-item-placeholder { color: var(--tu); font-style: italic; }

/* Item picker (nested) */
.grn-picker { background: var(--bc); border-radius: 12px; max-width: 600px; width: 100%; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.grn-picker-tabs { display: flex; gap: 4px; padding: 10px 20px 0; }
.grn-picker-tab { background: var(--bs); border: 1px solid var(--gb); border-radius: 6px 6px 0 0; padding: 6px 14px; font-size: 12.5px; cursor: pointer; color: var(--tm); border-bottom: 0; }
.grn-picker-tab.active { background: var(--bc); color: var(--ga); font-weight: 500; border-color: var(--ga); }
.grn-picker-search { margin: 12px 20px 8px; padding: 8px 12px; border: 1px solid var(--gb); border-radius: 6px; font-size: 13px; background: var(--bc); }
.grn-picker-results { flex: 1; overflow-y: auto; padding: 0 12px 12px; }
.grn-picker-item { display: block; width: 100%; text-align: left; background: transparent; border: 0; padding: 9px 12px; border-radius: 6px; cursor: pointer; }
.grn-picker-item:hover { background: var(--bs); }
.grn-picker-main { font-size: 13px; color: var(--td); }
.grn-picker-sub { font-size: 11.5px; color: var(--tu); margin-top: 2px; }

/* Confirm */
.grn-confirm { background: var(--bc); border-radius: 12px; max-width: 440px; width: 100%; padding: 20px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.grn-confirm h3 { margin: 0 0 8px; font-size: 16px; }
.grn-confirm p { margin: 0 0 14px; font-size: 13px; color: var(--tm); }
.grn-confirm-actions { display: flex; justify-content: flex-end; gap: 8px; }

/* Toast */
.grn-toast-wrap { position: fixed; top: 16px; right: 16px; z-index: 2000; }
.grn-toast { padding: 10px 16px; border-radius: 8px; font-size: 13px; color: white; box-shadow: 0 8px 24px rgba(0,0,0,.2); }
.grn-toast-s { background: var(--st); }
.grn-toast-e { background: var(--et); }
.grn-toast-w { background: var(--wt); }
</style>
