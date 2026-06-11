<script setup>
/**
 * PaketBedahDetailView — sub-page detail satu paket bedah.
 *
 * Route: /tarif-paket/paket-bedah/:id
 *
 * Layout: header info paket + 2 panel berdampingan
 *   - Kiri  : Komposisi Items (tabel dengan add/remove/edit qty)
 *   - Kanan : Tarif Jual per Penjamin (auto-diskon ditampilkan)
 *
 * Cara kerja diskon (backend): selisih total_base_price (Σ qty × default_price)
 * dengan sell_price per penjamin. Ditampilkan otomatis di kolom diskon.
 */
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTarifPaketStore } from '@/stores/tarifPaketStore'
import { masterApi, tarifPaketApi } from '@/services/api'
import MasterFormModal from '@/components/master-data/MasterFormModal.vue'

const route = useRoute()
const router = useRouter()
const store = useTarifPaketStore()

const paketId = computed(() => route.params.id)
const paket = computed(() => store.paketDetail)

// ─── Dropdown source ─────────────────────────────────────────────────────
// Item komposisi TIDAK lagi diprefetch (dulu cap per_page:500) — picker kini
// cari-server + paginasi (lihat searchItems). Hanya prefetch yg perlu daftar penuh:
//   procedures → combobox follow-up (konsultasi pasca-bedah)
//   insurers   → modal tarif per penjamin
const procedures = ref([])
const insurers = ref([])

async function loadDropdowns() {
  try {
    const [p, ins] = await Promise.all([
      masterApi.tindakan.list({ per_page: 500, active: 1 }),
      masterApi.penjamin({ per_page: 200 }),
    ])
    procedures.value  = p.data?.data?.data ?? p.data?.data ?? []
    insurers.value    = ins.data?.data?.data ?? ins.data?.data ?? []
  } catch (e) {
    showToast('e', 'Gagal memuat dropdown')
  }
}

// ─── Konstanta tipe item ─────────────────────────────────────────────────
const ITEM_TYPE_OPTIONS = [
  { value: 'PROCEDURE',  label: 'Tindakan' },
  { value: 'MEDICATION', label: 'Obat' },
  { value: 'BHP',        label: 'BHP' },
  { value: 'IOL',        label: 'IOL' },
]
const typeLabel = (t) => ITEM_TYPE_OPTIONS.find((o) => o.value === t)?.label ?? t

// ─── Kategori buku tarif (dropdown "Kategori" di picker item) ──────────────
// Kategori prosedur (master /master/tindakan/kategori-list) = kategori buku tarif
// untuk Tindakan (Konsultasi Dokter, Laboratorium, Sewa Kamar, dst). Obat/BHP/IOL
// ikut sebagai entri tipe tersendiri. 1 dropdown gabungan (pilihan user).
const procedureCategories = ref([])   // [{id, name, code_prefix}]
async function loadProcedureCategories() {
  try {
    const res = await masterApi.tindakan.kategoriList()
    procedureCategories.value = res.data?.data ?? []
  } catch { procedureCategories.value = [] }
}

// Opsi "Kategori": kategori prosedur buku tarif + Obat/BHP/IOL. PEMERIKSAAN = prosedur+Obat.
const categoryOptions = computed(() => {
  const isPemeriksaan = paket.value?.package_type === 'PEMERIKSAAN'
  const procCats = procedureCategories.value.map((c) => ({
    value: `PROC:${c.name}`, label: c.name, item_type: 'PROCEDURE', category: c.name,
  }))
  const typeOpts = [
    { value: 'TYPE:MEDICATION', label: 'Obat', item_type: 'MEDICATION', category: null },
    ...(isPemeriksaan ? [] : [
      { value: 'TYPE:BHP', label: 'BHP', item_type: 'BHP', category: null },
      { value: 'TYPE:IOL', label: 'IOL', item_type: 'IOL', category: null },
    ]),
  ]
  return [...procCats, ...typeOpts]
})
function categoryOptionByValue(v) {
  return categoryOptions.value.find((o) => o.value === v) ?? null
}
// Nilai dropdown kategori dari sebuah item komposisi (utk mode edit).
function categoryValueForItem(item) {
  if (item.item_type === 'PROCEDURE' && item.item_category) {
    const v = `PROC:${item.item_category}`
    if (categoryOptionByValue(v)) return v
  }
  return `TYPE:${item.item_type}`
}

// Map tipe item → tipe Buku Tarif (endpoint master-price) + master list API (cari-server).
const TARIF_TYPE_MAP  = { PROCEDURE: 'tindakan', MEDICATION: 'obat', BHP: 'bhp', IOL: 'iol' }
const MASTER_API_MAP  = {
  PROCEDURE:  (params) => masterApi.tindakan.list(params),
  MEDICATION: (params) => masterApi.obat.list(params),
  BHP:        (params) => masterApi.bhp.list(params),
  IOL:        (params) => masterApi.iol.list(params),
}

// Kategori internal BHP → label kategori tagihan (selaras BhpItem::billingCategoryLabel BE).
const BHP_BILLING_LABEL = { MEDICAL_BHP: 'BAHAN HABIS PAKAI', CSSD: 'CSSD', INSTRUMENT_SET: 'INSTRUMENT' }

// Normalisasi 1 baris master → opsi picker. Label format Buku Tarif: "Kategori - Nama Item".
function mapMasterRow(type, r) {
  if (type === 'IOL') {
    const nm = `${r.brand ?? ''} ${r.model ?? ''}${r.power != null ? ' ' + r.power + 'D' : ''}`.trim()
    const nameFull = nm + (r.serial_number ? ` (SN:${r.serial_number})` : '')
    return { id: r.id, name: nameFull, category: 'IOL', suggestedPrice: r.price }
  }
  // Obat: pos kwitansi per-tarif tak tersedia di master list → label generik 'Obat'.
  const cat = type === 'MEDICATION' ? 'Obat' : (type === 'BHP' ? (BHP_BILLING_LABEL[r.category] || 'BHP') : (r.category || ''))
  return { id: r.id, name: r.name, category: cat, suggestedPrice: r.base_price ?? r.price ?? 0 }
}

// ─── Modal Item (komposisi) — picker cari-server + halaman ────────────────
const itemModal = ref({
  open: false, mode: 'create', submitting: false, errors: null, editingId: null,
  item_type: 'PROCEDURE', item_id: '', item_label: '',
  quantity: 1, default_price: '', notes: '',
})
// Combobox pilih item: pencarian server + paginasi (TANPA cap "pakai page saja").
const itemPicker = ref({ search: '', open: false, loading: false, results: [], page: 1, lastPage: 1, total: 0 })
let itemSearchSeq = 0
let itemSearchTimer = null

function openAddItem() {
  const first = categoryOptions.value[0]
  itemModal.value = {
    open: true, mode: 'create', submitting: false, errors: null, editingId: null,
    category_value: first?.value ?? '',
    item_type: first?.item_type ?? 'PROCEDURE',
    category: first?.category ?? null,
    item_id: '', item_label: '',
    quantity: 1, default_price: '', notes: '',
  }
  resetItemPicker()
  searchItems(true)
}

function openEditItem(item) {
  itemModal.value = {
    open: true, mode: 'edit', submitting: false, errors: null, editingId: item.id,
    category_value: categoryValueForItem(item),
    item_type: item.item_type,
    category: item.item_type === 'PROCEDURE' ? (item.item_category || null) : null,
    item_id: item.item_id,
    item_label: `${item.item_category || typeLabel(item.item_type)} - ${item.item_name ?? '—'}`,
    quantity: item.quantity, default_price: item.default_price, notes: item.notes ?? '',
  }
  resetItemPicker()
}

function resetItemPicker() {
  itemPicker.value = { search: '', open: false, loading: false, results: [], page: 1, lastPage: 1, total: 0 }
}

async function searchItems(reset = true) {
  const type  = itemModal.value.item_type
  const apiFn = MASTER_API_MAP[type]
  if (!apiFn) return
  if (reset) { itemPicker.value.page = 1; itemPicker.value.results = [] }
  const seq = ++itemSearchSeq
  itemPicker.value.loading = true
  try {
    const params = { search: itemPicker.value.search || undefined, per_page: 25, page: itemPicker.value.page, active: 1 }
    if (type === 'IOL') { delete params.active; params.available_only = 1 }
    // Saring per kategori buku tarif (hanya Tindakan/PROCEDURE yang punya kategori master).
    if (type === 'PROCEDURE' && itemModal.value.category) params.category = itemModal.value.category
    const { data } = await apiFn(params)
    if (seq !== itemSearchSeq) return   // hasil basi (user sudah ketik lagi / ganti tipe)
    const pg   = data?.data ?? {}
    const rows = pg.data ?? (Array.isArray(pg) ? pg : [])
    const mapped = rows.map((r) => mapMasterRow(type, r))
    itemPicker.value.results  = reset ? mapped : [...itemPicker.value.results, ...mapped]
    itemPicker.value.lastPage = pg.last_page ?? 1
    itemPicker.value.total    = pg.total ?? itemPicker.value.results.length
  } catch {
    if (seq === itemSearchSeq) itemPicker.value.results = []
  } finally {
    if (seq === itemSearchSeq) itemPicker.value.loading = false
  }
}

function onItemSearchInput(e) {
  itemModal.value.item_id = ''
  itemPicker.value.search = e.target.value
  itemPicker.value.open = true
  clearTimeout(itemSearchTimer)
  itemSearchTimer = setTimeout(() => searchItems(true), 300)
}

function loadMoreItems() {
  if (itemPicker.value.page >= itemPicker.value.lastPage || itemPicker.value.loading) return
  itemPicker.value.page += 1
  searchItems(false)
}

async function pickItem(opt) {
  itemModal.value.item_id = opt.id
  itemModal.value.item_label = `${opt.category || typeLabel(itemModal.value.item_type)} - ${opt.name}`
  itemPicker.value.open = false
  // Auto-isi harga snapshot dari Buku Tarif UMUM (fallback harga master).
  const type = TARIF_TYPE_MAP[itemModal.value.item_type]
  try {
    const { data } = await tarifPaketApi.masterPrice(type, opt.id)
    const price = Number(data?.data?.price ?? 0)
    itemModal.value.default_price = price > 0 ? price : (Number(opt.suggestedPrice) || 0)
  } catch {
    itemModal.value.default_price = Number(opt.suggestedPrice) || 0
  }
}

function clearPickedItem() {
  itemModal.value.item_id = ''
  itemModal.value.item_label = ''
  itemModal.value.default_price = ''
  itemPicker.value.search = ''
  itemPicker.value.open = true
  searchItems(true)
}

function onCategoryChange() {
  const opt = categoryOptionByValue(itemModal.value.category_value)
  itemModal.value.item_type = opt?.item_type ?? 'PROCEDURE'
  itemModal.value.category  = opt?.category ?? null
  itemModal.value.item_id = ''
  itemModal.value.item_label = ''
  itemModal.value.default_price = ''
  resetItemPicker()
  searchItems(true)
}

function onItemPickerBlur() {
  // jeda agar klik opsi (mousedown) sempat diproses sebelum dropdown ditutup.
  setTimeout(() => { itemPicker.value.open = false }, 150)
}

async function onSubmitItem() {
  if (!itemModal.value.item_id) {
    itemModal.value.errors = { item_id: ['Pilih item dari daftar dulu'] }
    return
  }
  const qty   = Math.max(1, Number(itemModal.value.quantity) || 1)
  const price = Math.max(0, Number(itemModal.value.default_price) || 0)
  itemModal.value.submitting = true
  itemModal.value.errors = null
  try {
    if (itemModal.value.mode === 'create') {
      await store.addItem(paketId.value, {
        item_type: itemModal.value.item_type, item_id: itemModal.value.item_id,
        quantity: qty, default_price: price, notes: itemModal.value.notes || null,
      })
      showToast('s', 'Item ditambahkan')
    } else {
      // Edit boleh GANTI tipe+item (sesuai buku tarif), tak cuma qty/harga.
      await store.updateItem(paketId.value, itemModal.value.editingId, {
        item_type: itemModal.value.item_type, item_id: itemModal.value.item_id,
        quantity: qty, default_price: price, notes: itemModal.value.notes || null,
      })
      showToast('s', 'Item diperbarui')
    }
    itemModal.value.open = false
  } catch (e) {
    if (e.response?.status === 422) itemModal.value.errors = e.response.data?.errors ?? null
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan item')
  } finally {
    itemModal.value.submitting = false
  }
}

async function removeItem(item) {
  if (!confirm(`Hapus item ${item.item_name ?? '—'} dari paket?`)) return
  try {
    await store.removeItem(paketId.value, item.id)
    showToast('s', 'Item dihapus')
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus')
  }
}

// ─── Modal Tariff ────────────────────────────────────────────────────────
const tariffModal = ref({
  open: false,
  payload: { insurer_id: '', display_name: '', price_mode: 'NOMINAL', sell_price: 0, discount_percent: null, is_active: true },
  errors: null, submitting: false, editingId: null,
})

// Penjamin UMUM (sistem) — default saat tambah tarif karena edaran diskon khusus
// umumnya berlaku untuk pasien umum.
const umumInsurerId = computed(() =>
  insurers.value.find((i) => i.is_system && i.type === 'UMUM')?.id ?? '',
)

function openAddTariff() {
  tariffModal.value = {
    open: true,
    payload: { insurer_id: umumInsurerId.value, display_name: '', price_mode: 'NOMINAL', sell_price: paket.value?.total_base_price ?? 0, discount_percent: null, is_active: true },
    errors: null, submitting: false, editingId: null,
  }
}

function openEditTariff(t) {
  tariffModal.value = {
    open: true,
    payload: {
      insurer_id: t.insurer_id ?? '',
      display_name: t.display_name ?? '',
      price_mode: t.price_mode ?? 'NOMINAL',
      sell_price: t.sell_price,
      discount_percent: t.input_discount_pct ?? null,
      is_active: t.is_active,
    },
    errors: null, submitting: false, editingId: t.id,
  }
}

const tariffFields = computed(() => {
  // UMUM paling atas (default umum dipakai untuk diskon khusus pasien umum), lalu SEMUA, lalu sisanya.
  const umum = insurers.value.find((i) => i.is_system && i.type === 'UMUM')
  const others = insurers.value.filter((i) => !(i.is_system && i.type === 'UMUM'))
  const insurerOpts = [
    ...(umum ? [{ value: umum.id, label: `${umum.name} (default)` }] : []),
    { value: '', label: 'SEMUA penjamin' },
    ...others.map((i) => ({ value: i.id, label: i.name })),
  ]
  const base = Number(paket.value?.total_base_price ?? 0)
  const isPersen = tariffModal.value.payload.price_mode === 'PERSEN'
  const pct = Number(tariffModal.value.payload.discount_percent ?? 0)
  const hargaField = isPersen
    ? { key: 'discount_percent', label: '% Diskon dari base', type: 'number', required: true, min: 0, max: 100, cols: 2,
        hint: `Harga jual = Rp ${Math.round(base * (1 - pct / 100)).toLocaleString('id-ID')} (base Rp ${base.toLocaleString('id-ID')} − ${pct || 0}%)` }
    : { key: 'sell_price', label: 'Harga Jual (Rp)', type: 'number', required: true, min: 0, cols: 2,
        hint: `Base total paket: Rp ${base.toLocaleString('id-ID')} — selisih jadi diskon` }
  return [
    { key: 'insurer_id', label: 'Penjamin', type: 'select', options: insurerOpts, cols: 2,
      hint: 'Kosongkan = default semua penjamin. Pilih UMUM untuk harga/nama khusus pasien umum (surat edaran).' },
    { key: 'display_name', label: 'Nama Tampil (opsional)', type: 'text', cols: 2,
      hint: 'mis. "Promo Operasi Katarak" — muncul di kwitansi, pemilihan dokter & papan bedah. Kosong = pakai nama paket.' },
    { key: 'price_mode', label: 'Cara Set Harga', type: 'select', cols: 2,
      options: [{ value: 'NOMINAL', label: 'Harga Nominal' }, { value: 'PERSEN', label: '% Diskon dari base' }] },
    hargaField,
    { key: 'is_active', label: 'Aktif', type: 'checkbox', cols: 1 },
  ]
})

async function onSubmitTariff(payload) {
  tariffModal.value.submitting = true
  tariffModal.value.errors = null
  try {
    // id = varian yg diedit (kini 1 penjamin boleh >1 varian). Tambah baru → tanpa id.
    const data = {
      ...payload,
      id: tariffModal.value.editingId || undefined,
      insurer_id: payload.insurer_id || null,
      display_name: payload.display_name?.trim() || null,
    }
    await store.upsertTariff(paketId.value, data)
    showToast('s', 'Tarif paket disimpan')
    tariffModal.value.open = false
  } catch (e) {
    if (e.response?.status === 422) tariffModal.value.errors = e.response.data?.errors ?? null
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan tarif')
  } finally {
    tariffModal.value.submitting = false
  }
}

async function removeTariff(t) {
  if (!confirm(`Hapus tarif untuk ${t.insurer?.name ?? 'SEMUA'}?`)) return
  try {
    await store.removeTariff(paketId.value, t.id)
    showToast('s', 'Tarif dihapus')
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus')
  }
}

// ─── Derived ─────────────────────────────────────────────────────────────
const itemsEnriched = computed(() => {
  return (paket.value?.items ?? []).map((it) => {
    // Backend showPaket() sudah enrich item_name/item_code/subtotal
    return {
      ...it,
      _type_label: ITEM_TYPE_OPTIONS.find((t) => t.value === it.item_type)?.label ?? it.item_type,
    }
  })
})

const tariffsRaw = computed(() => paket.value?.package_tariffs ?? paket.value?.packageTariffs ?? [])

const formatRp = (v) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID')

const toast = ref(null)
function showToast(t, msg) {
  toast.value = { type: t, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

// ─── CSV per-paket (template / export / import komposisi paket ini) ─────────
const csvBusy = ref(false)
const csvFileInput = ref(null)
const openMenu = ref(null)   // 'template' | 'export' | null

function toggleMenu(name) {
  openMenu.value = openMenu.value === name ? null : name
}
function closeMenuOnOutside(e) {
  if (!e.target.closest?.('.pd-split')) openMenu.value = null
}

async function onDownloadTemplate(format = 'csv') {
  openMenu.value = null
  csvBusy.value = true
  try {
    await store.downloadPaketTemplateOne(paketId.value, paket.value?.name, format)
    showToast('s', `Template komposisi diunduh (${format.toUpperCase()})`)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal mengunduh template')
  } finally { csvBusy.value = false }
}

async function onExportCsv(format = 'csv') {
  openMenu.value = null
  csvBusy.value = true
  try {
    await store.exportPaketCsvOne(paketId.value, paket.value?.name, format)
    showToast('s', `Komposisi paket diunduh (${format.toUpperCase()})`)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal mengunduh')
  } finally { csvBusy.value = false }
}

function triggerImport() {
  csvFileInput.value?.click()
}

async function onImportFile(e) {
  const file = e.target.files?.[0]
  e.target.value = '' // reset supaya bisa pilih file sama lagi
  if (!file) return
  if (!confirm(`Ganti komposisi paket "${paket.value?.name}" dari file ini? Item lama akan diganti (tarif jual per penjamin tidak berubah).`)) return
  csvBusy.value = true
  try {
    const res = await store.importPaketCsvOne(file, paketId.value)
    const fail = res?.items_lookup_fail ?? 0
    const ins = res?.items_inserted ?? 0
    showToast(fail ? 'w' : 's', `Komposisi diperbarui: ${ins} item${fail ? `, ${fail} gagal lookup` : ''}.`)
    if (res?.errors?.length) {
      console.warn('[import paket]', res.errors)
    }
  } catch (err) {
    showToast('e', err.response?.data?.message ?? 'Gagal import komposisi')
  } finally { csvBusy.value = false }
}

// ─── Manfaat Kontrol Pasca-Bedah (Opsi B) ─────────────────────────────────
// Paket bisa memberi KONSULTASI GRATIS saat pasien kontrol pasca-operasi. Diisi =
// paket dapat manfaat; dikosongkan = tidak. Tersimpan di kolom followup_* paket.
const followup = ref({ procedure_id: '', count: 1, valid_days: '' })
const followupSaving = ref(false)
// Combobox cari prosedur konsultasi (296 prosedur → dropdown polos tak praktis).
const followupSearch = ref('')
const followupOpen = ref(false)

watch(paket, (p) => {
  if (!p) return
  followup.value = {
    procedure_id: p.followup_procedure_id ?? '',
    count: p.followup_count || 1,
    valid_days: p.followup_valid_days ?? '',
  }
  syncFollowupSearch()
}, { immediate: true })

const followupDirty = computed(() => {
  const p = paket.value
  if (!p) return false
  const curId = p.followup_procedure_id ?? ''
  const curCount = p.followup_count || (curId ? 1 : 1)
  const curDays = p.followup_valid_days ?? ''
  return followup.value.procedure_id !== curId
    || (!!followup.value.procedure_id && Number(followup.value.count) !== Number(curCount))
    || String(followup.value.valid_days ?? '') !== String(curDays)
})

async function saveFollowup() {
  followupSaving.value = true
  try {
    await store.updatePaket(paketId.value, {
      followup_procedure_id: followup.value.procedure_id || null,
      followup_count: followup.value.procedure_id ? Math.max(1, Number(followup.value.count) || 1) : 0,
      followup_valid_days: followup.value.valid_days === '' || followup.value.valid_days === null
        ? null : Math.max(0, Number(followup.value.valid_days)),
    })
    await store.fetchPaketDetail(paketId.value)
    showToast('s', 'Manfaat kontrol pasca-bedah disimpan')
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan manfaat')
  } finally { followupSaving.value = false }
}

// ─── Combobox prosedur konsultasi: search + pilih ─────────────────────────
function selectedFollowupName() {
  return procedures.value.find((p) => p.id === followup.value.procedure_id)?.name ?? ''
}
function syncFollowupSearch() { followupSearch.value = selectedFollowupName() }

const followupFiltered = computed(() => {
  const q = followupSearch.value.trim().toLowerCase()
  const list = q
    ? procedures.value.filter((p) => `${p.name} ${p.category ?? ''}`.toLowerCase().includes(q))
    : procedures.value
  return list.slice(0, 50)
})

function pickFollowup(p) {
  followup.value.procedure_id = p ? p.id : ''
  followupSearch.value = p ? p.name : ''
  followupOpen.value = false
}
function onFollowupBlur() {
  // jeda agar klik item (mousedown) sempat diproses, lalu samakan teks dgn pilihan.
  setTimeout(() => {
    followupOpen.value = false
    if (followupSearch.value !== selectedFollowupName()) syncFollowupSearch()
  }, 150)
}

// Saat daftar prosedur termuat (loadDropdowns), tampilkan nama prosedur terpilih.
watch(procedures, () => { if (!followupOpen.value) syncFollowupSearch() })

onMounted(async () => {
  document.addEventListener('click', closeMenuOnOutside)
  await Promise.all([store.fetchPaketDetail(paketId.value), loadDropdowns(), loadProcedureCategories()])
})
onUnmounted(() => document.removeEventListener('click', closeMenuOnOutside))

watch(paketId, async (id) => {
  if (id) await store.fetchPaketDetail(id)
})
</script>

<template>
  <div class="pd-wrap">
    <!-- Back button -->
    <button class="pd-back" @click="router.push('/tarif-paket/paket-bedah')">
      <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
      Kembali ke daftar paket
    </button>

    <!-- Loading / Error -->
    <div v-if="store.paketDetailLoading" class="pd-loading">Memuat detail paket…</div>
    <div v-else-if="store.paketDetailError" class="pd-error">{{ store.paketDetailError }}</div>

    <template v-else-if="paket">
      <!-- Header info paket -->
      <header class="pd-head">
        <div class="pd-head-info">
          <h2>{{ paket.name }}</h2>
          <div class="pd-meta">
            <span v-if="paket.category" class="pd-meta-pill">{{ paket.category }}</span>
            <span class="pd-meta-pill" :class="paket.is_active ? 'on' : 'off'">{{ paket.is_active ? 'Aktif' : 'Nonaktif' }}</span>
          </div>
          <p v-if="paket.description" class="pd-desc">{{ paket.description }}</p>
        </div>
        <div class="pd-head-stats">
          <div class="pd-stat">
            <span class="pd-stat-label">Total Base Price</span>
            <span class="pd-stat-value">{{ formatRp(paket.total_base_price) }}</span>
            <span class="pd-stat-sub">Σ qty × harga snapshot per item</span>
          </div>
        </div>
      </header>

      <!-- MANFAAT KONTROL PASCA-BEDAH (Opsi B) — hanya paket BEDAH -->
      <section v-if="paket.package_type !== 'PEMERIKSAAN'" class="pd-panel pd-followup">
        <div class="pd-panel-head">
          <div>
            <h3>🎁 Manfaat Kontrol Pasca-Bedah</h3>
            <p>Konsultasi gratis saat pasien <strong>kontrol</strong> setelah operasi. Tebusan otomatis di Kasir (penjamin UMUM). Kosongkan prosedur = paket ini tanpa manfaat.</p>
          </div>
        </div>
        <div class="pd-fu-row">
          <label class="pd-fu-field pd-fu-grow">
            <span>Konsultasi gratis</span>
            <div class="pd-fu-combo">
              <input
                v-model="followupSearch"
                type="text"
                class="pd-fu-input"
                placeholder="Cari prosedur… (kosongkan = tanpa manfaat)"
                autocomplete="off"
                @focus="followupOpen = true"
                @input="followupOpen = true"
                @blur="onFollowupBlur"
              />
              <button v-if="followup.procedure_id" type="button" class="pd-fu-clear"
                      title="Hapus pilihan" @mousedown.prevent="pickFollowup(null)">×</button>
              <ul v-if="followupOpen" class="pd-fu-drop">
                <li v-if="!followupFiltered.length" class="pd-fu-empty">Tidak ada prosedur cocok</li>
                <li v-for="p in followupFiltered" :key="p.id"
                    :class="{ sel: p.id === followup.procedure_id }"
                    @mousedown.prevent="pickFollowup(p)">
                  <span>{{ p.name }}</span>
                  <small v-if="p.category">{{ p.category }}</small>
                </li>
              </ul>
            </div>
          </label>
          <label class="pd-fu-field">
            <span>Jumlah / operasi</span>
            <input v-model.number="followup.count" type="number" min="1" max="20"
                   class="pd-fu-input" :disabled="!followup.procedure_id" />
          </label>
          <label class="pd-fu-field">
            <span>Masa berlaku (hari)</span>
            <input v-model="followup.valid_days" type="number" min="0" placeholder="tanpa batas"
                   class="pd-fu-input" :disabled="!followup.procedure_id" />
          </label>
          <button class="pd-btn-primary pd-fu-save" :disabled="followupSaving || !followupDirty" @click="saveFollowup">
            {{ followupSaving ? 'Menyimpan…' : 'Simpan' }}
          </button>
        </div>
        <p v-if="paket.followup_procedure_id" class="pd-fu-active">
          Aktif: <strong>{{ paket.followup_procedure_name || 'Konsultasi' }}</strong>
          gratis {{ paket.followup_count }}× per operasi
          <template v-if="paket.followup_valid_days">· berlaku {{ paket.followup_valid_days }} hari</template>
          <template v-else>· tanpa batas waktu</template>
        </p>
      </section>

      <div class="pd-grid">
        <!-- ITEMS PANEL -->
        <section class="pd-panel">
          <div class="pd-panel-head">
            <div>
              <h3>Komposisi Items</h3>
              <p>Tindakan / Obat / BHP / IOL — harga snapshot saat ditambahkan.</p>
            </div>
            <div class="pd-head-actions">
              <div class="pd-split">
                <button class="pd-btn-ghost" :disabled="csvBusy" title="Unduh template komposisi paket ini (terisi) — pilih format" @click.stop="toggleMenu('template')">
                  <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                  Template
                  <svg class="pd-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div v-if="openMenu === 'template'" class="pd-menu">
                  <button @click="onDownloadTemplate('csv')">CSV (.csv)</button>
                  <button @click="onDownloadTemplate('xlsx')">Excel (.xlsx)</button>
                </div>
              </div>
              <div class="pd-split">
                <button class="pd-btn-ghost" :disabled="csvBusy || !itemsEnriched.length" title="Unduh komposisi paket ini — pilih format" @click.stop="toggleMenu('export')">
                  <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  Export
                  <svg class="pd-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div v-if="openMenu === 'export'" class="pd-menu">
                  <button @click="onExportCsv('csv')">CSV (.csv)</button>
                  <button @click="onExportCsv('xlsx')">Excel (.xlsx)</button>
                </div>
              </div>
              <button class="pd-btn-ghost" :disabled="csvBusy" title="Ganti komposisi paket ini dari CSV/Excel" @click="triggerImport">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Import
              </button>
              <input ref="csvFileInput" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" style="display:none" @change="onImportFile" />
              <button class="pd-btn-primary" @click="openAddItem">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Tambah Item
              </button>
            </div>
          </div>

          <table v-if="itemsEnriched.length" class="pd-table">
            <thead>
              <tr><th>Tipe</th><th>Item</th><th class="r">Qty</th><th class="r">Harga</th><th class="r">Subtotal</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="it in itemsEnriched" :key="it.id">
                <!-- Pill TIPE = kategori Buku Tarif (sama dgn grouping kwitansi); warna tetap per tipe item. -->
                <td><span class="pd-type-pill" :data-t="it.item_type">{{ it.item_category || it._type_label }}</span></td>
                <td>
                  <div class="pd-item-cell">
                    <strong>{{ it.item_name || '—' }}</strong>
                  </div>
                </td>
                <td class="r">{{ it.quantity }}</td>
                <td class="r">{{ formatRp(it.default_price) }}</td>
                <td class="r"><strong>{{ formatRp(it.subtotal) }}</strong></td>
                <td class="r" style="white-space:nowrap">
                  <button class="pd-icon-btn" title="Edit qty/harga" @click="openEditItem(it)">
                    <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                  </button>
                  <button class="pd-icon-btn pd-icon-danger" title="Hapus" @click="removeItem(it)">
                    <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                  </button>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr><td colspan="4" class="r"><strong>Base Total</strong></td><td class="r"><strong>{{ formatRp(paket.total_base_price) }}</strong></td><td></td></tr>
            </tfoot>
          </table>
          <div v-else class="pd-empty">Belum ada item. Klik <strong>Tambah Item</strong> untuk mulai mengisi komposisi paket.</div>
        </section>

        <!-- TARIFFS PANEL -->
        <section class="pd-panel">
          <div class="pd-panel-head">
            <div>
              <h3>Tarif Jual per Penjamin</h3>
              <p>Diskon otomatis: <em>Base Total − Harga Jual</em>. 1 penjamin boleh <strong>beberapa varian</strong> (beda "Nama Tampil", mis. Phaco Mandalika / Osaka).</p>
            </div>
            <button class="pd-btn-primary" @click="openAddTariff" :disabled="!itemsEnriched.length" :title="!itemsEnriched.length ? 'Isi komposisi item dulu' : ''">
              <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Tambah Tarif
            </button>
          </div>

          <table v-if="tariffsRaw.length" class="pd-table">
            <thead>
              <tr><th>Penjamin</th><th>Nama Tampil</th><th class="r">Harga Jual</th><th class="r">Diskon</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="t in tariffsRaw" :key="t.id">
                <td>
                  <span class="pd-insurer-pill" :class="{ all: !t.insurer_id }">{{ t.insurer?.name ?? 'SEMUA' }}</span>
                </td>
                <td>
                  <span v-if="t.display_name" class="pd-name-alt">{{ t.display_name }}</span>
                  <span v-else class="pd-no-disc">— nama paket —</span>
                </td>
                <td class="r">
                  <strong>{{ formatRp(t.sell_price) }}</strong>
                  <span v-if="t.price_mode === 'PERSEN'" class="pd-mode-pill" :title="`Diskon ${t.input_discount_pct}% dari base`">−{{ Number(t.input_discount_pct) }}%</span>
                </td>
                <td class="r">
                  <div v-if="t.discount_amount > 0" class="pd-disc">
                    <span class="pd-disc-amt">−{{ formatRp(t.discount_amount) }}</span>
                    <span class="pd-disc-pct">({{ t.discount_percent }}%)</span>
                  </div>
                  <span v-else class="pd-no-disc">—</span>
                </td>
                <td><span class="pd-status" :class="t.is_active ? 'on' : 'off'">{{ t.is_active ? 'Aktif' : 'Off' }}</span></td>
                <td class="r" style="white-space:nowrap">
                  <button class="pd-icon-btn" title="Edit" @click="openEditTariff(t)">
                    <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                  </button>
                  <button class="pd-icon-btn pd-icon-danger" title="Hapus" @click="removeTariff(t)">
                    <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-else class="pd-empty">Belum ada tarif jual. Klik <strong>Tambah Tarif</strong> untuk set harga per penjamin.</div>
        </section>
      </div>
    </template>

    <!-- Modal Item (komposisi) — picker cari-server "Kategori - Nama Item" -->
    <Teleport to="body">
      <div v-if="itemModal.open" class="pdm-overlay" @click.self="itemModal.open = false">
        <div class="pdm-modal">
          <header class="pdm-head">
            <h3>{{ itemModal.mode === 'create' ? 'Tambah Item ke Paket' : 'Edit Item Paket' }}</h3>
            <button class="pdm-x" @click="itemModal.open = false" aria-label="Tutup">×</button>
          </header>
          <div class="pdm-body">
            <label class="pdm-field">
              <span>Kategori <small class="pdm-hint-inline">(sesuai buku tarif)</small></span>
              <select v-model="itemModal.category_value" @change="onCategoryChange">
                <option v-for="o in categoryOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </label>

            <label class="pdm-field">
              <span>Item <small class="pdm-hint-inline">(format buku tarif: Kategori - Nama Item)</small></span>
              <div class="pdm-combo">
                <input
                  type="text"
                  :value="itemModal.item_id ? itemModal.item_label : itemPicker.search"
                  :placeholder="itemModal.item_id ? '' : 'Cari item dari buku tarif…'"
                  autocomplete="off"
                  @focus="itemPicker.open = true; itemPicker.results.length || searchItems(true)"
                  @input="onItemSearchInput"
                  @blur="onItemPickerBlur"
                />
                <button v-if="itemModal.item_id" type="button" class="pdm-clear" title="Hapus pilihan" @mousedown.prevent="clearPickedItem">×</button>
                <ul v-if="itemPicker.open" class="pdm-drop">
                  <li v-if="itemPicker.loading && !itemPicker.results.length" class="pdm-drop-info">Memuat…</li>
                  <li v-else-if="!itemPicker.results.length" class="pdm-drop-info">Tidak ada item cocok</li>
                  <li v-for="opt in itemPicker.results" :key="opt.id" @mousedown.prevent="pickItem(opt)">
                    <strong>{{ opt.name }}</strong>
                    <small>{{ opt.category || typeLabel(itemModal.item_type) }}</small>
                  </li>
                  <li v-if="itemPicker.page < itemPicker.lastPage" class="pdm-more" @mousedown.prevent="loadMoreItems">
                    {{ itemPicker.loading ? 'Memuat…' : `Muat lebih banyak (${itemPicker.results.length}/${itemPicker.total})` }}
                  </li>
                </ul>
              </div>
            </label>

            <div class="pdm-grid2">
              <label class="pdm-field">
                <span>Jumlah</span>
                <input type="number" min="1" v-model.number="itemModal.quantity" />
              </label>
              <label class="pdm-field">
                <span>Harga Snapshot (Rp)</span>
                <input type="number" min="0" v-model.number="itemModal.default_price" />
              </label>
            </div>

            <label class="pdm-field">
              <span>Catatan</span>
              <input type="text" v-model="itemModal.notes" placeholder="opsional" />
            </label>

            <p v-if="itemModal.errors" class="pdm-err">{{ Object.values(itemModal.errors).flat().join(', ') }}</p>
          </div>
          <footer class="pdm-foot">
            <button class="pd-btn-ghost" @click="itemModal.open = false">Batal</button>
            <button class="pd-btn-primary" :disabled="itemModal.submitting" @click="onSubmitItem">
              {{ itemModal.submitting ? 'Menyimpan…' : (itemModal.mode === 'create' ? 'Tambah' : 'Simpan') }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <MasterFormModal
      v-model:open="tariffModal.open"
      v-model="tariffModal.payload"
      :title="tariffModal.editingId ? 'Edit Tarif Paket' : 'Tambah Tarif Paket'"
      :fields="tariffFields"
      :submitting="tariffModal.submitting"
      :errors="tariffModal.errors"
      submit-label="Simpan Tarif"
      width="560px"
      @submit="onSubmitTariff"
    />

    <Teleport to="body">
      <div v-if="toast" class="pd-toast-wrap">
        <div class="pd-toast" :class="`pd-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.pd-wrap { display: flex; flex-direction: column; gap: 1rem; }

.pd-back { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 12px; font-weight: 500; cursor: pointer; align-self: flex-start; transition: background 0.15s, color 0.15s; }
.pd-back:hover { background: var(--gl); color: var(--td); border-color: var(--ga); }
.pd-back svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

.pd-loading, .pd-error { padding: 2rem; text-align: center; color: var(--tm); font-size: 13px; }
.pd-error { color: var(--et); }

.pd-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1.5rem; padding: 1.2rem 1.3rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 12px; flex-wrap: wrap; }
.pd-head-info h2 { font-family: 'Space Grotesk', serif; font-size: 22px; color: var(--td); margin: 0 0 6px; }
.pd-meta { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; }
.pd-meta-pill { display: inline-block; padding: 3px 9px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--bc); color: var(--tm); border: 1px solid var(--gb); }
.pd-meta-pill.on { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.pd-meta-pill.off { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.pd-desc { font-size: 13px; color: var(--tm); margin: 0; max-width: 600px; }

.pd-head-stats { display: flex; gap: 1rem; }
.pd-stat { background: var(--bc); border: 1px solid var(--gb); border-radius: 10px; padding: 0.8rem 1.1rem; min-width: 200px; display: flex; flex-direction: column; gap: 2px; }
.pd-stat-label { font-size: 10.5px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: 0.06em; }
.pd-stat-value { font-size: 20px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.pd-stat-sub { font-size: 11px; color: var(--tu); }

.pd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; align-items: start; }
@media (max-width: 1100px) { .pd-grid { grid-template-columns: 1fr; } }

.pd-panel { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 1rem 1.1rem; display: flex; flex-direction: column; gap: 0.8rem; }

/* Manfaat Kontrol Pasca-Bedah (Opsi B) */
.pd-followup { margin-bottom: 1rem; border-left: 3px solid var(--ga); }
.pd-fu-row { display: flex; align-items: flex-end; gap: 0.8rem; flex-wrap: wrap; }
.pd-fu-field { display: flex; flex-direction: column; gap: 4px; }
.pd-fu-field > span { font-size: 11px; color: var(--tm); font-weight: 500; }
.pd-fu-grow { flex: 1 1 240px; min-width: 200px; }
.pd-fu-input { padding: 7px 10px; border: 1px solid var(--gb); border-radius: 8px; font-size: 13px; background: var(--bc); color: var(--td); width: 100%; }
.pd-fu-field:not(.pd-fu-grow) .pd-fu-input { width: 120px; }
.pd-fu-input:disabled { opacity: 0.5; background: var(--gb); }
/* Combobox cari prosedur konsultasi */
.pd-fu-combo { position: relative; width: 100%; }
.pd-fu-combo .pd-fu-input { padding-right: 28px; }
.pd-fu-clear { position: absolute; top: 7px; right: 8px; width: 18px; height: 18px; border: none; background: var(--gb); color: var(--td); border-radius: 50%; font-size: 13px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.pd-fu-clear:hover { background: var(--ga); color: #fff; }
.pd-fu-drop { position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 60; max-height: 248px; overflow-y: auto; margin: 0; padding: 4px; list-style: none; background: var(--bc); border: 1px solid var(--gb); border-radius: 9px; box-shadow: 0 8px 24px rgba(0,0,0,0.14); }
.pd-fu-drop li { padding: 6px 10px; border-radius: 6px; font-size: 12.5px; color: var(--td); cursor: pointer; }
.pd-fu-drop li:hover { background: var(--gl); color: var(--ga); }
.pd-fu-drop li.sel { background: color-mix(in srgb, var(--ga) 12%, transparent); color: var(--ga); font-weight: 500; }
.pd-fu-drop li small { display: block; font-size: 10.5px; color: var(--tm); }
.pd-fu-drop li:hover small, .pd-fu-drop li.sel small { color: var(--ga); }
.pd-fu-empty, .pd-fu-empty:hover { color: var(--tm); cursor: default; background: transparent; }
.pd-fu-save { height: 36px; }
.pd-fu-active { font-size: 12px; color: var(--ga); margin: 0; background: color-mix(in srgb, var(--ga) 8%, transparent); padding: 6px 10px; border-radius: 8px; }
.pd-panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.pd-panel-head h3 { font-family: 'Space Grotesk', serif; font-size: 16px; color: var(--td); margin: 0; }
.pd-panel-head p { font-size: 12px; color: var(--tm); margin: 2px 0 0; }

.pd-btn-primary { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 8px; border: 1px solid var(--ga); background: var(--ga); color: white; font-size: 12px; font-weight: 500; cursor: pointer; flex-shrink: 0; }
.pd-btn-primary:hover:not(:disabled) { background: var(--gm); border-color: var(--gm); }
.pd-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.pd-btn-primary svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }

.pd-head-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }
.pd-btn-ghost { display: inline-flex; align-items: center; gap: 5px; padding: 7px 11px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--td); font-size: 12px; font-weight: 500; cursor: pointer; }
.pd-btn-ghost:hover:not(:disabled) { background: var(--gl); border-color: var(--ga); color: var(--td); }
.pd-btn-ghost:disabled { opacity: 0.45; cursor: not-allowed; }
.pd-btn-ghost svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.pd-split { position: relative; }
.pd-caret { width: 11px !important; height: 11px !important; margin-left: 1px; }
.pd-menu { position: absolute; top: calc(100% + 4px); right: 0; z-index: 50; background: var(--bc); border: 1px solid var(--gb); border-radius: 9px; box-shadow: 0 8px 24px rgba(0,0,0,0.14); padding: 4px; min-width: 140px; display: flex; flex-direction: column; gap: 2px; }
.pd-menu button { text-align: left; padding: 7px 10px; border: none; background: transparent; border-radius: 6px; font-size: 12px; color: var(--td); cursor: pointer; }
.pd-menu button:hover { background: var(--gl); color: var(--ga); }

.pd-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.pd-table th, .pd-table td { padding: 8px 10px; text-align: left; border-bottom: 1px solid var(--gb); }
.pd-table th { font-size: 11px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: 0.04em; background: var(--bs); }
.pd-table .r { text-align: right; }
.pd-table tfoot td { background: var(--bs); padding: 10px; border-top: 2px solid var(--gb); font-size: 13px; }

.pd-type-pill { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 600; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.pd-type-pill[data-t="PROCEDURE"]  { background: var(--gl); color: var(--td); border-color: var(--ga); }
.pd-type-pill[data-t="MEDICATION"] { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.pd-type-pill[data-t="BHP"]        { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.pd-type-pill[data-t="IOL"]        { background: var(--pb); color: var(--pt); border-color: var(--pbd); }

.pd-item-cell { display: flex; flex-direction: column; gap: 1px; }
.pd-item-cell strong { font-weight: 500; color: var(--td); }
.pd-item-cat { font-size: 10.5px; color: var(--tu); }

/* Modal Item kustom (picker cari-server) */
.pdm-overlay { position: fixed; inset: 0; z-index: 9000; background: rgba(0,0,0,0.42); display: flex; align-items: flex-start; justify-content: center; padding: 6vh 1rem; }
.pdm-modal { width: 560px; max-width: 100%; background: var(--bc); border: 1px solid var(--gb); border-radius: 14px; box-shadow: 0 16px 48px rgba(0,0,0,0.22); display: flex; flex-direction: column; max-height: 88vh; }
.pdm-head { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.2rem; border-bottom: 1px solid var(--gb); }
.pdm-head h3 { font-family: 'Space Grotesk', serif; font-size: 16px; color: var(--td); margin: 0; }
.pdm-x { width: 30px; height: 30px; border: none; background: transparent; color: var(--tm); font-size: 22px; line-height: 1; cursor: pointer; border-radius: 8px; }
.pdm-x:hover { background: var(--gl); color: var(--td); }
.pdm-body { padding: 1.1rem 1.2rem; display: flex; flex-direction: column; gap: 0.85rem; overflow-y: auto; }
.pdm-field { display: flex; flex-direction: column; gap: 5px; }
.pdm-field > span { font-size: 12px; color: var(--tm); font-weight: 500; }
.pdm-hint-inline { font-weight: 400; color: var(--tu); font-size: 10.5px; }
.pdm-field select, .pdm-field input, .pdm-readonly { padding: 8px 11px; border: 1px solid var(--gb); border-radius: 9px; font-size: 13px; background: var(--bc); color: var(--td); width: 100%; }
.pdm-field select:disabled { opacity: 0.6; background: var(--bs); }
.pdm-readonly { background: var(--bs); color: var(--tm); }
.pdm-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem; }
.pdm-combo { position: relative; }
.pdm-combo input { padding-right: 30px; }
.pdm-clear { position: absolute; top: 8px; right: 9px; width: 18px; height: 18px; border: none; background: var(--gb); color: var(--td); border-radius: 50%; font-size: 13px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.pdm-clear:hover { background: var(--ga); color: #fff; }
.pdm-drop { position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 60; max-height: 260px; overflow-y: auto; margin: 0; padding: 4px; list-style: none; background: var(--bc); border: 1px solid var(--gb); border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,0.16); }
.pdm-drop li { padding: 7px 10px; border-radius: 7px; font-size: 12.5px; color: var(--td); cursor: pointer; }
.pdm-drop li:hover { background: var(--gl); color: var(--ga); }
.pdm-drop li strong { display: block; font-weight: 500; }
.pdm-drop li small { display: block; font-size: 10.5px; color: var(--tm); }
.pdm-drop li:hover small { color: var(--ga); }
.pdm-drop-info, .pdm-drop-info:hover { color: var(--tm); cursor: default; background: transparent; }
.pdm-more { text-align: center; color: var(--ga); font-weight: 500; font-size: 12px !important; border-top: 1px dashed var(--gb); margin-top: 2px; }
.pdm-more:hover { background: var(--gl); }
.pdm-err { color: var(--et); font-size: 12px; margin: 0; background: var(--eb); border: 1px solid var(--ebd); padding: 7px 10px; border-radius: 8px; }
.pdm-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 0.9rem 1.2rem; border-top: 1px solid var(--gb); }

.pd-insurer-pill { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.pd-insurer-pill.all { background: var(--bs); color: var(--tm); border-color: var(--gb); }
.pd-class-pill { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 600; }
.pd-class-pill[data-c="UMUM"] { background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.pd-class-pill[data-c="BPJS"] { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.pd-class-pill[data-c="ASURANSI"] { background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.pd-class-pill[data-c="PERUSAHAAN"] { background: var(--pb); color: var(--pt); border: 1px solid var(--pbd); }
.pd-class-pill[data-c="SOSIAL"] { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

.pd-disc { display: flex; flex-direction: column; gap: 1px; }
.pd-disc-amt { font-weight: 600; color: var(--et); font-variant-numeric: tabular-nums; }
.pd-disc-pct { font-size: 10.5px; color: var(--tu); }
.pd-no-disc { color: var(--tu); font-size: 12px; font-style: italic; }
.pd-name-alt { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11.5px; font-weight: 500; background: var(--gl); color: var(--ga); border: 1px solid var(--gb); }
.pd-mode-pill { margin-left: 6px; padding: 1px 6px; border-radius: 999px; font-size: 10px; font-weight: 600; background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); vertical-align: middle; }

.pd-status { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 600; }
.pd-status.on { background: var(--sb); color: var(--st); }
.pd-status.off { background: var(--eb); color: var(--et); }

.pd-icon-btn { width: 26px; height: 26px; border-radius: 6px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; margin-left: 3px; }
.pd-icon-btn svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.pd-icon-btn:hover { background: var(--gl); color: var(--td); border-color: var(--ga); }
.pd-icon-danger:hover { background: var(--eb); color: var(--et); border-color: var(--ebd); }

.pd-empty { padding: 1.8rem 1rem; text-align: center; color: var(--tu); font-size: 12.5px; background: var(--bs); border-radius: 8px; }

.pd-toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
.pd-toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; animation: pd-toast-in 0.2s ease; }
.pd-toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.pd-toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
@keyframes pd-toast-in { from { transform: translateY(-8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
</style>
