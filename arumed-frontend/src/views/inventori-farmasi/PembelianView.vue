<script setup>
/**
 * PembelianView — manajemen Purchase Order.
 *
 * UX: list PO + modal create/edit dengan dynamic line items.
 * Item picker: dropdown type (Obat/BHP/IOL) + search live ke master.
 */
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { pembelianApi, masterApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'
import { uid } from '@/utils/uid'

const auth = useAuthStore()

// ─── Profil klinik (untuk kop surat PO) ──────────────────────────────────
const clinic = ref(null)
const clinicLogoUrl = computed(() => {
  const p = clinic.value?.logo_path
  if (!p) return null
  if (p.startsWith('http')) return p
  const apiBase = import.meta.env.VITE_API_URL ?? '/api/v1'
  const backendOrigin = apiBase.replace(/\/api\/v\d+\/?$/, '')
  return `${backendOrigin}/storage/${p}`
})

async function fetchClinic() {
  try {
    const res = await masterApi.profilKlinik.show()
    clinic.value = res.data?.data ?? res.data ?? null
  } catch (e) {
    // Non-fatal: PO tetap bisa diprint tanpa kop lengkap.
  }
}

// ─── State list ─────────────────────────────────────────────────────────
const items = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 })
const loading = ref(false)
const error = ref(null)

const filters = ref({ search: '', status: '', supplier_id: '' })
const suppliers = ref([])

// ─── Tab Aktif vs History ─────────────────────────────────────────────────
// Aktif = PO yang masih berjalan (DRAFT/SENT/PARTIAL/CANCELED).
// History = PO yang sudah diterima penuh (RECEIVED). Pembedaan murni by status —
// stok sudah auto-update di backend saat GRN (GoodsReceiptService), PO tinggal
// "pindah" ke tab History karena statusnya jadi RECEIVED.
const activeTab = ref('active') // 'active' | 'history'
const ACTIVE_STATUSES = ['DRAFT', 'SENT', 'PARTIAL', 'CANCELED']

function switchTab(tab) {
  if (activeTab.value === tab) return
  activeTab.value = tab
  filters.value.status = '' // reset dropdown status saat ganti tab
  refresh(1)
}

const STATUS_BADGES = {
  DRAFT:    { label: 'Draft',     cls: 'badge-draft' },
  SENT:     { label: 'Dikirim',   cls: 'badge-sent' },
  PARTIAL:  { label: 'Sebagian',  cls: 'badge-partial' },
  RECEIVED: { label: 'Diterima',  cls: 'badge-received' },
  CANCELED: { label: 'Dibatalkan', cls: 'badge-canceled' },
}

const formatRp = (v) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID')
const formatDate = (v) => v ? new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const formatDateTime = (v) => v ? new Date(v).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'

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

    if (activeTab.value === 'history') {
      // History = hanya PO yang sudah diterima penuh.
      params.status = 'RECEIVED'
    } else if (filters.value.status) {
      // Tab Aktif + dropdown status spesifik (selain RECEIVED).
      params.status = filters.value.status
    } else {
      // Tab Aktif + "Semua" → semua kecuali RECEIVED.
      params.statuses = ACTIVE_STATUSES
    }
    const res = await pembelianApi.list(params)
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
    error.value = e.response?.data?.message ?? 'Gagal memuat PO'
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

// ─── Modal create/edit ──────────────────────────────────────────────────
const modal = ref({
  open: false,
  mode: 'create', // create | edit | view
  submitting: false,
  errors: null,
  form: blankForm(),
})

function blankForm() {
  return {
    id: null,
    po_number: '',
    supplier_id: '',
    po_date: new Date().toISOString().slice(0, 10),
    expected_date: '',
    status: 'DRAFT',
    notes: '',
    total_amount: 0,
    items: [],
  }
}

function openCreate() {
  modal.value = { open: true, mode: 'create', submitting: false, errors: null, form: blankForm() }
}

async function openEdit(row) {
  modal.value = { open: true, mode: 'edit', submitting: false, errors: null, form: blankForm() }
  try {
    const res = await pembelianApi.show(row.id)
    const po = res.data?.data
    modal.value.form = {
      id: po.id,
      po_number: po.po_number,
      supplier_id: po.supplier_id,
      po_date: po.po_date,
      expected_date: po.expected_date ?? '',
      status: po.status,
      notes: po.notes ?? '',
      total_amount: po.total_amount,
      items: po.items.map((it) => ({
        ...it,
        _key: uid(),
      })),
    }
  } catch (e) {
    showToast('e', 'Gagal memuat detail PO')
    modal.value.open = false
  }
}

async function openView(row) {
  await openEdit(row)
  modal.value.mode = 'view'
}

function closeModal() {
  modal.value.open = false
}

const isLocked = computed(() => {
  if (modal.value.mode === 'view') return true
  if (modal.value.mode === 'edit') {
    return ['RECEIVED', 'CANCELED', 'PARTIAL'].includes(modal.value.form.status)
  }
  return false
})

const canEditItems = computed(() => {
  if (modal.value.mode === 'create') return true
  if (modal.value.mode === 'edit') {
    // Item locked kalau sudah ada qty_received > 0 (artinya sudah ada GRN)
    return modal.value.form.status === 'DRAFT' || modal.value.form.status === 'SENT'
      ? !modal.value.form.items.some((it) => Number(it.qty_received) > 0)
      : false
  }
  return false
})

// ─── Line items management ──────────────────────────────────────────────
function addLine() {
  modal.value.form.items.push({
    _key: uid(),
    item_type: 'MEDICATION',
    item_id: '',
    item_code: '',
    item_name: '',
    item_unit: '',
    qty_ordered: 1,
    qty_received: 0,
    unit_price: 0,
    subtotal: 0,
    notes: '',
  })
}

function removeLine(idx) {
  modal.value.form.items.splice(idx, 1)
  recalcTotal()
}

function recalcLine(line) {
  line.subtotal = Math.round(Number(line.qty_ordered || 0) * Number(line.unit_price || 0) * 100) / 100
  recalcTotal()
}

function recalcTotal() {
  modal.value.form.total_amount = modal.value.form.items.reduce((sum, it) => sum + Number(it.subtotal || 0), 0)
}

// ─── Item picker (dropdown search per line) ─────────────────────────────
const itemPicker = ref({ open: false, lineKey: null, type: 'MEDICATION', search: '', results: [], loading: false })
let pickerDebounce = null

function openItemPicker(line) {
  itemPicker.value = {
    open: true,
    lineKey: line._key,
    type: line.item_type || 'MEDICATION',
    search: '',
    results: [],
    loading: false,
  }
  searchItems()
}

function closeItemPicker() {
  itemPicker.value.open = false
}

async function searchItems() {
  clearTimeout(pickerDebounce)
  pickerDebounce = setTimeout(async () => {
    itemPicker.value.loading = true
    try {
      const params = { per_page: 20 }
      if (itemPicker.value.search) params.search = itemPicker.value.search
      const apiMap = {
        MEDICATION: masterApi.obat,
        BHP: masterApi.bhp,
        IOL: masterApi.iol,
      }
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
  const line = modal.value.form.items.find((it) => it._key === itemPicker.value.lineKey)
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

// ─── Submit ─────────────────────────────────────────────────────────────
async function submit() {
  if (modal.value.form.items.length === 0) {
    showToast('w', 'PO harus berisi minimal 1 item')
    return
  }
  if (!modal.value.form.supplier_id) {
    showToast('w', 'Supplier wajib dipilih')
    return
  }
  for (const [idx, it] of modal.value.form.items.entries()) {
    if (!it.item_id) {
      showToast('w', `Baris #${idx + 1}: item belum dipilih`)
      return
    }
    if (Number(it.qty_ordered) <= 0) {
      showToast('w', `Baris #${idx + 1}: quantity harus > 0`)
      return
    }
  }

  modal.value.submitting = true
  modal.value.errors = null
  try {
    const payload = {
      supplier_id: modal.value.form.supplier_id,
      po_date: modal.value.form.po_date,
      expected_date: modal.value.form.expected_date || null,
      status: modal.value.form.status,
      notes: modal.value.form.notes || null,
      items: modal.value.form.items.map((it) => ({
        item_type: it.item_type,
        item_id: it.item_id,
        qty_ordered: Number(it.qty_ordered),
        unit_price: Number(it.unit_price || 0),
        notes: it.notes || null,
      })),
    }

    if (modal.value.mode === 'create') {
      await pembelianApi.create(payload)
      showToast('s', 'PO dibuat')
    } else {
      // Edit: hanya kirim items kalau masih bisa diedit
      if (!canEditItems.value) delete payload.items
      await pembelianApi.update(modal.value.form.id, payload)
      showToast('s', 'PO diperbarui')
    }
    closeModal()
    refresh(meta.value.current_page)
  } catch (e) {
    if (e.response?.status === 422) {
      modal.value.errors = e.response.data?.errors ?? null
    }
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan PO')
  } finally {
    modal.value.submitting = false
  }
}

// ─── Delete & Cancel ────────────────────────────────────────────────────
const confirmAction = ref({ open: false, type: '', row: null, busy: false })

function askDelete(row) { confirmAction.value = { open: true, type: 'delete', row, busy: false } }
function askCancel(row) { confirmAction.value = { open: true, type: 'cancel', row, busy: false } }

async function doAction() {
  confirmAction.value.busy = true
  try {
    if (confirmAction.value.type === 'delete') {
      await pembelianApi.remove(confirmAction.value.row.id)
      showToast('s', 'PO dihapus')
    } else if (confirmAction.value.type === 'cancel') {
      await pembelianApi.cancel(confirmAction.value.row.id)
      showToast('s', 'PO di-cancel')
    }
    confirmAction.value.open = false
    refresh(meta.value.current_page)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Aksi gagal')
  } finally {
    confirmAction.value.busy = false
  }
}

// ─── Print PO (surat A4) ─────────────────────────────────────────────────
const printPo = ref(null)

const printSupplier = computed(() => {
  if (!printPo.value) return null
  if (printPo.value.supplier) return printPo.value.supplier
  return suppliers.value.find((s) => s.id === printPo.value.supplier_id) ?? null
})

const printGrandTotal = computed(() =>
  (printPo.value?.items ?? []).reduce((sum, it) => sum + Number(it.subtotal || 0), 0)
)

const TYPE_LABEL = { MEDICATION: 'Obat', BHP: 'BHP', IOL: 'IOL' }

// Buka data PO lengkap lalu cetak. Bisa dipanggil dari baris tabel maupun modal.
async function printPurchaseOrder(row) {
  let po = row
  // Kalau data baris belum punya items (list tidak memuat relasi), ambil detail.
  if (!po?.items || !Array.isArray(po.items) || po.items.length === 0) {
    try {
      const res = await pembelianApi.show(row.id)
      po = res.data?.data ?? row
    } catch (e) {
      showToast('e', 'Gagal memuat detail PO untuk dicetak')
      return
    }
  }
  printPo.value = po
  await nextTick()
  // Pastikan logo benar-benar ter-load sebelum print, kalau tidak gambar
  // cross-origin sering tidak ikut tercetak (img belum siap saat print()).
  await waitForLogo()
  window.print()
}

function waitForLogo() {
  return new Promise((resolve) => {
    const url = clinicLogoUrl.value
    if (!url) return resolve()
    const img = new Image()
    img.onload = resolve
    img.onerror = resolve
    img.src = url
    // Safety timeout: jangan menggantung print kalau gambar lambat.
    setTimeout(resolve, 2500)
  })
}

// ─── Init ───────────────────────────────────────────────────────────────
onMounted(() => {
  refresh()
  fetchSuppliers()
  fetchClinic()
})

watch(() => itemPicker.value.type, searchItems)

const canWrite = computed(() => auth.can('inventori_farmasi.write'))
const canDelete = computed(() => auth.can('inventori_farmasi.delete'))
</script>

<template>
  <div class="po-wrap">
    <div class="po-section-head">
      <div>
        <h2>Purchase Order</h2>
        <p>Pembelian obat, BHP, & IOL ke supplier. Penomoran auto PO-YYYYMM-NNNN.</p>
      </div>
      <button v-if="canWrite && activeTab === 'active'" class="po-btn-primary" @click="openCreate">
        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Buat PO Baru
      </button>
    </div>

    <!-- Tab: PO Aktif vs History Pembelian -->
    <div class="po-tabs">
      <button class="po-tab" :class="{ active: activeTab === 'active' }" @click="switchTab('active')">
        PO Aktif
      </button>
      <button class="po-tab" :class="{ active: activeTab === 'history' }" @click="switchTab('history')">
        History Pembelian
      </button>
    </div>

    <div class="po-filter-bar">
      <input
        type="text"
        v-model="filters.search"
        @input="onFilterChange"
        placeholder="Cari no. PO / nama supplier…"
        class="po-search"
      />

      <template v-if="activeTab === 'active'">
        <span class="po-filter-label">Status:</span>
        <select v-model="filters.status" @change="onFilterChange" class="po-filter-select">
          <option value="">Semua</option>
          <option value="DRAFT">Draft</option>
          <option value="SENT">Dikirim</option>
          <option value="PARTIAL">Sebagian Diterima</option>
          <option value="CANCELED">Dibatalkan</option>
        </select>
      </template>

      <span class="po-filter-label">Supplier:</span>
      <select v-model="filters.supplier_id" @change="onFilterChange" class="po-filter-select">
        <option value="">Semua</option>
        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} · {{ s.name }}</option>
      </select>
    </div>

    <div class="po-table-wrap">
      <table class="po-table">
        <thead>
          <tr>
            <th class="c" style="width:48px">No</th>
            <th>No. PO</th>
            <th>Tanggal</th>
            <th>Supplier</th>
            <th>ETA</th>
            <th class="r">Total</th>
            <th class="c">Status</th>
            <th class="c">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="8" class="po-state">Memuat…</td>
          </tr>
          <tr v-else-if="error">
            <td colspan="8" class="po-state po-state-error">{{ error }}</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="8" class="po-state">
              <template v-if="activeTab === 'history'">Belum ada PO yang diterima. PO akan muncul di sini setelah barang diterima penuh di menu Penerimaan.</template>
              <template v-else>Belum ada PO. Klik tombol "Buat PO Baru" untuk mulai.</template>
            </td>
          </tr>
          <tr v-for="(po, idx) in items" :key="po.id" v-else>
            <td class="c po-rownum">{{ (meta.current_page - 1) * meta.per_page + idx + 1 }}</td>
            <td><strong>{{ po.po_number }}</strong></td>
            <td>{{ formatDate(po.po_date) }}</td>
            <td>{{ po.supplier?.name ?? '—' }}</td>
            <td>{{ formatDate(po.expected_date) }}</td>
            <td class="r">{{ formatRp(po.total_amount) }}</td>
            <td class="c">
              <span class="po-badge" :class="STATUS_BADGES[po.status]?.cls">
                {{ STATUS_BADGES[po.status]?.label ?? po.status }}
              </span>
            </td>
            <td class="c po-actions-cell">
              <button class="po-icon-btn" title="Lihat detail" @click="openView(po)">
                <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
              <button class="po-icon-btn" title="Cetak / Download PO" @click="printPurchaseOrder(po)">
                <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              </button>
              <button v-if="canWrite && ['DRAFT','SENT'].includes(po.status)" class="po-icon-btn" title="Edit" @click="openEdit(po)">
                <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
              </button>
              <button v-if="canWrite && ['DRAFT','SENT'].includes(po.status)" class="po-icon-btn po-icon-warn" title="Cancel" @click="askCancel(po)">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
              </button>
              <button v-if="canDelete && po.status === 'DRAFT'" class="po-icon-btn po-icon-danger" title="Hapus" @click="askDelete(po)">
                <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="meta.last_page > 1" class="po-pagination">
      <button :disabled="meta.current_page <= 1" @click="refresh(meta.current_page - 1)">‹ Sebelumnya</button>
      <span>Halaman {{ meta.current_page }} / {{ meta.last_page }} · {{ meta.total }} PO</span>
      <button :disabled="meta.current_page >= meta.last_page" @click="refresh(meta.current_page + 1)">Berikutnya ›</button>
    </div>

    <!-- Modal create/edit/view -->
    <Teleport to="body">
      <div v-if="modal.open" class="po-modal-overlay" @click.self="closeModal">
        <div class="po-modal">
          <div class="po-modal-head">
            <h3>
              <span v-if="modal.mode === 'create'">Buat Purchase Order Baru</span>
              <span v-else-if="modal.mode === 'edit'">Edit PO — {{ modal.form.po_number }}</span>
              <span v-else>Detail PO — {{ modal.form.po_number }}</span>
            </h3>
            <button class="po-close" @click="closeModal">×</button>
          </div>

          <div class="po-modal-body">
            <!-- Header form -->
            <div class="po-form-grid">
              <div class="po-field">
                <label>Supplier <span class="req">*</span></label>
                <select v-model="modal.form.supplier_id" :disabled="isLocked">
                  <option value="">— Pilih supplier —</option>
                  <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} · {{ s.name }}</option>
                </select>
              </div>
              <div class="po-field">
                <label>Tanggal PO <span class="req">*</span></label>
                <input type="date" v-model="modal.form.po_date" :disabled="isLocked" />
              </div>
              <div class="po-field">
                <label>Tanggal ETA</label>
                <input type="date" v-model="modal.form.expected_date" :disabled="isLocked" />
              </div>
              <div class="po-field">
                <label>Status</label>
                <select v-model="modal.form.status" :disabled="isLocked || modal.mode === 'create'">
                  <option value="DRAFT">Draft</option>
                  <option value="SENT">Dikirim</option>
                  <option v-if="modal.mode !== 'create'" value="PARTIAL" disabled>Sebagian (auto)</option>
                  <option v-if="modal.mode !== 'create'" value="RECEIVED" disabled>Diterima (auto)</option>
                  <option v-if="modal.mode !== 'create'" value="CANCELED" disabled>Dibatalkan</option>
                </select>
              </div>
              <div class="po-field po-field-full">
                <label>Catatan</label>
                <textarea v-model="modal.form.notes" :disabled="isLocked" rows="2" placeholder="Catatan opsional…"></textarea>
              </div>
            </div>

            <!-- Line items -->
            <div class="po-items-head">
              <h4>Item PO</h4>
              <button v-if="!isLocked && canEditItems" class="po-btn-secondary po-btn-sm" @click="addLine">
                + Tambah Baris
              </button>
              <span v-else-if="!isLocked && !canEditItems" class="po-warn-text">
                Item tidak bisa diubah — sudah ada penerimaan.
              </span>
            </div>

            <div class="po-items-wrap">
            <table class="po-items-table">
              <thead>
                <tr>
                  <th style="width:80px">Tipe</th>
                  <th>Item</th>
                  <th style="width:90px" class="r">Qty</th>
                  <th style="width:60px">Satuan</th>
                  <th style="width:130px" class="r">Harga Satuan</th>
                  <th style="width:130px" class="r">Subtotal</th>
                  <th v-if="!isLocked && canEditItems" style="width:40px"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="modal.form.items.length === 0">
                  <td colspan="7" class="po-state">Belum ada item. Klik "+ Tambah Baris".</td>
                </tr>
                <tr v-for="(line, idx) in modal.form.items" :key="line._key">
                  <td>
                    <span class="po-type-tag" :data-type="line.item_type">{{ line.item_type }}</span>
                  </td>
                  <td>
                    <button v-if="!isLocked && canEditItems" class="po-item-picker-btn" @click="openItemPicker(line)">
                      <span v-if="line.item_id">
                        <strong>{{ line.item_code }}</strong> · {{ line.item_name }}
                      </span>
                      <span v-else class="po-item-placeholder">— Pilih item —</span>
                    </button>
                    <span v-else>
                      <strong>{{ line.item_code }}</strong> · {{ line.item_name }}
                    </span>
                  </td>
                  <td class="r">
                    <input
                      v-if="!isLocked && canEditItems"
                      type="number" min="0.01" step="0.01"
                      v-model.number="line.qty_ordered"
                      @input="recalcLine(line)"
                      class="po-input-inline po-input-r"
                    />
                    <span v-else>{{ line.qty_ordered }}</span>
                  </td>
                  <td>{{ line.item_unit || '—' }}</td>
                  <td class="r">
                    <input
                      v-if="!isLocked && canEditItems"
                      type="number" min="0" step="100"
                      v-model.number="line.unit_price"
                      @input="recalcLine(line)"
                      class="po-input-inline po-input-r"
                    />
                    <span v-else>{{ formatRp(line.unit_price) }}</span>
                  </td>
                  <td class="r"><strong>{{ formatRp(line.subtotal) }}</strong></td>
                  <td v-if="!isLocked && canEditItems" class="c">
                    <button class="po-icon-btn po-icon-danger" @click="removeLine(idx)" title="Hapus baris">×</button>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="5" class="r"><strong>Total PO</strong></td>
                  <td class="r"><strong>{{ formatRp(modal.form.total_amount) }}</strong></td>
                  <td v-if="!isLocked && canEditItems"></td>
                </tr>
              </tfoot>
            </table>
            </div>
          </div>

          <div class="po-modal-foot">
            <button class="po-btn-secondary" @click="closeModal" :disabled="modal.submitting">
              {{ modal.mode === 'view' ? 'Tutup' : 'Batal' }}
            </button>
            <button
              v-if="modal.mode !== 'create' && modal.form.id"
              class="po-btn-secondary"
              @click="printPurchaseOrder(modal.form)"
            >
              <svg viewBox="0 0 24 24" class="po-btn-ico"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              Cetak PO
            </button>
            <button v-if="modal.mode !== 'view'" class="po-btn-primary" @click="submit" :disabled="modal.submitting">
              {{ modal.submitting ? 'Menyimpan…' : (modal.mode === 'create' ? 'Buat PO' : 'Simpan Perubahan') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Item picker modal (nested) -->
    <Teleport to="body">
      <div v-if="itemPicker.open" class="po-modal-overlay po-modal-overlay-2" @click.self="closeItemPicker">
        <div class="po-picker">
          <div class="po-modal-head">
            <h3>Pilih Item</h3>
            <button class="po-close" @click="closeItemPicker">×</button>
          </div>
          <div class="po-picker-tabs">
            <button
              v-for="t in ['MEDICATION','BHP','IOL']"
              :key="t"
              :class="['po-picker-tab', { active: itemPicker.type === t }]"
              @click="itemPicker.type = t"
            >
              {{ t === 'MEDICATION' ? 'Obat' : t }}
            </button>
          </div>
          <input
            type="text"
            v-model="itemPicker.search"
            @input="searchItems"
            placeholder="Cari kode/nama…"
            class="po-picker-search"
            autofocus
          />
          <div class="po-picker-results">
            <div v-if="itemPicker.loading" class="po-state">Memuat…</div>
            <div v-else-if="itemPicker.results.length === 0" class="po-state">Tidak ada hasil.</div>
            <button
              v-for="r in itemPicker.results"
              :key="r.id"
              class="po-picker-item"
              @click="selectItem(r)"
            >
              <div class="po-picker-main">
                <strong>{{ r.code }}</strong> · {{ displayMasterName(r) }}
              </div>
              <div v-if="displayMasterSub(r)" class="po-picker-sub">{{ displayMasterSub(r) }}</div>
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Confirm action -->
    <Teleport to="body">
      <div v-if="confirmAction.open" class="po-modal-overlay" @click.self="confirmAction.open = false">
        <div class="po-confirm">
          <h3>{{ confirmAction.type === 'delete' ? 'Hapus PO?' : 'Cancel PO?' }}</h3>
          <p>
            <strong>{{ confirmAction.row?.po_number }}</strong>
            akan {{ confirmAction.type === 'delete' ? 'dihapus permanen' : 'di-cancel' }}.
            <span v-if="confirmAction.type === 'cancel'"> Status berubah jadi CANCELED.</span>
          </p>
          <div class="po-confirm-actions">
            <button class="po-btn-secondary" :disabled="confirmAction.busy" @click="confirmAction.open = false">Batal</button>
            <button class="po-btn-danger" :disabled="confirmAction.busy" @click="doAction">
              {{ confirmAction.busy ? 'Memproses…' : (confirmAction.type === 'delete' ? 'Hapus' : 'Cancel PO') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ===== Print PO — Surat A4 (hanya tampil saat window.print) ===== -->
    <Teleport to="body">
      <div v-if="printPo" id="po-print-root">
        <div class="po-print-sheet">
          <!-- Kop kanonik (sumber tunggal) — identik dgn pratinjau Profil Institusi -->
          <div v-if="clinic?.letterhead_html" class="pp-kop-canon" v-html="clinic.letterhead_html"></div>
          <header v-else class="pp-kop">
            <img v-if="clinicLogoUrl" :src="clinicLogoUrl" alt="Logo" class="pp-logo" />
            <div class="pp-kop-text">
              <div class="pp-clinic">{{ clinic?.clinic_name ?? 'Rumah Sakit' }}</div>
              <div v-if="clinic?.address" class="pp-line">{{ clinic.address }}</div>
              <div class="pp-line">
                <span v-if="clinic?.phone">Telp: {{ clinic.phone }}</span>
                <span v-if="clinic?.email"> · Email: {{ clinic.email }}</span>
              </div>
            </div>
          </header>

          <h1 class="pp-title">PURCHASE ORDER</h1>

          <!-- Meta: nomor PO + supplier -->
          <div class="pp-meta">
            <table class="pp-meta-tbl">
              <tbody>
                <tr><td class="pp-meta-k">No. PO</td><td class="pp-meta-c">:</td><td class="pp-meta-v"><strong>{{ printPo.po_number }}</strong></td></tr>
                <tr><td class="pp-meta-k">Tanggal PO</td><td class="pp-meta-c">:</td><td class="pp-meta-v">{{ formatDate(printPo.po_date) }}</td></tr>
                <tr><td class="pp-meta-k">Estimasi Tiba</td><td class="pp-meta-c">:</td><td class="pp-meta-v">{{ formatDate(printPo.expected_date) }}</td></tr>
                <tr><td class="pp-meta-k">Status</td><td class="pp-meta-c">:</td><td class="pp-meta-v">{{ STATUS_BADGES[printPo.status]?.label ?? printPo.status }}</td></tr>
              </tbody>
            </table>
            <div class="pp-supplier">
              <div class="pp-supplier-head">Kepada Yth. Supplier:</div>
              <div class="pp-supplier-name">{{ printSupplier?.name ?? '—' }}</div>
              <div v-if="printSupplier?.address" class="pp-line">{{ printSupplier.address }}</div>
              <div v-if="printSupplier?.contact_person" class="pp-line">u.p. {{ printSupplier.contact_person }}</div>
              <div v-if="printSupplier?.phone" class="pp-line">Telp: {{ printSupplier.phone }}</div>
            </div>
          </div>

          <!-- Tabel item -->
          <table class="pp-items">
            <thead>
              <tr>
                <th style="width:32px">No</th>
                <th style="width:50px">Tipe</th>
                <th>Nama Item</th>
                <th style="width:70px" class="r">Qty</th>
                <th style="width:55px">Satuan</th>
                <th style="width:110px" class="r">Harga Satuan</th>
                <th style="width:120px" class="r">Subtotal</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(it, i) in printPo.items" :key="i">
                <td class="c">{{ i + 1 }}</td>
                <td>{{ TYPE_LABEL[it.item_type] ?? it.item_type }}</td>
                <td>{{ it.item_name }}</td>
                <td class="r">{{ it.qty_ordered }}</td>
                <td>{{ it.item_unit || '—' }}</td>
                <td class="r">{{ formatRp(it.unit_price) }}</td>
                <td class="r">{{ formatRp(it.subtotal) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="6" class="r"><strong>TOTAL</strong></td>
                <td class="r"><strong>{{ formatRp(printGrandTotal) }}</strong></td>
              </tr>
            </tfoot>
          </table>

          <div v-if="printPo.notes" class="pp-notes">
            <strong>Catatan:</strong> {{ printPo.notes }}
          </div>

          <!-- Tanda tangan — Pemohon (e-sign ringan) & Direktur (TTD basah kosong) -->
          <div class="pp-sign">
            <div class="pp-sign-box">
              <div>Pemohon,</div>
              <div class="pp-sign-esign">
                <div class="pp-esign-check">✓ Ditandatangani secara elektronik</div>
                <div class="pp-esign-meta">{{ formatDateTime(printPo.created_at) }} · {{ printPo.po_number }}</div>
              </div>
              <div class="pp-sign-name">{{ auth.employeeName || '(.......................)' }}</div>
              <div class="pp-sign-sub">Petugas Farmasi</div>
            </div>
            <div class="pp-sign-box">
              <div>Menyetujui,</div>
              <div class="pp-sign-space"></div>
              <div class="pp-sign-name">{{ clinic?.director_name || '(.......................)' }}</div>
              <div class="pp-sign-sub">
                Direktur<span v-if="clinic?.director_sip"> · SIP: {{ clinic.director_sip }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Toast -->
    <Teleport to="body">
      <div v-if="toast" class="po-toast-wrap">
        <div class="po-toast" :class="`po-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.po-wrap { display: flex; flex-direction: column; gap: 1.25rem; max-width: 1280px; margin: 0 auto; width: 100%; }

.po-section-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.po-section-head h2 { font-family: 'Space Grotesk', serif; font-size: 22px; color: var(--td); margin: 0; }
.po-section-head p { font-size: 12.5px; color: var(--tm); margin: 4px 0 0; }

.po-tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--gb); }
.po-tab { background: transparent; border: 0; border-bottom: 2px solid transparent; margin-bottom: -2px; padding: 9px 18px; font-size: 13.5px; font-weight: 600; color: var(--tm); cursor: pointer; }
.po-tab:hover { color: #1763d4; }
.po-tab.active { color: #1763d4; border-bottom-color: #1763d4; }

.po-btn-primary { display: inline-flex; align-items: center; gap: 6px; background: var(--ga); color: white; border: 0; border-radius: 8px; padding: 8px 14px; font-size: 13px; font-weight: 500; cursor: pointer; }
.po-btn-primary svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.5; }
.po-btn-primary:hover { background: var(--gd); }
.po-btn-primary:disabled { opacity: .5; cursor: not-allowed; }

.po-btn-secondary { background: var(--bs); color: var(--td); border: 1px solid var(--gb); border-radius: 8px; padding: 8px 14px; font-size: 13px; cursor: pointer; }
.po-btn-secondary:hover { background: var(--bc); }
.po-btn-secondary:disabled { opacity: .5; cursor: not-allowed; }
.po-btn-sm { padding: 5px 10px; font-size: 12px; }

.po-btn-danger { background: var(--eb); color: var(--et); border: 1px solid var(--ebd); border-radius: 8px; padding: 8px 14px; font-size: 13px; font-weight: 500; cursor: pointer; }
.po-btn-danger:hover { filter: brightness(.95); }

.po-filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; padding: 10px 14px; }
.po-search { flex: 1 1 240px; min-width: 200px; height: 34px; padding: 6px 10px; border: 1px solid var(--gb); border-radius: 6px; font-size: 12.5px; background: var(--bc); }
.po-filter-label { font-size: 11.5px; color: var(--tm); font-weight: 500; }
.po-filter-select { height: 34px; padding: 5px 8px; border: 1px solid var(--gb); border-radius: 6px; font-size: 12.5px; background: var(--bc); color: var(--td); }

.po-table-wrap { background: var(--bc); border: 1px solid var(--gb); border-radius: 8px; overflow-x: auto; }
.po-table { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 720px; }
.po-table th, .po-table td { padding: 9px 12px; text-align: left; border-bottom: 1px solid var(--gb); }
.po-table th { background: var(--bs); font-weight: 600; color: var(--tm); font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; }
.po-table td.r, .po-table th.r { text-align: right; }
.po-table td.c, .po-table th.c { text-align: center; }
.po-table tbody tr:hover { background: var(--bs); }
.po-rownum { color: var(--tu); font-variant-numeric: tabular-nums; }

.po-btn-ico { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; vertical-align: -2px; margin-right: 5px; }

.po-state { text-align: center; padding: 24px; color: var(--tu); font-size: 12.5px; }
.po-state-error { color: var(--et); }

.po-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; }
.badge-draft { background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.badge-sent { background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.badge-partial { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }
.badge-received { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.badge-canceled { background: var(--eb); color: var(--et); border: 1px solid var(--ebd); }

.po-actions-cell { display: flex; gap: 4px; justify-content: center; }
.po-icon-btn { background: transparent; border: 1px solid var(--gb); border-radius: 5px; padding: 4px 6px; cursor: pointer; color: var(--tm); }
.po-icon-btn:hover { background: var(--bs); color: var(--td); }
.po-icon-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; }
.po-icon-danger { color: var(--et); }
.po-icon-danger:hover { background: var(--eb); }
.po-icon-warn { color: var(--wt); }
.po-icon-warn:hover { background: var(--wb); }

.po-pagination { display: flex; align-items: center; justify-content: space-between; padding: 0 4px; font-size: 12.5px; color: var(--tm); }
.po-pagination button { background: var(--bc); border: 1px solid var(--gb); border-radius: 6px; padding: 5px 10px; font-size: 12px; cursor: pointer; }
.po-pagination button:disabled { opacity: .4; cursor: not-allowed; }

/* Modal */
.po-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 1rem; }
.po-modal-overlay-2 { z-index: 1010; background: rgba(0,0,0,.2); }
.po-modal { background: var(--bc); border-radius: 12px; max-width: 1000px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.po-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--gb); }
.po-modal-head h3 { margin: 0; font-size: 16px; color: var(--td); }
.po-close { background: transparent; border: 0; font-size: 22px; color: var(--tu); cursor: pointer; line-height: 1; padding: 0 6px; }
.po-close:hover { color: var(--td); }
.po-modal-body { padding: 20px 22px; overflow-y: auto; flex: 1; }
.po-modal-foot { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 8px; padding: 14px 22px; border-top: 1px solid var(--gb); }

.po-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; margin-bottom: 20px; }
.po-field { display: flex; flex-direction: column; gap: 4px; }
.po-field-full { grid-column: 1 / -1; }
.po-field label { font-size: 11.5px; font-weight: 500; color: var(--tm); }
.po-field .req { color: var(--et); }
.po-field input, .po-field select, .po-field textarea { min-height: 34px; padding: 7px 10px; border: 1px solid var(--gb); border-radius: 6px; font-size: 13px; background: var(--bc); color: var(--td); font-family: inherit; }
.po-field textarea { min-height: auto; }
.po-field input:disabled, .po-field select:disabled, .po-field textarea:disabled { background: var(--bs); color: var(--tu); }

.po-items-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin: 16px 0 10px; }
.po-items-head h4 { margin: 0; font-size: 14px; color: var(--td); }
.po-warn-text { font-size: 11.5px; color: var(--wt); font-style: italic; }

.po-items-wrap { overflow-x: auto; border-radius: 6px; }
.po-items-table { width: 100%; min-width: 640px; border-collapse: collapse; font-size: 12.5px; border: 1px solid var(--gb); border-radius: 6px; overflow: hidden; }
.po-items-table th, .po-items-table td { padding: 8px 10px; border-bottom: 1px solid var(--gb); text-align: left; }
.po-items-table th { background: var(--bs); font-weight: 600; color: var(--tm); font-size: 11px; text-transform: uppercase; }
.po-items-table td.r, .po-items-table th.r { text-align: right; }
.po-items-table td.c, .po-items-table th.c { text-align: center; }
.po-items-table tfoot td { background: var(--bs); font-size: 13px; }

.po-input-inline { width: 100%; padding: 4px 8px; border: 1px solid var(--gb); border-radius: 4px; font-size: 12.5px; background: var(--bc); }
.po-input-r { text-align: right; }

.po-type-tag { display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.po-type-tag[data-type="MEDICATION"] { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.po-type-tag[data-type="BHP"] { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.po-type-tag[data-type="IOL"] { background: var(--wb); color: var(--wt); border-color: var(--wbd); }

.po-item-picker-btn { width: 100%; text-align: left; background: var(--bc); border: 1px solid var(--gb); border-radius: 4px; padding: 5px 10px; font-size: 12.5px; cursor: pointer; color: var(--td); }
.po-item-picker-btn:hover { border-color: var(--ga); background: var(--bs); }
.po-item-placeholder { color: var(--tu); font-style: italic; }

/* Picker modal */
.po-picker { background: var(--bc); border-radius: 12px; max-width: 600px; width: 100%; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.po-picker-tabs { display: flex; gap: 4px; padding: 10px 20px 0; }
.po-picker-tab { background: var(--bs); border: 1px solid var(--gb); border-radius: 6px 6px 0 0; padding: 6px 14px; font-size: 12.5px; cursor: pointer; color: var(--tm); border-bottom: 0; }
.po-picker-tab.active { background: var(--bc); color: var(--ga); font-weight: 500; border-color: var(--ga); }
.po-picker-search { margin: 12px 20px 8px; padding: 8px 12px; border: 1px solid var(--gb); border-radius: 6px; font-size: 13px; background: var(--bc); }
.po-picker-results { flex: 1; overflow-y: auto; padding: 0 12px 12px; }
.po-picker-item { display: block; width: 100%; text-align: left; background: transparent; border: 0; padding: 9px 12px; border-radius: 6px; cursor: pointer; }
.po-picker-item:hover { background: var(--bs); }
.po-picker-main { font-size: 13px; color: var(--td); }
.po-picker-sub { font-size: 11.5px; color: var(--tu); margin-top: 2px; }

/* Confirm */
.po-confirm { background: var(--bc); border-radius: 12px; max-width: 420px; width: 100%; padding: 20px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.po-confirm h3 { margin: 0 0 8px; font-size: 16px; }
.po-confirm p { margin: 0 0 14px; font-size: 13px; color: var(--tm); }
.po-confirm-actions { display: flex; justify-content: flex-end; gap: 8px; }

/* Toast */
.po-toast-wrap { position: fixed; top: 16px; right: 16px; z-index: 2000; }
.po-toast { padding: 10px 16px; border-radius: 8px; font-size: 13px; color: white; box-shadow: 0 8px 24px rgba(0,0,0,.2); }
.po-toast-s { background: var(--st); }
.po-toast-e { background: var(--et); }
.po-toast-w { background: var(--wt); }

/* ── Responsif: reflow saat area konten menyempit ─────────────────────── */
@media (max-width: 900px) {
  .po-form-grid { grid-template-columns: 1fr; }
  .po-pagination { flex-direction: column; gap: 8px; align-items: stretch; text-align: center; }
}
</style>

<!--
  Print styles — TIDAK scoped supaya bisa menyembunyikan seluruh aplikasi
  saat window.print() dan hanya menampilkan #po-print-root.
-->
<style>
/* Di layar: sembunyikan area print sepenuhnya. */
#po-print-root { display: none; }

@media print {
  /* Sembunyikan seluruh aplikasi; hanya area print yang tampil. */
  body > *:not(#po-print-root) { display: none !important; }

  #po-print-root {
    display: block !important;
    position: absolute;
    inset: 0;
    background: #fff;
  }

  @page { size: A4 portrait; margin: 16mm 14mm; }
  html, body { background: #fff !important; }
}

/* Lembar surat A4 — dipakai di layar (kalau dibutuhkan) & print. */
.po-print-sheet {
  width: 182mm;
  margin: 0 auto;
  color: #000;
  font-family: 'Helvetica Neue', Arial, sans-serif;
  font-size: 11pt;
  line-height: 1.5;
}

.pp-kop { display: flex; align-items: center; gap: 14px; border-bottom: 3px double #000; padding-bottom: 10px; }
.pp-logo { max-height: 70px; max-width: 110px; object-fit: contain; }
.pp-clinic { font-size: 17pt; font-weight: 800; letter-spacing: .01em; }
.pp-line { font-size: 9.5pt; color: #222; }

.pp-title { text-align: center; font-size: 14pt; font-weight: 800; letter-spacing: .12em; margin: 16px 0 14px; text-decoration: underline; }

.pp-meta { display: flex; justify-content: space-between; gap: 24px; margin-bottom: 16px; }
.pp-meta-tbl td { padding: 1px 0; font-size: 10pt; vertical-align: top; }
.pp-meta-k { white-space: nowrap; color: #333; }
.pp-meta-c { padding: 0 8px !important; }
.pp-supplier { max-width: 48%; }
.pp-supplier-head { font-size: 9.5pt; color: #333; margin-bottom: 2px; }
.pp-supplier-name { font-weight: 700; font-size: 11pt; }

.pp-items { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-bottom: 14px; }
.pp-items th, .pp-items td { border: 1px solid #000; padding: 5px 7px; text-align: left; }
.pp-items th { background: #f0f0f0; font-weight: 700; }
.pp-items td.r, .pp-items th.r { text-align: right; }
.pp-items td.c, .pp-items th.c { text-align: center; }
.pp-items tfoot td { background: #f7f7f7; font-size: 10.5pt; }
.pp-code { font-weight: 700; margin-right: 4px; }

.pp-notes { font-size: 9.5pt; margin-bottom: 18px; border: 1px solid #999; padding: 6px 9px; background: #fafafa; }

.pp-sign { display: flex; justify-content: space-between; gap: 40px; margin-top: 24px; }
.pp-sign-box { text-align: center; font-size: 10pt; flex: 1; max-width: 240px; }
.pp-sign-space { height: 64px; }
.pp-sign-esign { height: 64px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; }
.pp-esign-check { font-size: 9pt; font-weight: 600; color: #15803d; border: 1px dashed #86c79a; border-radius: 6px; padding: 2px 8px; }
.pp-esign-meta { font-size: 7.5pt; color: #555; font-variant-numeric: tabular-nums; }
.pp-sign-name { font-weight: 700; text-decoration: underline; }
.pp-sign-sub { font-size: 9pt; color: #333; }
</style>
