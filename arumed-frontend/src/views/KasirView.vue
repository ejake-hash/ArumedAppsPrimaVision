<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { kasirApi, masterApi } from '@/services/api'
import PatientAvatar from '@/components/common/PatientAvatar.vue'

// ─── Master kategori tagihan (grouping order) ────────────────────────────────
// Daftar kategori aktif di master billing_categories — dipakai untuk grouping
// & urutan section di Rincian Tagihan. Kategori yang dipakai item tapi TIDAK
// terdaftar di master → otomatis masuk bucket "Lainnya" di akhir.
const billingCategories = ref([])
async function fetchBillingCategories() {
  try {
    const { data } = await masterApi.kategoriTagihan.list({ active: 1 })
    const rows = Array.isArray(data.data) ? data.data : (data.data?.data ?? [])
    billingCategories.value = rows
  } catch (err) {
    // Non-fatal; fallback ke urutan default (string asc).
    billingCategories.value = []
  }
}

// ─── Antrean Kasir ──────────────────────────────────────────────────────────
const queue        = ref([])
const queueLoading = ref(false)
const qPrimaryFilter   = ref('waiting') // 'waiting' (belum bayar) | 'done' (lunas)
const qSecondaryFilter = ref('semua')   // 'semua' | 'bpjs' | 'umum'
const qSearch          = ref('')
const pendingCallIds   = ref([])
const pendingSkipIds   = ref([])

function ptypeOf(q) {
  const g = (q.visit?.guarantor_type ?? '').toUpperCase()
  if (g === 'BPJS') return 'bpjs'
  if (g === 'ASURANSI' || g === 'PERUSAHAAN') return 'asn'
  return 'umum'
}
function isLunas(q) {
  return q.status === 'COMPLETED' || q.visit?.billing_invoice?.status === 'PAID'
}
function formatTime(ts) {
  if (!ts) return '--:--'
  const d = new Date(ts)
  return Number.isNaN(d.getTime()) ? '--:--'
    : d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false })
}
// Tanggal lahir → DD/MM/YYYY (sumber Y-m-d). '' bila kosong/invalid.
function formatDob(v) {
  if (!v) return ''
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return ''
  const dd = String(d.getDate()).padStart(2, '0')
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  return `${dd}/${mm}/${d.getFullYear()}`
}

// Baris antrean dibuat hari ini? (tagihan lintas-hari yang belum lunas → "Masih Aktif")
function isTodayRow(c) {
  if (!c) return true
  const d = new Date(c), n = new Date()
  return d.getFullYear() === n.getFullYear() && d.getMonth() === n.getMonth() && d.getDate() === n.getDate()
}
const isTodayQ = (q) => isTodayRow(q.created_at)

const belumBayarCount = computed(() => queue.value.filter((q) => isTodayQ(q) && !isLunas(q)).length)
const selesaiCount    = computed(() => queue.value.filter((q) => isTodayQ(q) &&  isLunas(q)).length)
const cActive         = computed(() => queue.value.filter((q) => !isTodayQ(q)).length)

const filtQ = computed(() => {
  let l = queue.value
  if (qPrimaryFilter.value === 'active')     l = l.filter((q) => !isTodayQ(q))
  else if (qPrimaryFilter.value === 'done')  l = l.filter((q) => isTodayQ(q) &&  isLunas(q))
  else                                       l = l.filter((q) => isTodayQ(q) && !isLunas(q))

  if (qSecondaryFilter.value === 'bpjs')      l = l.filter((q) => ptypeOf(q) === 'bpjs')
  else if (qSecondaryFilter.value === 'umum') l = l.filter((q) => ptypeOf(q) !== 'bpjs')

  if (qSearch.value) {
    const s = qSearch.value.toLowerCase()
    l = l.filter((q) =>
      (q.visit?.patient?.name ?? '').toLowerCase().includes(s) ||
      (q.queue_number ?? '').toLowerCase().includes(s) ||
      (q.visit?.patient?.no_rm ?? '').toLowerCase().includes(s),
    )
  }
  return l
})

async function fetchQueue() {
  queueLoading.value = true
  try {
    const { data } = await kasirApi.antrian()
    queue.value = data.data ?? []
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat antrean')
  } finally {
    queueLoading.value = false
  }
}

// ─── Detail invoice terpilih ────────────────────────────────────────────────
const pg          = ref('tagihan')

// ─── Panel antrean collapsible→rail (pola RefraksionisView/DokterView/BedahView) ──
const QUEUE_PREF_KEY = 'kasir.queueCollapsed'
const queueCollapsed = ref(localStorage.getItem(QUEUE_PREF_KEY) === '1')
// Default ciut di layar sedang bila user belum pernah set preferensi.
if (localStorage.getItem(QUEUE_PREF_KEY) === null && window.matchMedia('(max-width:1400px)').matches) {
  queueCollapsed.value = true
}
function toggleQueue() {
  queueCollapsed.value = !queueCollapsed.value
  localStorage.setItem(QUEUE_PREF_KEY, queueCollapsed.value ? '1' : '0')
}

const selQ        = ref(null)    // queue item dipilih
const selInv      = ref(null)    // full BillingInvoice + items
const selInvLoading = ref(false)
// Tagihan tertahan karena resep belum diverifikasi Farmasi (alur D→K→F).
const awaitingVerify = ref(false)
// Split COB (2 penjamin) — diisi dari /kasir/invoice/{id}/coverages bila visit COB.
const cobSplit    = ref(null)

// Warning verifikasi asuransi (Sprint 4 modul Asuransi/TPA)
const insuranceWarning = ref({ show: false })

// Estimasi copay pasien berdasarkan verifikasi + total tagihan saat ini.
// REFERENSI — sistem tidak auto-set nominal bayar. Aturan: max(% × tagihan, copay tetap).
const kasirCopayEstimate = computed(() => {
  const v = insuranceWarning.value?.verification
  const total = Number(selInv.value?.total) || 0
  if (!v || total === 0) return { label: '—', amount: 0 }

  const pct = Math.max(0, Math.min(100, Number(v.copayment_percent) || 0))
  const fix = Math.max(0, Number(v.copayment_amount) || 0)
  const fromPct = total * (pct / 100)
  const patientShare = Math.max(fromPct, fix)

  if (pct === 0 && fix === 0) {
    return { label: 'Rp 0 (full cover)', amount: 0 }
  }
  return {
    label: 'Rp ' + Math.round(patientShare).toLocaleString('id-ID'),
    amount: patientShare,
  }
})

// Warning kalau sisa plafon tidak cukup nutup klaim
const eligPlafonWarn = computed(() => {
  const v = insuranceWarning.value?.verification
  const total = Number(selInv.value?.total) || 0
  if (!v || !v.plafon_amount || total === 0) return null

  const plafon = Number(v.plafon_amount)
  const tpaShare = Math.max(0, total - kasirCopayEstimate.value.amount)
  if (plafon < tpaShare) {
    const diff = tpaShare - plafon
    return `Sisa plafon Rp ${plafon.toLocaleString('id-ID')} lebih kecil dari estimasi klaim Rp ${Math.round(tpaShare).toLocaleString('id-ID')}. Selisih Rp ${Math.round(diff).toLocaleString('id-ID')} jadi tanggungan pasien.`
  }
  return null
})

async function pickP(q) {
  const wasEmpty = !selQ.value
  // Mode fokus: ciutkan antrean saat PERTAMA pilih pasien (layar ≤1500px, pref belum diset).
  if (wasEmpty && localStorage.getItem(QUEUE_PREF_KEY) === null && window.matchMedia('(max-width:1500px)').matches) {
    queueCollapsed.value = true
  }
  selQ.value         = q
  selInv.value       = null
  selPM.value        = null
  uangDibayar.value  = 0
  showMixed.value    = false
  mixedAmounts.value = { 1: 0, 2: 0, 3: 0, 4: 0 }   // bersihkan agar nilai pasien lama tak bocor
  editTagihan.value  = false
  insuranceWarning.value = { show: false }
  awaitingVerify.value = false
  cobSplit.value     = null
  emailTujuan.value  = q.visit?.patient?.email ?? ''   // prefill email pasien
  if (!q.visit?.id) return

  // Fetch warning asuransi (non-blocking, error → silent)
  kasirApi.insuranceWarning(q.visit.id)
    .then(({ data }) => { insuranceWarning.value = data.data ?? { show: false } })
    .catch(() => {})

  selInvLoading.value = true
  try {
    let { data } = await kasirApi.showInvoice(q.visit.id)
    if (!data.data) {
      try {
        ({ data } = await kasirApi.generateInvoice(q.visit.id))
      } catch (err) {
        const msg = err.response?.data?.message ?? 'Gagal generate invoice'
        // Gate alur D→K→F: tagihan tertahan sampai Farmasi verifikasi & kunci resep.
        if (err.response?.status === 422 && msg.toLowerCase().includes('diverifikasi')) {
          awaitingVerify.value = true
        } else {
          toast('w', msg)
        }
        return
      }
    }
    selInv.value = data.data
    // Backend meng-override harga baris Rp 0 ke Buku Tarif terbaru saat tagihan dibuka.
    if (data.data?.prices_refreshed > 0) {
      toast('s', `${data.data.prices_refreshed} harga obat/BHP Rp 0 diperbarui dari Buku Tarif.`)
    }
    syncGlobalDiscountFields()
    // Split COB (non-blocking) — tampil bila visit dijamin 2 penjamin.
    if (selInv.value?.id) {
      kasirApi.invoiceCoverages(selInv.value.id)
        .then(({ data: cd }) => { const s = cd.data ?? cd; cobSplit.value = s?.is_cob ? s : null })
        .catch(() => { cobSplit.value = null })
    }
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat tagihan')
  } finally {
    selInvLoading.value = false
  }
}

async function callPt(q, e) {
  e.stopPropagation()
  if (pendingCallIds.value.includes(q.id)) return
  pendingCallIds.value.push(q.id)
  try {
    const { data } = await kasirApi.panggilAntrian(q.id)
    Object.assign(q, data.data)
    toast('i', `Memanggil ${q.visit?.patient?.name ?? ''} (${q.queue_number ?? ''})`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memanggil pasien')
  } finally {
    pendingCallIds.value = pendingCallIds.value.filter((id) => id !== q.id)
  }
}

async function skipPt(q, e) {
  e.stopPropagation()
  if (pendingSkipIds.value.includes(q.id)) return
  pendingSkipIds.value.push(q.id)
  try {
    await kasirApi.lewatiAntrian(q.id)
    toast('w', `${q.visit?.patient?.name ?? ''} (${q.queue_number ?? ''}) dipindah ke akhir antrean`)
    if (selQ.value?.id === q.id) { selQ.value = null; selInv.value = null }
    await fetchQueue()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal melewati pasien')
  } finally {
    pendingSkipIds.value = pendingSkipIds.value.filter((id) => id !== q.id)
  }
}

// ─── Hitungan tagihan ───────────────────────────────────────────────────────
const subtotal       = () => Number(selInv.value?.subtotal ?? 0)
const itemDiscount   = computed(() => (selInv.value?.items ?? []).reduce(
  (a, it) => a + Number(it.discount_amount ?? 0), 0,
))
const subtotalNet    = computed(() => Math.max(0, subtotal() - itemDiscount.value))
const discountAmount = computed(() => Number(selInv.value?.discount ?? 0))
const taxAmount      = computed(() => Number(selInv.value?.tax ?? 0))
const totalTagihan   = computed(() => Number(selInv.value?.total ?? 0))
const paidAmount     = computed(() => Number(selInv.value?.paid_amount ?? 0))
// Porsi ditanggung asuransi/TPA (diinput admin di menu Asuransi). 0 untuk pasien umum.
const coveredAmount  = computed(() => Number(selInv.value?.covered_amount ?? 0))
// Sisa yang harus DIBAYAR PASIEN = total − ditanggung asuransi − sudah dibayar.
const sisaTagihan    = computed(() => Math.max(0, totalTagihan.value - coveredAmount.value - paidAmount.value))
// Ditanggung penuh: cover menutup seluruh sisa (pasien tidak perlu bayar apa-apa).
const isFullCover    = computed(() =>
  coveredAmount.value > 0 && (coveredAmount.value + paidAmount.value) >= totalTagihan.value,
)
// Tagihan Rp 0 (diskon/penghapusan 100% RS/dokter) — pasien UMUM, bukan asuransi/BPJS.
// Tidak ada yang harus dibayar; processPayment menolak nominal 0 → jalur "settle-zero".
const isZeroDue      = computed(() =>
  !!selInv.value
  && !['PAID', 'CANCELLED'].includes(selInv.value.status)
  && !isBpjsSelected.value
  && !isFullCover.value
  && sisaTagihan.value === 0,
)

function bayar() { return sisaTagihan.value }

// ─── Metode pembayaran ──────────────────────────────────────────────────────
const payMethods = [
  { id: 1, code: 'CASH',        name: 'Tunai',        icon: '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 100 7h5a3.5 3.5 0 110 7H6"/>', bg: 'var(--gl)', color: 'var(--ga)' },
  { id: 2, code: 'CREDIT_CARD', name: 'Debit/Kredit', icon: '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>', bg: 'var(--ib)', color: 'var(--it)' },
  { id: 3, code: 'TRANSFER',    name: 'Transfer',     icon: '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/>', bg: 'var(--pb)', color: 'var(--pt)' },
  { id: 4, code: 'TRANSFER',    name: 'QRIS',         icon: '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>', bg: 'var(--wb)', color: 'var(--wt)' },
]
const selPM = ref(null)
const uangDibayar = ref(0)
const paying = ref(false)

const showMixed = ref(false)
const mixedAmounts = ref({ 1: 0, 2: 0, 3: 0, 4: 0 })
const mixedTotal = computed(() => Object.values(mixedAmounts.value).reduce((a, b) => a + (Number(b) || 0), 0))

// Re-sync nominal bayar saat sisa tagihan berubah selama edit (tambah/hapus item,
// diskon, atau cover asuransi di-update). Tanpa ini, nominal yang sudah terisi saat
// pilih metode jadi stale → bisa kurang/lebih dari tagihan baru. Hanya jika sudah
// pilih metode & invoice belum lunas.
watch(sisaTagihan, (sisa) => {
  if (!selInv.value || selInv.value.status === 'PAID' || selInv.value.status === 'CANCELLED') return
  if (selPM.value === 1) {
    uangDibayar.value = sisa
  } else if (selPM.value === 99) {
    // Bayar campuran: sinkronkan leg pertama (CASH) ke sisa, sisanya nol.
    mixedAmounts.value = { 1: sisa, 2: 0, 3: 0, 4: 0 }
  }
})

function toggleMixed() {
  showMixed.value = !showMixed.value
  if (showMixed.value) {
    selPM.value = 99
    mixedAmounts.value = { 1: 0, 2: 0, 3: 0, 4: 0 }
    if (selInv.value) mixedAmounts.value[1] = bayar()
  } else {
    selPM.value = null
  }
}

// ─── Edit tagihan ───────────────────────────────────────────────────────────
const editTagihan = ref(false)

// ─── Tarif tindakan per-penjamin (untuk Edit Tagihan) ────────────────────────
// Saat menambah TINDAKAN, kasir pilih dari master tarif yang harganya sudah
// di-resolve sesuai penjamin visit (bukan ketik manual). Harga ikut master.
// ── Search-driven picker buku tarif (semua kategori) ─────────────────────────
// Pencarian dilakukan SERVER-SIDE lintas kategori (tindakan/obat/BHP/IOL/alkes)
// supaya non-tindakan pun bisa ditambah dengan harga master per-penjamin —
// bukan ketik harga manual. Filter kategori opsional via chip.
const TARIF_TYPES = [
  { key: 'ALL',               label: 'Semua' },
  { key: 'TINDAKAN',          label: 'Tindakan' },
  { key: 'OBAT',              label: 'Obat' },
  { key: 'BHP',               label: 'BHP' },
  { key: 'IOL',               label: 'IOL' },
  { key: 'MEDICAL_EQUIPMENT', label: 'Alat Medis' },
]
const tindakanSearch      = ref('')
const tindakanSearchFocus = ref(false)
const tindakanSearchRef   = ref(null)
const tarifTypeFilter     = ref('ALL')
const tarifResults        = ref([])
const tarifLoading        = ref(false)
const addingTindakanIds   = ref([])   // guard double-add saat POST berjalan (key = source:id)
const tarifSearchDebounce = ref(null)
let   tarifSearchSeq      = 0          // anti race: abaikan respons usang

function fmtRp(v) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID') }

function rowKey(t) { return `${t.source ?? t.item_type}:${t.id}` }

// Debounced server-side search; re-jalan saat query / filter kategori berubah.
function runTarifSearch() {
  if (tarifSearchDebounce.value) clearTimeout(tarifSearchDebounce.value)
  const q = tindakanSearch.value.trim()
  if (!q || !selQ.value?.visit?.id) { tarifResults.value = []; tarifLoading.value = false; return }
  tarifLoading.value = true
  const seq = ++tarifSearchSeq
  tarifSearchDebounce.value = setTimeout(async () => {
    try {
      const { data } = await kasirApi.tarifBuku(selQ.value.visit.id, q, tarifTypeFilter.value)
      if (seq !== tarifSearchSeq) return            // respons usang → buang
      tarifResults.value = Array.isArray(data.data) ? data.data : []
    } catch (err) {
      if (seq !== tarifSearchSeq) return
      tarifResults.value = []
      toast('w', err.response?.data?.message ?? 'Gagal mencari buku tarif')
    } finally {
      if (seq === tarifSearchSeq) tarifLoading.value = false
    }
  }, 350)
}
watch([tindakanSearch, tarifTypeFilter], runTarifSearch)
// Keluar mode edit → bersihkan pencarian agar tak menyisakan hasil basi.
watch(editTagihan, (on) => { if (!on) { tindakanSearch.value = ''; tarifResults.value = [] } })

// Item buku tarif yang sudah ada di invoice (tanda ✓) — cocokkan via reference_id.
function tarifInInvoice(t) {
  return (selInv.value?.items ?? []).some(
    (it) => it.reference_id && it.reference_id === t.id,
  )
}

// Klik hasil → POST item ke invoice dengan item_type & harga master per-penjamin.
async function addTindakanFromTarif(t) {
  if (!selInv.value?.id) { toast('w', 'Tagihan belum siap'); return }
  const key = rowKey(t)
  if (addingTindakanIds.value.includes(key)) return
  addingTindakanIds.value.push(key)
  try {
    await kasirApi.storeItem(selInv.value.id, {
      item_type:    t.item_type || 'TINDAKAN',
      category:     t.category || 'Lainnya',
      description:  t.name,
      quantity:     1,
      unit_price:   Number(t.price) || 0,
      reference_id: t.id,
    })
    await refreshInvoice()
    toast('s', `${t.name} ditambahkan`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menambahkan item')
  } finally {
    addingTindakanIds.value = addingTindakanIds.value.filter((k) => k !== key)
  }
}

// Tutup dropdown saat klik di luar.
function onClickOutsideTindakan(e) {
  if (tindakanSearchFocus.value) {
    const el = tindakanSearchRef.value
    if (el && !el.contains(e.target)) tindakanSearchFocus.value = false
  }
  if (showPrintSettings.value) {
    const ps = printSetRef.value
    if (ps && !ps.contains(e.target)) showPrintSettings.value = false
  }
}

const itemMutating = ref(false)

async function removeItem(item) {
  if (!item?.id || itemMutating.value) return
  itemMutating.value = true
  try {
    await kasirApi.deleteItem(item.id)
    selInv.value.items = selInv.value.items.filter((i) => i.id !== item.id)
    await refreshInvoice()
    toast('s', 'Item dihapus')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menghapus item')
  } finally {
    itemMutating.value = false
  }
}

const itemDiscDebounce = ref({})
function onItemDiscChange(item, field) {
  if (itemDiscDebounce.value[item.id]) clearTimeout(itemDiscDebounce.value[item.id])
  itemDiscDebounce.value[item.id] = setTimeout(async () => {
    try {
      // Clamp: % di 0–100; Rp tak boleh > subtotal item (unit_price × qty) → cegah total negatif.
      const lineGross = (Number(item.unit_price) || 0) * (Number(item.quantity) || 0)
      const payload = field === 'discount_amount'
        ? { discount_amount:  Math.max(0, Math.min(Number(item.discount_amount) || 0, lineGross)) }
        : { discount_percent: Math.max(0, Math.min(100, Number(item.discount_percent) || 0)) }
      await kasirApi.updateItem(item.id, payload)
      // JANGAN Object.assign(item, fresh): menimpa field yang mungkin masih diketik kasir
      // (race ketik-vs-respons). refreshInvoice() menarik ulang seluruh invoice dari
      // server sebagai sumber kebenaran tunggal.
      await refreshInvoice()
    } catch (err) {
      toast('w', err.response?.data?.message ?? 'Gagal update diskon item')
    }
  }, 400)
}

async function refreshInvoice() {
  if (!selQ.value?.visit?.id) return
  try {
    const { data } = await kasirApi.showInvoice(selQ.value.visit.id)
    if (data.data) selInv.value = data.data
    syncGlobalDiscountFields()
  } catch { /* ignore */ }
}

// ─── Global diskon (Rp / %) ─────────────────────────────────────────────────
const globalDiscRp = ref(0)
const globalDiscPc = ref(0)
const globalDiscDebounce = ref(null)

function syncGlobalDiscountFields() {
  globalDiscRp.value = Number(selInv.value?.discount ?? 0)
  globalDiscPc.value = Number(selInv.value?.discount_percent ?? 0)
}

function onGlobalDiscChange(field) {
  if (globalDiscDebounce.value) clearTimeout(globalDiscDebounce.value)
  globalDiscDebounce.value = setTimeout(async () => {
    if (!selInv.value?.id) return
    try {
      const payload = field === 'rp'
        ? { discount: Math.max(0, Math.min(Number(globalDiscRp.value) || 0, subtotalNet.value)), discount_percent: 0 }
        : { discount_percent: Math.max(0, Math.min(100, Number(globalDiscPc.value) || 0)) }
      const { data } = await kasirApi.updateInvoice(selInv.value.id, payload)
      selInv.value = data.data
      await refreshInvoice()
    } catch (err) {
      toast('w', err.response?.data?.message ?? 'Gagal update diskon')
    }
  }, 400)
}

// ─── Proses pembayaran ──────────────────────────────────────────────────────
async function prosesBayar() {
  if (paying.value) return   // guard double-submit (backend mengakumulasi paid_amount)
  if (!selInv.value) { toast('w', 'Tagihan belum siap'); return }
  if (!selPM.value)  { toast('w', 'Pilih metode pembayaran terlebih dahulu'); return }
  const total = bayar()
  if (selPM.value === 1 && uangDibayar.value < total) { toast('w', 'Uang diterima kurang'); return }
  if (selPM.value === 99 && mixedTotal.value < total) { toast('w', 'Total pembayaran campuran masih kurang'); return }

  paying.value = true
  try {
    if (selInv.value.status === 'DRAFT') {
      const { data } = await kasirApi.finalizeInvoice(selInv.value.id)
      selInv.value = data.data
    }

    if (selPM.value === 99) {
      // Bayar campuran: kirim tiap metode, tapi CLAMP ke sisa tagihan agar backend
      // (yang mengakumulasi paid_amount tanpa clamp) tak pernah tercatat > total.
      // Jangan 'break' saat PAID — metode lain yang sudah diisi tetap berkontribusi
      // sampai sisa habis; sisa kelebihan input diabaikan (uang fisik = kembalian).
      let remaining = total
      for (const pm of payMethods) {
        if (remaining <= 0) break
        const input = Number(mixedAmounts.value[pm.id] || 0)
        if (input <= 0) continue
        const amount = Math.min(input, remaining)
        const body = { paid_amount: amount, payment_method: pm.code }
        // Leg CASH: kirim uang tunai fisik diterima agar kembalian benar tercetak
        // di kwitansi (service mengakumulasi cash_received hanya utk metode CASH).
        if (pm.code === 'CASH') body.cash_received = input
        const { data } = await kasirApi.bayarInvoice(selInv.value.id, body)
        selInv.value = data.data
        remaining -= amount
      }
    } else {
      const pm = payMethods.find((p) => p.id === selPM.value)
      const amount = selPM.value === 1 ? Math.min(uangDibayar.value, total) : total
      const payload = { paid_amount: amount, payment_method: pm.code }
      // CASH: kirim uang fisik diterima agar kembalian benar tercetak di kwitansi.
      if (selPM.value === 1) payload.cash_received = Number(uangDibayar.value) || 0
      const { data } = await kasirApi.bayarInvoice(selInv.value.id, payload)
      selInv.value = data.data
    }

    if (selInv.value.status === 'PAID') {
      const localQ = queue.value.find((q) => q.id === selQ.value?.id)
      if (localQ) localQ.status = 'COMPLETED'
      toast('s', `Pembayaran lunas — invoice ${selInv.value.invoice_number}`)
    } else {
      toast('i', `Pembayaran sebagian — tersisa Rp ${sisaTagihan.value.toLocaleString('id-ID')}`)
    }
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memproses pembayaran')
  } finally {
    paying.value = false
  }
}

// Konfirmasi tagihan ditanggung penuh asuransi — pasien tidak membayar.
async function prosesKonfirmasiCover() {
  if (paying.value) return
  if (!selInv.value?.id) { toast('w', 'Tagihan belum siap'); return }
  paying.value = true
  try {
    if (selInv.value.status === 'DRAFT') {
      const { data } = await kasirApi.finalizeInvoice(selInv.value.id)
      selInv.value = data.data
    }
    const { data } = await kasirApi.confirmCoverage(selInv.value.id, {})
    selInv.value = data.data
    const localQ = queue.value.find((q) => q.id === selQ.value?.id)
    if (localQ) localQ.status = 'COMPLETED'
    toast('s', `Ditanggung asuransi — invoice ${selInv.value.invoice_number} selesai`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal konfirmasi tanggungan asuransi')
  } finally {
    paying.value = false
  }
}

// Selesaikan tagihan Rp 0 (diskon/penghapusan 100% RS/dokter) — pasien tidak membayar.
async function prosesSettleNol() {
  if (paying.value) return
  if (!selInv.value?.id) { toast('w', 'Tagihan belum siap'); return }
  paying.value = true
  try {
    // Backend settle-zero meng-handle DRAFT→FINALIZED→PAID secara atomik.
    const { data } = await kasirApi.settleZero(selInv.value.id, {})
    selInv.value = data.data
    const localQ = queue.value.find((q) => q.id === selQ.value?.id)
    if (localQ) localQ.status = 'COMPLETED'
    toast('s', `Tagihan Rp 0 diselesaikan — invoice ${selInv.value.invoice_number}`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyelesaikan tagihan Rp 0')
  } finally {
    paying.value = false
  }
}

// Konfirmasi kunjungan BPJS — pasien tidak membayar (ditagih via klaim INA-CBG).
async function prosesKonfirmasiBpjs() {
  if (paying.value) return
  if (!selInv.value?.id) { toast('w', 'Tagihan belum siap'); return }
  paying.value = true
  try {
    const { data } = await kasirApi.confirmBpjs(selInv.value.id, {})
    selInv.value = data.data
    const localQ = queue.value.find((q) => q.id === selQ.value?.id)
    if (localQ) localQ.status = 'COMPLETED'
    toast('s', `Kunjungan BPJS dikonfirmasi — invoice ${selInv.value.invoice_number} selesai`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal konfirmasi kunjungan BPJS')
  } finally {
    paying.value = false
  }
}

// ─── Cetak Rincian Biaya (A4) ────────────────────────────────────────────────
const printData = ref(null)
const printing  = ref(false)

// ─── Kirim kwitansi ke email pasien (alternatif cetak fisik) ─────────────────
const emailTujuan = ref('')      // prefill dari patient.email saat pilih pasien
const emailing    = ref(false)

async function kirimEmail() {
  if (emailing.value) return
  if (!selInv.value?.id) { toast('w', 'Tagihan belum siap'); return }
  const email = (emailTujuan.value || '').trim()
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { toast('w', 'Format email tidak valid'); return }
  emailing.value = true
  try {
    const { data } = await kasirApi.emailInvoice(selInv.value.id, email)
    const res = data.data ?? {}
    // Catat status kirim di invoice lokal agar badge langsung tampil.
    if (selInv.value) {
      selInv.value.receipt_email        = res.email ?? email
      selInv.value.receipt_email_status = res.status ?? 'QUEUED'
      selInv.value.receipt_email_at     = res.at ?? null
    }
    // Sinkronkan email ke pasien lokal agar prefill konsisten kunjungan ini.
    if (selQ.value?.visit?.patient) selQ.value.visit.patient.email = email
    toast(res.status === 'SENT' ? 's' : 'i',
      res.status === 'SENT' ? `Kwitansi terkirim ke ${email}` : `Kwitansi diantrekan untuk dikirim ke ${email}`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal mengirim kwitansi ke email')
  } finally {
    emailing.value = false
  }
}

// ─── Setting cetak (toggle elemen kwitansi) ──────────────────────────────────
const showPrintSettings = ref(false)   // popover terbuka/tidak
const printSetRef = ref(null)          // utk deteksi klik di luar popover
const printSettings = ref({ show_logo: true, show_stamp: true, show_esign: true, show_footer: true, show_watermark: true })
const printSettingsSaving = ref(false)
const printSettingItems = [
  { key: 'show_logo',      label: 'Logo / Kop rumah sakit' },
  { key: 'show_stamp',     label: 'Stempel rumah sakit' },
  { key: 'show_esign',     label: 'Tanda tangan elektronik kasir' },
  { key: 'show_footer',    label: 'Footer penanggung jawab' },
  { key: 'show_watermark', label: 'Watermark' },
]

async function fetchPrintSettings() {
  try {
    const { data } = await kasirApi.getPrintSettings()
    if (data.data) printSettings.value = { ...printSettings.value, ...data.data }
  } catch { /* pakai default */ }
}

async function togglePrintSetting(key) {
  printSettings.value[key] = !printSettings.value[key]
  printSettingsSaving.value = true
  try {
    const { data } = await kasirApi.updatePrintSettings({ [key]: printSettings.value[key] })
    if (data.data) printSettings.value = { ...printSettings.value, ...data.data }
    toast('s', 'Setting cetak disimpan')
  } catch (err) {
    printSettings.value[key] = !printSettings.value[key]  // rollback
    toast('w', err.response?.data?.message ?? 'Gagal menyimpan setting cetak')
  } finally {
    printSettingsSaving.value = false
  }
}

// Pasien BPJS: kwitansi/rincian TIDAK dicetak untuk pasien (klaim ditagih ke BPJS).
const isBpjsSelected = computed(() =>
  (selQ.value?.visit?.guarantor_type ?? '').toUpperCase() === 'BPJS',
)

async function cetakRincian() {
  if (!selInv.value?.id) { toast('w', 'Tagihan belum siap'); return }
  printing.value = true
  try {
    const { data } = await kasirApi.cetakInvoice(selInv.value.id)
    printData.value = data.data
    await nextTick()
    setTimeout(() => window.print(), 80)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyiapkan dokumen cetak')
  } finally {
    printing.value = false
  }
}

function rupiah(v) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID') }
function penjaminLabel(g) {
  const t = (g ?? '').toUpperCase()
  if (t === 'BPJS') return 'BPJS Kesehatan'
  if (t === 'ASURANSI') return 'Asuransi'
  if (t === 'PERUSAHAAN') return 'Perusahaan'
  return 'Umum'
}
// Penjamin lengkap: tampilkan nama insurer HANYA bila menambah info (mis. "Asuransi —
// Admedika", "BPJS Kesehatan — COB Kereta Api"). Sembunyikan bila redundan dgn jenis
// penjamin (mis. Umum — UMUM, BPJS — BPJS).
function penjaminFull(p) {
  const base = penjaminLabel(p?.guarantor_type)
  const ins  = (p?.insurer ?? '').trim()
  if (!ins) return base
  const g = (p?.guarantor_type ?? '').toUpperCase()
  const insU = ins.toUpperCase()
  if (insU === g || insU === base.toUpperCase()) return base
  return `${base} — ${ins}`
}
// Jenis layanan kunjungan (judul kwitansi + label/badge).
function svcCode(t)  { return (t ?? 'RAJAL').toUpperCase() }
function svcTitle(t) { return ({ RANAP: 'KWITANSI RAWAT INAP', IGD: 'KWITANSI GAWAT DARURAT (IGD)', RAJAL: 'KWITANSI RAWAT JALAN' })[svcCode(t)] ?? 'RINCIAN BIAYA PELAYANAN' }
function svcLabel(t) { return ({ RANAP: 'Rawat Inap', IGD: 'Gawat Darurat (IGD)', RAJAL: 'Rawat Jalan' })[svcCode(t)] ?? 'Rawat Jalan' }
function svcShort(t) { return ({ RANAP: 'Rawat Inap', IGD: 'IGD', RAJAL: 'Rawat Jalan' })[svcCode(t)] ?? 'Rawat Jalan' }

// ─── History pembayaran ─────────────────────────────────────────────────────
// Tanggal default = hari ini (format yyyy-mm-dd WIB). Backend getInvoiceList
// memfilter `whereDate('created_at', tanggal)`, default today() bila kosong.
function todayStr() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const history       = ref([])
const historyLoading = ref(false)
const hDate         = ref(todayStr())
const hSearch       = ref('')
const hFilterPtype  = ref('')
const hFilterMetode = ref('')
const hCareType     = ref('')   // '' = semua | 'RAJAL' | 'RANAP' | 'IGD'

const CARE_TABS = [
  { key: '',      label: 'Semua' },
  { key: 'RAJAL', label: 'Rawat Jalan' },
  { key: 'RANAP', label: 'Rawat Inap' },
  { key: 'IGD',   label: 'IGD' },
]
function setHCareType(t) {
  if (hCareType.value === t) return
  hCareType.value = t
  fetchHistory()
}
// Label jenis layanan kunjungan (untuk pill di tabel history).
function careTypeOf(h)      { return (h.visit?.jenis_pelayanan ?? 'RAJAL').toUpperCase() }
function careTypeLabelOf(h) { return ({ RANAP: 'Rawat Inap', IGD: 'IGD', RAJAL: 'Rawat Jalan' })[careTypeOf(h)] ?? 'Rawat Jalan' }

async function fetchHistory() {
  historyLoading.value = true
  try {
    const { data } = await kasirApi.invoiceList({
      status: 'PAID',
      per_page: 50,
      tanggal: hDate.value || todayStr(),
      jenis_pelayanan: hCareType.value || undefined,
    })
    const payload  = data.data
    history.value  = Array.isArray(payload) ? payload : (payload?.data ?? [])
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat history')
  } finally {
    historyLoading.value = false
  }
}

// Cetak kwitansi/rincian dari baris history (reuse Teleport print template + printData).
// Setiap baris (termasuk BPJS) bisa dicetak: pasien BPJS menghasilkan RINCIAN biaya
// (bukan kwitansi tagih-ke-pasien), konsisten dgn tombol "Cetak Rincian" di panel.
async function cetakKwitansiHistory(h) {
  if (!h?.id) { toast('w', 'Invoice tidak valid'); return }
  printing.value = true
  try {
    const { data } = await kasirApi.cetakInvoice(h.id)
    printData.value = data.data
    await nextTick()
    setTimeout(() => window.print(), 80)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyiapkan dokumen cetak')
  } finally {
    printing.value = false
  }
}

function metodeLabel(code) {
  return ({ CASH: 'Tunai', CREDIT_CARD: 'Debit/Kredit', TRANSFER: 'Transfer', BPJS: 'BPJS', INSURANCE: 'Ditanggung Asuransi', WAIVED: 'Gratis / Diskon 100%' })[code] ?? (code ?? '—')
}
function ptypeOfHistory(h) {
  const g = (h.visit?.guarantor_type ?? '').toUpperCase()
  if (g === 'BPJS') return 'bpjs'
  if (g === 'ASURANSI' || g === 'PERUSAHAAN') return 'asn'
  return 'umum'
}

const histFiltered = computed(() =>
  history.value.filter((h) => {
    const name = h.visit?.patient?.name ?? ''
    const inv  = h.invoice_number ?? ''
    const metode = metodeLabel(h.payment_method)
    return (
      (!hSearch.value || name.toLowerCase().includes(hSearch.value.toLowerCase()) || inv.toLowerCase().includes(hSearch.value.toLowerCase())) &&
      (!hFilterPtype.value || ptypeOfHistory(h) === hFilterPtype.value) &&
      (!hFilterMetode.value || metode === hFilterMetode.value)
    )
  }),
)

// ─── Lifecycle / polling ────────────────────────────────────────────────────
let _poll = null
onMounted(() => {
  fetchQueue()
  fetchHistory()
  fetchBillingCategories()
  fetchPrintSettings()
  _poll = setInterval(fetchQueue, 8_000)
  document.addEventListener('mousedown', onClickOutsideTindakan)
})
onUnmounted(() => {
  if (_poll) clearInterval(_poll)
  document.removeEventListener('mousedown', onClickOutsideTindakan)
})

// ─── Toast ──────────────────────────────────────────────────────────────────
const toasts = ref([])
let tid = 0
function toast(type, msg) {
  const id = ++tid
  toasts.value.push({ id, type, msg })
  setTimeout(() => (toasts.value = toasts.value.filter((t) => t.id !== id)), 3000)
}

function catLabel(item) {
  return item.category || item.item_type || '—'
}
function catCls(item) {
  return `kat-${(item.item_type || 'lainnya').toLowerCase()}`
}

// Baris diskon paket (net negatif) — redaksi editable, tanpa input diskon item.
function isDiskonPaket(item) {
  return item.item_type === 'DISKON_PAKET'
}
const paketDescDebounce = ref({})
function onPaketDescChange(item) {
  if (paketDescDebounce.value[item.id]) clearTimeout(paketDescDebounce.value[item.id])
  paketDescDebounce.value[item.id] = setTimeout(async () => {
    try {
      await kasirApi.updateItem(item.id, { description: item.description || 'Diskon Paket' })
      await refreshInvoice()
    } catch { /* biarkan, refreshInvoice akan sinkron ulang */ }
  }, 500)
}

// ─── Grouping rincian tagihan per kategori ───────────────────────────────────
// Mengelompokkan selInv.items berdasarkan category, mengikuti sort_order dari
// master billingCategories. Item yang category-nya tidak terdaftar di master
// dilempar ke bucket "Lainnya" di akhir.
const FALLBACK_CATEGORY = 'Lainnya'

function groupItemsByCategory(items, categories) {
  if (!Array.isArray(items) || !items.length) return []

  // Map nama → sort_order; key lowercase supaya tidak case-sensitive.
  const orderMap = new Map()
  for (const cat of (categories ?? [])) {
    if (cat?.name) orderMap.set(String(cat.name).toLowerCase(), cat.sort_order ?? 100)
  }

  // Bucket per nama kategori (preserve original casing dari item).
  const buckets = new Map()
  for (const it of items) {
    const rawCat   = (it.category && String(it.category).trim()) || FALLBACK_CATEGORY
    const inMaster = orderMap.has(rawCat.toLowerCase())
    const bucketKey = inMaster ? rawCat : FALLBACK_CATEGORY
    if (!buckets.has(bucketKey)) buckets.set(bucketKey, [])
    buckets.get(bucketKey).push(it)
  }

  // Convert ke array + sort: known categories by sort_order asc; Lainnya selalu terakhir.
  const groups = Array.from(buckets.entries()).map(([name, rows]) => ({
    name,
    sort_order: orderMap.get(name.toLowerCase()) ?? 99999,
    items: rows,
    subtotal: rows.reduce((a, r) => a + Number(r.net_price ?? r.total_price ?? 0), 0),
  }))
  groups.sort((a, b) => {
    if (a.name === FALLBACK_CATEGORY) return 1
    if (b.name === FALLBACK_CATEGORY) return -1
    return a.sort_order - b.sort_order
  })
  return groups
}

const groupedItems = computed(() => groupItemsByCategory(selInv.value?.items ?? [], billingCategories.value))
const groupedPrintItems = computed(() =>
  groupItemsByCategory(printData.value?.items ?? [], printData.value?.categories ?? billingCategories.value),
)
</script>

<template>
  <div :class="['kasir', { 'q-collapsed': queueCollapsed }]">
    <div class="grid">
      <!-- ══════════════════ LEFT: QUEUE (collapsible→rail, pola RefraksionisView) ══════════════════ -->
      <aside class="col-queue">
        <!-- Rail vertikal saat panel diciutkan (CSS show-hide agar breakpoint bisa override) -->
        <button class="queue-rail" type="button" @click="toggleQueue" title="Buka panel antrean kasir" aria-label="Buka panel antrean kasir">
          <svg viewBox="0 0 24 24" class="qr-chevron" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
          <span class="qr-count">{{ queue.length }}</span>
          <span class="qr-label">Antrean Kasir</span>
        </button>

        <div class="card">
          <div class="card-head">
            <div>
              <div class="card-head-title">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
                Antrean Kasir
              </div>
              <div class="card-head-sub">{{ queue.length }} pasien hari ini</div>
            </div>
            <div class="head-actions">
              <span class="pill-live">LIVE</span>
              <button class="panel-collapse" type="button" @click="toggleQueue" title="Ciutkan panel antrean" aria-label="Ciutkan panel antrean">
                <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
              </button>
            </div>
          </div>

          <div class="card-body queue-scroll" role="region" aria-label="Daftar antrean kasir">
            <!-- Stats bar -->
            <div class="stats-bar">
              <div class="stat-item">
                <span class="stat-label">Belum Bayar</span>
                <b class="stat-num stat-waiting">{{ belumBayarCount }}</b>
              </div>
              <div class="stat-divider"></div>
              <div class="stat-item">
                <span class="stat-label">Lunas</span>
                <b class="stat-num stat-done">{{ selesaiCount }}</b>
              </div>
              <div class="stat-divider"></div>
              <div class="stat-item">
                <span class="stat-label">Total</span>
                <b class="stat-num">{{ queue.length }}</b>
              </div>
            </div>

            <!-- Primary filter -->
            <div class="primary-filter" role="group" aria-label="Filter utama antrean">
              <button :class="['pf-btn', qPrimaryFilter === 'waiting' ? 'a' : '']" @click="qPrimaryFilter = 'waiting'">
                Belum Bayar
                <span v-if="belumBayarCount" class="pf-ct">{{ belumBayarCount }}</span>
              </button>
              <button :class="['pf-btn', qPrimaryFilter === 'done' ? 'a' : '']" @click="qPrimaryFilter = 'done'">
                Lunas
                <span v-if="selesaiCount" class="pf-ct">{{ selesaiCount }}</span>
              </button>
              <button :class="['pf-btn', qPrimaryFilter === 'active' ? 'a' : '']" @click="qPrimaryFilter = 'active'" title="Tagihan belum selesai dari hari sebelumnya (lintas-hari)">
                Masih Aktif
                <span v-if="cActive" class="pf-ct">{{ cActive }}</span>
              </button>
            </div>

            <!-- Secondary filter -->
            <div class="ptype-tabs" role="group" aria-label="Filter jenis penjamin">
              <button :class="['ptype-tab', qSecondaryFilter === 'semua' ? 'a' : '']" @click="qSecondaryFilter = 'semua'">Semua</button>
              <button :class="['ptype-tab ptype-bpjs', qSecondaryFilter === 'bpjs' ? 'a' : '']" @click="qSecondaryFilter = 'bpjs'">BPJS</button>
              <button :class="['ptype-tab ptype-umum', qSecondaryFilter === 'umum' ? 'a' : '']" @click="qSecondaryFilter = 'umum'">Umum/Asuransi</button>
            </div>

            <!-- Search -->
            <div class="q-search-wrap">
              <input v-model="qSearch" class="q-search" placeholder="Cari nama / no. antrean / RM…" />
            </div>

            <!-- Loading -->
            <template v-if="queueLoading && !queue.length">
              <div v-for="n in 3" :key="n" class="q-skeleton"></div>
            </template>

            <!-- Empty -->
            <div v-else-if="!filtQ.length" class="empty-section">Tidak ada pasien dalam filter ini</div>

            <!-- Queue list -->
            <div v-else role="list" aria-label="Daftar antrean kasir">
              <div
                v-for="q in filtQ" :key="q.id"
                role="listitem"
                :class="['q-item', selQ?.id === q.id ? 'active' : '', isLunas(q) ? 'done' : '']"
                tabindex="0"
                @click="pickP(q)"
                @keydown.enter="pickP(q)"
              >
                <div class="qi-left">
                  <div class="q-num">{{ q.queue_number }}</div>
                  <span :class="['pill', isLunas(q) ? 'pill-completed' : `pill-${(q.status || 'waiting').toLowerCase()}`]">
                    <svg v-if="!isLunas(q) && q.status === 'WAITING'" viewBox="0 0 24 24" class="pill-icon"><path d="M5 2h14M5 22h14M6 2v5l4 5-4 5v5M18 2v5l-4 5 4 5v5"/></svg>
                    <svg v-else-if="!isLunas(q) && q.status === 'CALLED'" viewBox="0 0 24 24" class="pill-icon"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 014.69 12a19.79 19.79 0 01-3.07-8.67A2 2 0 013.6 1.27h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.91 8.91a16 16 0 006.18 6.18l.96-.96a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                    <svg v-else viewBox="0 0 24 24" class="pill-icon"><polyline points="20 6 9 17 4 12"/></svg>
                    {{ isLunas(q) ? 'Lunas' : (q.status === 'WAITING' ? 'Menunggu' : q.status === 'CALLED' ? 'Dipanggil' : 'Proses') }}
                  </span>
                </div>

                <div class="q-info">
                  <div class="q-name">{{ q.visit?.patient?.name ?? '—' }}</div>
                  <div class="q-meta">
                    RM: {{ q.visit?.patient?.no_rm ?? '—' }}
                  </div>
                  <div class="q-tags">
                    <span :class="['pill', ptypeOf(q) === 'bpjs' ? 'pill-bpjs' : ptypeOf(q) === 'asn' ? 'pill-asn' : 'pill-umum']">
                      {{ ptypeOf(q) === 'bpjs' ? 'BPJS' : ptypeOf(q) === 'asn' ? 'Asuransi' : 'Umum' }}
                    </span>
                    <span :class="['pill', `pill-care care-${svcCode(q.visit?.jenis_pelayanan).toLowerCase()}`]">{{ svcShort(q.visit?.jenis_pelayanan) }}</span>
                    <span v-if="isLunas(q)" class="pill pill-done">
                      <svg viewBox="0 0 24 24" class="pill-icon"><polyline points="20 6 9 17 4 12"/></svg>
                      Lunas
                    </span>
                    <span v-else class="pill pill-belum">Belum Bayar</span>
                  </div>
                  <div v-if="q.visit?.dpjp_name" class="q-dpjp" :title="`DPJP: ${q.visit.dpjp_name}`">
                    <svg viewBox="0 0 24 24" class="pill-icon"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    DPJP: {{ q.visit.dpjp_name }}
                  </div>
                  <div v-if="!isLunas(q)" class="q-actions" @click.stop>
                    <button
                      :class="['q-act-btn', 'call', q.status !== 'WAITING' ? 'recall' : '']"
                      :disabled="pendingCallIds.includes(q.id)"
                      @click="callPt(q, $event)"
                    >
                      <svg v-if="q.status === 'WAITING'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 014.69 12a19.79 19.79 0 01-3.07-8.67A2 2 0 013.6 1.27h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.91 8.91a16 16 0 006.18 6.18l.96-.96a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                      <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                      {{ q.status === 'WAITING' ? 'Panggil' : 'Panggil Ulang' }}
                    </button>
                    <button
                      class="q-act-btn skip"
                      :disabled="pendingSkipIds.includes(q.id)"
                      @click="skipPt(q, $event)"
                    >
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="7 13 12 18 17 13"/><polyline points="7 6 12 11 17 6"/></svg>
                      Lewati
                    </button>
                  </div>
                </div>

                <div class="qi-time">{{ formatTime(q.called_at ?? q.created_at) }}</div>
              </div>
            </div>
          </div>
        </div>
      </aside>

      <!-- ══════════════════ RIGHT ══════════════════ -->
      <section class="rp">
        <div class="nvt">
          <button :class="['nt', pg === 'tagihan' ? 'a' : '']" @click="pg = 'tagihan'">
            <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            Tagihan Aktif
          </button>
          <button :class="['nt', pg === 'history' ? 'a' : '']" @click="pg = 'history'">
            <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
            History
          </button>
        </div>

        <!-- TAGIHAN -->
        <div v-if="pg === 'tagihan'">
          <div v-if="!selQ" class="empty-state">
            <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            <p>Pilih pasien dari antrean kasir untuk memproses pembayaran</p>
          </div>
          <div v-else-if="selInvLoading" class="empty-state">
            <p>Memuat tagihan…</p>
          </div>
          <div v-else-if="awaitingVerify" class="empty-state">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
            <p><b>Menunggu verifikasi Farmasi.</b><br/>Resep pasien ini belum diverifikasi &amp; dikunci Farmasi, jadi tagihan belum bisa dibuat. Minta Farmasi memverifikasi resep, lalu klik Muat ulang.</p>
            <button class="btn btn-primary btn-sm" @click="pickP(selQ)">↻ Muat ulang</button>
          </div>
          <template v-else-if="selInv">
            <!-- Patient header -->
            <div class="pt-banner">
              <PatientAvatar :name="selQ.visit?.patient?.name" :src="selQ.visit?.patient?.photo_url" :size="46" radius="50%" />
              <div class="pt-info">
                <div class="pt-name">{{ selQ.visit?.patient?.name ?? '—' }}</div>
                <div class="pt-meta">{{ selQ.visit?.patient?.no_rm ?? '—' }} · {{ selInv.invoice_number ?? 'Invoice' }}</div>
                <div class="pt-contact">
                  <span v-if="formatDob(selQ.visit?.patient?.date_of_birth)" class="pt-contact-item">
                    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    {{ formatDob(selQ.visit.patient.date_of_birth) }}
                  </span>
                  <span v-if="selQ.visit?.patient?.phone" class="pt-contact-item">
                    <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 014.69 12a19.79 19.79 0 01-3.07-8.67A2 2 0 013.6 1.27h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.91 8.91a16 16 0 006.18 6.18l.96-.96a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                    {{ selQ.visit.patient.phone }}
                  </span>
                  <span v-if="selQ.visit?.patient?.address" class="pt-contact-item">
                    <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    {{ selQ.visit.patient.address }}<span v-if="selQ.visit.patient.province">, {{ selQ.visit.patient.province }}</span>
                  </span>
                  <span v-if="!selQ.visit?.patient?.address" class="pt-contact-item pt-contact-empty">
                    Alamat belum diisi
                  </span>
                </div>
                <div class="pt-tags">
                  <span :class="['ptg', ptypeOf(selQ) === 'bpjs' ? 'ptg-b' : ptypeOf(selQ) === 'asn' ? 'ptg-a' : 'ptg-u']">
                    {{ ptypeOf(selQ) === 'bpjs' ? 'BPJS' : ptypeOf(selQ) === 'asn' ? 'Asuransi' : 'Umum' }}
                  </span>
                  <span :class="['ptg', `ptg-care care-${svcCode(selQ.visit?.jenis_pelayanan).toLowerCase()}`]">{{ svcShort(selQ.visit?.jenis_pelayanan) }}</span>
                  <span v-if="selQ.visit?.dpjp_name" class="ptg ptg-dpjp" :title="`DPJP: ${selQ.visit.dpjp_name}`">DPJP: {{ selQ.visit.dpjp_name }}</span>
                  <span v-if="selInv.status === 'PAID'" class="ptg ptg-ok">LUNAS</span>
                  <span v-else-if="selInv.status === 'PARTIALLY_PAID'" class="ptg ptg-ok">Bayar Sebagian</span>
                </div>
              </div>
              <div class="pt-total">
                <div class="pt-total-v">Rp {{ sisaTagihan.toLocaleString('id-ID') }}</div>
                <div class="pt-total-l">Sisa Bayar</div>
              </div>
            </div>

            <div class="layout">
              <!-- LEFT: detail tagihan -->
              <div class="col-left">
                <!-- Warning verifikasi asuransi/TPA — non-blocker (Sprint 4 modul Asuransi) -->
                <div v-if="insuranceWarning.show" :class="['insurance-alert', insuranceWarning.status === 'ISSUE' ? 'ia-issue' : 'ia-pending']">
                  <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                  <div>
                    <strong>
                      {{ insuranceWarning.status === 'ISSUE' ? '⚠ Verifikasi Bermasalah' : '⏳ Verifikasi Pending' }}
                    </strong>
                    <div class="ia-msg">{{ insuranceWarning.message }}</div>
                  </div>
                </div>

                <!-- Panel info eligibility (readonly) — tampil untuk visit ASURANSI/PERUSAHAAN -->
                <div v-if="insuranceWarning.verification" class="elig-panel">
                  <div class="elig-head">
                    <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/></svg>
                    Info Eligibility Asuransi
                    <span :class="['elig-status', `es-${(insuranceWarning.status || 'NONE').toLowerCase()}`]">
                      {{ insuranceWarning.status }}
                    </span>
                  </div>
                  <div class="elig-grid">
                    <div class="elig-cell">
                      <div class="elig-lbl">Sisa Plafon</div>
                      <div class="elig-val" :class="eligPlafonWarn ? 'val-warn' : ''">
                        {{ insuranceWarning.verification.plafon_amount
                          ? 'Rp ' + Math.round(Number(insuranceWarning.verification.plafon_amount)).toLocaleString('id-ID')
                          : 'Unlimited / —' }}
                      </div>
                    </div>
                    <div class="elig-cell">
                      <div class="elig-lbl">Co-payment %</div>
                      <div class="elig-val">{{ Math.round(Number(insuranceWarning.verification.copayment_percent) || 0) }}%</div>
                    </div>
                    <div class="elig-cell">
                      <div class="elig-lbl">Co-payment Tetap</div>
                      <div class="elig-val">
                        {{ Number(insuranceWarning.verification.copayment_amount)
                          ? 'Rp ' + Math.round(Number(insuranceWarning.verification.copayment_amount)).toLocaleString('id-ID')
                          : '—' }}
                      </div>
                    </div>
                    <div class="elig-cell">
                      <div class="elig-lbl">Estimasi Pasien Bayar</div>
                      <div class="elig-val val-emphasis" :title="'Estimasi referensi — bukan keputusan final. Hitung manual sesuai polis.'">
                        {{ kasirCopayEstimate.label }}
                      </div>
                    </div>
                  </div>
                  <div v-if="insuranceWarning.verification.policy_number || insuranceWarning.verification.member_name" class="elig-meta">
                    <span v-if="insuranceWarning.verification.policy_number">
                      Polis: <strong>{{ insuranceWarning.verification.policy_number }}</strong>
                    </span>
                    <span v-if="insuranceWarning.verification.member_name">
                       · Peserta: <strong>{{ insuranceWarning.verification.member_name }}</strong>
                    </span>
                  </div>
                  <div v-if="insuranceWarning.verification.coverage_notes" class="elig-notes">
                    📝 {{ insuranceWarning.verification.coverage_notes }}
                  </div>
                  <div v-if="(insuranceWarning.verification.exclusion_flags || []).length" class="elig-excl">
                    🚫 Tidak cover: {{ (insuranceWarning.verification.exclusion_flags || []).join(', ') }}
                  </div>
                  <div v-if="eligPlafonWarn" class="elig-plafon-warn">
                    ⚠ {{ eligPlafonWarn }}
                  </div>
                  <div class="elig-hint">
                    💡 Estimasi di atas adalah referensi — sistem <strong>tidak otomatis</strong> potong tagihan. Kasir tetap hitung manual nominal yang ditagih ke pasien sesuai aturan polis.
                  </div>
                </div>

                <div v-if="selInv.notes" class="note-warning">
                  <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                  <span><b>Catatan:</b> {{ selInv.notes }}</span>
                </div>

                <div class="card">
                  <div class="card-head">
                    <div class="card-head-title">
                      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
                      Rincian Tagihan
                    </div>
                    <div style="display:flex;gap:.4rem">
                      <button :class="['btn btn-sm', editTagihan ? 'btn-primary' : 'btn-secondary']"
                        :disabled="['PAID','CANCELLED'].includes(selInv.status)"
                        @click="editTagihan = !editTagihan">
                        <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        {{ editTagihan ? 'Selesai Edit' : 'Edit Tagihan' }}
                      </button>
                      <button class="btn btn-sm btn-secondary" :disabled="printing" @click="cetakRincian">
                        <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        {{ printing ? 'Menyiapkan…' : 'Cetak Rincian' }}
                      </button>
                      <!-- 1 tombol: Setting Print (popover toggle elemen kwitansi) -->
                      <div class="print-set-wrap" ref="printSetRef">
                        <button class="btn btn-sm btn-secondary btn-icon" title="Setting cetak kwitansi" @click="showPrintSettings = !showPrintSettings">
                          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                          Setting Print
                        </button>
                        <div v-if="showPrintSettings" class="print-set-pop">
                          <div class="print-set-head">
                            Tampil di Cetak Kwitansi
                            <span v-if="printSettingsSaving" class="print-set-saving">menyimpan…</span>
                          </div>
                          <label v-for="opt in printSettingItems" :key="opt.key" class="print-set-row">
                            <input type="checkbox" :checked="printSettings[opt.key]" @change="togglePrintSetting(opt.key)" />
                            <span>{{ opt.label }}</span>
                          </label>
                          <div class="print-set-foot">Berlaku untuk semua cetak kwitansi/rincian kasir.</div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Tambah tindakan (konsep sama Tab Tindakan DokterView): search → dropdown → klik tambah.
                       Harga ikut tarif master per-penjamin visit. -->
                  <div v-if="editTagihan" class="add-tindakan-bar">
                    <div class="tindakan-search-wrap" ref="tindakanSearchRef">
                      <div class="tindakan-search-field">
                        <svg class="tindakan-search-icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input
                          v-model="tindakanSearch"
                          class="tindakan-search-input"
                          placeholder="Cari & tambah dari Buku Tarif: tindakan / obat / BHP / IOL / alat medis (nama / kode)…"
                          @focus="tindakanSearchFocus = true"
                        />
                        <button v-if="tindakanSearch" class="tindakan-search-clear" @click="tindakanSearch = ''" title="Hapus pencarian">
                          <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                      </div>
                      <!-- Filter kategori buku tarif -->
                      <div class="tarif-type-chips">
                        <button
                          v-for="tt in TARIF_TYPES" :key="tt.key"
                          :class="['tarif-type-chip', tarifTypeFilter === tt.key ? 'a' : '']"
                          @click="tarifTypeFilter = tt.key"
                        >{{ tt.label }}</button>
                      </div>
                      <div v-if="tindakanSearchFocus && tindakanSearch.trim()" class="tindakan-search-drop">
                        <div
                          v-for="t in tarifResults" :key="rowKey(t)"
                          :class="['tarif-list-item', tarifInInvoice(t) ? 'in-list' : '', addingTindakanIds.includes(rowKey(t)) ? 'is-adding' : '']"
                          @mousedown.prevent="addTindakanFromTarif(t)"
                        >
                          <span :class="['tarif-type-badge', `tt-${(t.item_type || 'lainnya').toLowerCase()}`]">{{ t.item_type }}</span>
                          <span v-if="t.code" class="tarif-kode">{{ t.code }}</span>
                          <span v-if="t.category" class="tarif-kat td">{{ t.category }}</span>
                          <span class="tarif-list-name">{{ t.name }}</span>
                          <span class="tarif-list-price">{{ fmtRp(t.price) }}</span>
                          <svg v-if="tarifInInvoice(t)" class="tarif-list-icon check" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                          <svg v-else class="tarif-list-icon add" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </div>
                        <div v-if="tarifLoading" class="tarif-empty">Mencari…</div>
                        <div v-else-if="!tarifResults.length" class="tarif-empty">Tidak ditemukan di buku tarif</div>
                        <div v-else-if="tarifResults.length >= 40" class="tindakan-search-hint">
                          Menampilkan 40 teratas — sempitkan pencarian / pilih kategori
                        </div>
                      </div>
                    </div>
                  </div>

                  <table class="tbl">
                    <thead>
                      <tr>
                        <th>Keterangan</th>
                        <th>Kategori</th>
                        <th class="num">Qty</th>
                        <th class="num">Harga</th>
                        <th v-if="editTagihan" class="num">Disc %</th>
                        <th v-if="editTagihan" class="num">Disc Rp</th>
                        <th class="num">Net</th>
                        <th v-if="editTagihan"></th>
                      </tr>
                    </thead>
                    <!-- Grouped tbody per kategori — urutan section ikut master billingCategories.
                         Item dengan kategori tidak terdaftar di master → otomatis masuk grup "Lainnya". -->
                    <tbody v-for="grp in groupedItems" :key="grp.name" class="kat-group">
                      <tr class="kat-group-head">
                        <td :colspan="editTagihan ? 7 : 4">
                          <span class="kat-group-name">{{ grp.name }}</span>
                          <span class="kat-group-count">{{ grp.items.length }} item</span>
                        </td>
                        <td class="num strong">Rp {{ grp.subtotal.toLocaleString('id-ID') }}</td>
                        <td v-if="editTagihan"></td>
                      </tr>
                      <tr v-for="item in grp.items" :key="item.id" :class="{ 'is-diskon': isDiskonPaket(item) }">
                        <td class="strong">
                          <input
                            v-if="editTagihan && isDiskonPaket(item)"
                            v-model="item.description"
                            class="fi tbl-fi"
                            placeholder="Redaksi diskon (mis. Paket Bedah)"
                            @input="onPaketDescChange(item)"
                          />
                          <template v-else>{{ item.description }}</template>
                        </td>
                        <td><span :class="['kat-pill', catCls(item)]">{{ catLabel(item) }}</span></td>
                        <td class="num">{{ item.quantity }}</td>
                        <td class="num">Rp {{ Number(item.unit_price).toLocaleString('id-ID') }}</td>
                        <td v-if="editTagihan" class="num">
                          <input
                            v-if="!isDiskonPaket(item)"
                            v-model.number="item.discount_percent"
                            type="number" min="0" max="100" step="0.01"
                            class="fi tbl-fi tbl-num"
                            @input="onItemDiscChange(item, 'discount_percent')"
                          />
                        </td>
                        <td v-if="editTagihan" class="num">
                          <input
                            v-if="!isDiskonPaket(item)"
                            v-model.number="item.discount_amount"
                            type="number" min="0" :max="(Number(item.unit_price)||0)*(Number(item.quantity)||0)"
                            class="fi tbl-fi tbl-num"
                            @input="onItemDiscChange(item, 'discount_amount')"
                          />
                        </td>
                        <td class="num strong">
                          <span v-if="Number(item.discount_amount) > 0" class="muted-strike">
                            Rp {{ Number(item.total_price).toLocaleString('id-ID') }}
                          </span>
                          Rp {{ Number(item.net_price ?? item.total_price).toLocaleString('id-ID') }}
                        </td>
                        <td v-if="editTagihan">
                          <button class="del-btn" @click="removeItem(item)" :disabled="itemMutating || (selInv.items?.length ?? 0) <= 1">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                          </button>
                        </td>
                      </tr>
                    </tbody>
                    <tbody>
                      <tr v-if="!(selInv.items ?? []).length"><td :colspan="editTagihan ? 8 : 5" class="empty-row">Belum ada item</td></tr>
                    </tbody>
                  </table>
                  <div class="tbl-foot">
                    <div class="totals">
                      <div class="row"><span>Subtotal</span><span class="num">Rp {{ subtotal().toLocaleString('id-ID') }}</span></div>
                      <div v-if="itemDiscount" class="row red"><span>Diskon Item</span><span class="num">−Rp {{ itemDiscount.toLocaleString('id-ID') }}</span></div>
                      <div v-if="itemDiscount" class="row"><span>Subtotal setelah diskon item</span><span class="num">Rp {{ subtotalNet.toLocaleString('id-ID') }}</span></div>

                      <!-- Diskon global Rp + % -->
                      <div class="row global-disc" v-if="editTagihan && !['PAID','CANCELLED'].includes(selInv.status)">
                        <span>Diskon Global</span>
                        <span class="disc-inputs">
                          <input v-model.number="globalDiscPc" type="number" min="0" max="100" step="0.01" class="fi disc-pc" @input="onGlobalDiscChange('pc')" /><span class="disc-suffix">%</span>
                          <span class="disc-sep">atau</span>
                          <span class="disc-rp-wrap">Rp <input v-model.number="globalDiscRp" type="number" min="0" :max="subtotalNet" class="fi disc-rp" @input="onGlobalDiscChange('rp')" /></span>
                        </span>
                      </div>
                      <div v-else-if="discountAmount" class="row red"><span>Diskon Global<span v-if="Number(selInv.discount_percent) > 0"> ({{ Number(selInv.discount_percent) }}%)</span></span><span class="num">−Rp {{ discountAmount.toLocaleString('id-ID') }}</span></div>

                      <div v-if="taxAmount" class="row"><span>Pajak</span><span class="num">Rp {{ taxAmount.toLocaleString('id-ID') }}</span></div>
                      <div class="row"><span>Total Tagihan</span><span class="num">Rp {{ totalTagihan.toLocaleString('id-ID') }}</span></div>
                      <!-- COB: rincian tanggungan per penjamin (BPJS INA-CBG + selisih penjamin-2) -->
                      <template v-if="cobSplit && cobSplit.is_cob">
                        <div v-for="p in cobSplit.penjamin" :key="p.sequence" class="row green">
                          <span>Ditanggung Penjamin {{ p.sequence }} ({{ p.guarantor_type }})</span>
                          <span class="num">−Rp {{ Number(p.covered_amount).toLocaleString('id-ID') }}</span>
                        </div>
                      </template>
                      <div v-else-if="coveredAmount" class="row green"><span>Ditanggung Asuransi</span><span class="num">−Rp {{ coveredAmount.toLocaleString('id-ID') }}</span></div>
                      <div v-if="paidAmount" class="row blue"><span>Sudah Dibayar</span><span class="num">−Rp {{ paidAmount.toLocaleString('id-ID') }}</span></div>
                      <div class="row grand"><span>{{ coveredAmount ? 'Sisa Bayar Pasien' : 'Sisa Bayar' }}</span><span class="num">Rp {{ sisaTagihan.toLocaleString('id-ID') }}</span></div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- RIGHT: aksi -->
              <div class="col-right">
                <div class="card">
                  <div class="card-head">
                    <div class="card-head-title">
                      <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
                      {{ (isBpjsSelected && selInv.status !== 'PAID') ? 'Tanggungan BPJS'
                         : (isFullCover && selInv.status !== 'PAID') ? 'Tanggungan Asuransi'
                         : (isZeroDue) ? 'Tagihan Rp 0'
                         : 'Metode Pembayaran' }}
                    </div>
                    <button v-if="!(isBpjsSelected && selInv.status !== 'PAID') && !(isFullCover && selInv.status !== 'PAID') && !isZeroDue" :class="['btn btn-sm', showMixed ? 'btn-primary' : 'btn-secondary']" @click="toggleMixed">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 100 7h5a3.5 3.5 0 110 7H6"/></svg>
                      Campuran
                    </button>
                  </div>
                  <div class="card-body">
                    <!-- BPJS: pasien tidak membayar di kasir (ditagih via klaim INA-CBG) — kasir cukup konfirmasi -->
                    <template v-if="isBpjsSelected && selInv.status !== 'PAID'">
                      <div class="cover-confirm-box cover-bpjs">
                        <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/><polyline points="9 12 11 14 15 10"/></svg>
                        <div class="cover-confirm-title">Ditanggung BPJS Kesehatan</div>
                        <div class="cover-confirm-amount">Rp {{ totalTagihan.toLocaleString('id-ID') }}</div>
                        <div class="cover-confirm-sub">Pasien BPJS tidak membayar di kasir. Tagihan diklaim via INA-CBG. Klik konfirmasi untuk menyelesaikan kunjungan.</div>
                      </div>
                      <button class="btn btn-success btn-full btn-lg"
                        :disabled="paying"
                        @click="prosesKonfirmasiBpjs">
                        <div v-if="paying" class="sp"></div>
                        <svg v-else viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                        {{ paying ? 'Memproses...' : 'Konfirmasi (Ditanggung BPJS)' }}
                      </button>
                    </template>

                    <!-- FULL COVER asuransi/TPA: pasien tidak membayar, kasir cukup konfirmasi -->
                    <template v-else-if="isFullCover && selInv.status !== 'PAID'">
                      <div class="cover-confirm-box">
                        <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/><polyline points="9 12 11 14 15 10"/></svg>
                        <div class="cover-confirm-title">Ditanggung Penuh Asuransi</div>
                        <div class="cover-confirm-amount">Rp {{ coveredAmount.toLocaleString('id-ID') }}</div>
                        <div class="cover-confirm-sub">Pasien tidak membayar. Klik konfirmasi untuk menyelesaikan kunjungan.</div>
                      </div>
                      <button class="btn btn-success btn-full btn-lg"
                        :disabled="paying"
                        @click="prosesKonfirmasiCover">
                        <div v-if="paying" class="sp"></div>
                        <svg v-else viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                        {{ paying ? 'Memproses...' : 'Konfirmasi Lunas (Ditanggung Asuransi)' }}
                      </button>
                    </template>

                    <!-- TAGIHAN Rp 0: diskon/penghapusan 100% RS/dokter — pasien tidak membayar -->
                    <template v-else-if="isZeroDue">
                      <div class="cover-confirm-box">
                        <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/><polyline points="9 12 11 14 15 10"/></svg>
                        <div class="cover-confirm-title">Tagihan Rp 0 — Gratis / Diskon 100%</div>
                        <div class="cover-confirm-amount">Rp 0</div>
                        <div class="cover-confirm-sub">Seluruh tagihan didiskon/dihapus RS atau dokter. Pasien tidak membayar. Klik konfirmasi untuk menyelesaikan kunjungan.</div>
                      </div>
                      <button class="btn btn-success btn-full btn-lg"
                        :disabled="paying"
                        @click="prosesSettleNol">
                        <div v-if="paying" class="sp"></div>
                        <svg v-else viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                        {{ paying ? 'Memproses...' : 'Konfirmasi Lunas (Rp 0)' }}
                      </button>
                    </template>

                    <template v-else-if="!showMixed">
                      <div class="pm-grid">
                        <div v-for="pm in payMethods" :key="pm.id" :class="['pm', selPM === pm.id ? 'sel' : '']"
                          @click="selPM = pm.id; uangDibayar = bayar(); showMixed = false">
                          <div class="pm-icon" :style="{ background: pm.bg }">
                            <svg viewBox="0 0 24 24" :stroke="pm.color" v-html="pm.icon"></svg>
                          </div>
                          <span>{{ pm.name }}</span>
                        </div>
                      </div>
                      <div v-if="selPM === 1" class="fg cash-row">
                        <label class="fl">Uang Diterima</label>
                        <input v-model.number="uangDibayar" type="number" class="fi" />
                        <div v-if="uangDibayar >= bayar()" class="kembalian">
                          Kembalian: Rp {{ (uangDibayar - bayar()).toLocaleString('id-ID') }}
                        </div>
                      </div>
                    </template>

                    <template v-else>
                      <div class="mixed-header">
                        <span class="mixed-lbl">Total Tagihan</span>
                        <span class="mixed-total">Rp {{ bayar().toLocaleString('id-ID') }}</span>
                      </div>
                      <div class="mixed-list">
                        <div v-for="pm in payMethods" :key="pm.id" class="mixed-row">
                          <div class="pm-icon sm" :style="{ background: pm.bg }">
                            <svg viewBox="0 0 24 24" :stroke="pm.color" v-html="pm.icon"></svg>
                          </div>
                          <span class="mixed-name">{{ pm.name }}</span>
                          <input v-model.number="mixedAmounts[pm.id]" type="number" min="0" class="fi mixed-input" placeholder="0" />
                        </div>
                      </div>
                      <div :class="['mixed-status', mixedTotal >= bayar() ? 'ok' : 'warn']">
                        <span>Terbayar: Rp {{ mixedTotal.toLocaleString('id-ID') }}</span>
                        <span v-if="mixedTotal < bayar()">Kurang: Rp {{ (bayar() - mixedTotal).toLocaleString('id-ID') }}</span>
                        <span v-else>Kembalian: Rp {{ (mixedTotal - bayar()).toLocaleString('id-ID') }}</span>
                      </div>
                    </template>

                    <button v-if="!(isFullCover && selInv.status !== 'PAID') && !(isBpjsSelected && selInv.status !== 'PAID') && !isZeroDue" class="btn btn-success btn-full btn-lg"
                      :disabled="paying || !selPM || (selPM === 1 && uangDibayar < bayar()) || (selPM === 99 && mixedTotal < bayar()) || selInv.status === 'PAID' || selInv.status === 'CANCELLED'"
                      @click="prosesBayar">
                      <div v-if="paying" class="sp"></div>
                      <svg v-else viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                      {{ selInv.status === 'PAID' ? 'Sudah Lunas' : paying ? 'Memproses...' : 'Proses Pembayaran' }}
                    </button>
                    <button v-if="selInv.status === 'PAID'" class="btn btn-secondary btn-full btn-sm" style="margin-top:.35rem" :disabled="printing" @click="cetakRincian">
                      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
                      {{ isBpjsSelected ? 'Cetak Rincian' : 'Cetak Kwitansi' }} {{ selInv.invoice_number }}
                    </button>

                    <!-- Kirim kwitansi ke email (alternatif cetak fisik). Disembunyikan
                         untuk BPJS — ditagih via klaim, bukan ke pasien. -->
                    <div v-if="!isBpjsSelected" class="email-send">
                      <div class="email-send-lbl">
                        <svg viewBox="0 0 24 24"><path d="M4 4h16a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V6a2 2 0 012-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Kirim kwitansi ke email
                      </div>
                      <div class="email-send-row">
                        <input v-model="emailTujuan" type="email" class="fi email-send-input" placeholder="nama@email.com" @keydown.enter="kirimEmail" />
                        <button class="btn btn-primary btn-sm email-send-btn" :disabled="emailing || !emailTujuan.trim()" @click="kirimEmail">
                          <div v-if="emailing" class="sp"></div>
                          <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                          {{ emailing ? 'Mengirim…' : (selInv.receipt_email_status === 'FAILED' ? 'Kirim Ulang' : 'Kirim') }}
                        </button>
                      </div>

                      <!-- Status pengiriman per-invoice (jujur: ANTRE / TERKIRIM / GAGAL) -->
                      <div v-if="selInv.receipt_email_status" :class="['email-status', `es-${selInv.receipt_email_status.toLowerCase()}`]">
                        <template v-if="selInv.receipt_email_status === 'SENT'">
                          <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                          Terkirim ke {{ selInv.receipt_email }}<span v-if="selInv.receipt_email_at"> · {{ formatTime(selInv.receipt_email_at) }}</span>
                        </template>
                        <template v-else-if="selInv.receipt_email_status === 'QUEUED'">
                          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                          Antre dikirim ke {{ selInv.receipt_email }} — menunggu proses pengiriman
                        </template>
                        <template v-else>
                          <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                          Gagal kirim ke {{ selInv.receipt_email }}<span v-if="selInv.receipt_email_error"> — {{ selInv.receipt_email_error }}</span>. Klik “Kirim Ulang”.
                        </template>
                      </div>

                      <div class="email-send-hint">PDF kwitansi dikirim ke email pasien. Email tersimpan otomatis ke data pasien.</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </template>
          <div v-else class="empty-state">
            <p>Tagihan tidak tersedia untuk pasien ini.</p>
          </div>
        </div>

        <!-- HISTORY -->
        <div v-if="pg === 'history'">
          <div class="stat-row">
            <div class="stat-card">
              <div class="stat-icon" style="background: var(--sb)"><svg viewBox="0 0 24 24" stroke="var(--st)"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg></div>
              <div><div class="stat-val">{{ history.length }}</div><div class="stat-lbl">Transaksi Hari Ini</div></div>
            </div>
            <div class="stat-card">
              <div class="stat-icon" style="background: var(--gl)"><svg viewBox="0 0 24 24" stroke="var(--ga)"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 100 7h5a3.5 3.5 0 110 7H6"/></svg></div>
              <div><div class="stat-val">Rp {{ (history.reduce((a, b) => a + Number(b.paid_amount ?? 0), 0) / 1000000).toFixed(2) }}jt</div><div class="stat-lbl">Kas Diterima</div></div>
            </div>
            <div class="stat-card">
              <div class="stat-icon" style="background: #dbeafe"><svg viewBox="0 0 24 24" stroke="#1e40af"><rect x="3" y="4" width="18" height="18" rx="2"/></svg></div>
              <div><div class="stat-val">{{ history.filter((h) => ptypeOfHistory(h) === 'bpjs').length }}</div><div class="stat-lbl">Pasien BPJS</div></div>
            </div>
            <div class="stat-card">
              <div class="stat-icon" style="background: var(--gl)"><svg viewBox="0 0 24 24" stroke="var(--ga)"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
              <div><div class="stat-val">{{ history.filter((h) => ptypeOfHistory(h) !== 'bpjs').length }}</div><div class="stat-lbl">Umum / Asuransi</div></div>
            </div>
          </div>

          <div class="card">
            <div class="card-head">
              <div class="card-head-title">
                <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                Riwayat Pembayaran
              </div>
              <div class="care-tabs">
                <button
                  v-for="t in CARE_TABS"
                  :key="t.key"
                  :class="['care-tab', hCareType === t.key ? 'a' : '']"
                  @click="setHCareType(t.key)"
                >{{ t.label }}</button>
              </div>
              <div class="filter-row">
                <input v-model="hDate" type="date" class="fi" title="Tanggal transaksi" @change="fetchHistory" />
                <input v-model="hSearch" class="fi" placeholder="Cari pasien/no invoice..." />
                <select v-model="hFilterPtype" class="fi">
                  <option value="">Semua jenis</option>
                  <option value="bpjs">BPJS</option>
                  <option value="umum">Umum</option>
                  <option value="asn">Asuransi</option>
                </select>
                <select v-model="hFilterMetode" class="fi">
                  <option value="">Semua metode</option>
                  <option>Tunai</option><option>Debit/Kredit</option><option>Transfer</option><option>BPJS</option>
                </select>
              </div>
            </div>
            <table class="tbl">
              <thead>
                <tr>
                  <th class="num" style="width:44px">No</th>
                  <th>No. Invoice</th>
                  <th>Pasien</th>
                  <th>Jenis</th>
                  <th>Metode</th>
                  <th class="num">Total</th>
                  <th>Jam</th>
                  <th style="width:104px">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="historyLoading && !history.length"><td colspan="8" class="empty-row">Memuat history…</td></tr>
                <tr v-for="(h, i) in histFiltered" :key="h.id">
                  <td class="num muted">{{ i + 1 }}</td>
                  <td class="strong">{{ h.invoice_number }}</td>
                  <td>{{ h.visit?.patient?.name ?? '—' }}<div class="muted">{{ h.visit?.patient?.no_rm ?? '—' }}</div></td>
                  <td>
                    <span :class="['kat-pill', `kat-${ptypeOfHistory(h)}`]">{{ ptypeOfHistory(h).toUpperCase() }}</span>
                    <span :class="['care-pill', `care-${careTypeOf(h).toLowerCase()}`]">{{ careTypeLabelOf(h) }}</span>
                  </td>
                  <td>{{ metodeLabel(h.payment_method) }}</td>
                  <td class="num strong">Rp {{ Number(h.paid_amount ?? h.total).toLocaleString('id-ID') }}</td>
                  <td class="muted">{{ formatTime(h.paid_at ?? h.updated_at) }}</td>
                  <td>
                    <button
                      class="hist-print-btn"
                      :disabled="printing"
                      :title="ptypeOfHistory(h) === 'bpjs' ? 'Cetak rincian biaya' : 'Cetak kwitansi'"
                      @click="cetakKwitansiHistory(h)"
                    >
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                      {{ ptypeOfHistory(h) === 'bpjs' ? 'Rincian' : 'Cetak' }}
                    </button>
                  </td>
                </tr>
                <tr v-if="!historyLoading && !histFiltered.length"><td colspan="8" class="empty-row">Tidak ada transaksi yang cocok</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>

    <div class="toast-wrap">
      <div v-for="t in toasts" :key="t.id" :class="['toast', `toast-${t.type}`]">{{ t.msg }}</div>
    </div>

    <!-- ═══ DOKUMEN CETAK A4 — RINCIAN BIAYA (Teleport ke body supaya @media print global bekerja) ═══ -->
    <Teleport to="body">
      <div v-if="printData" class="rincian-print">
        <div v-if="printData.clinic?.watermark_type" class="rp-watermark">{{ printData.clinic.watermark_type }}</div>

        <!-- Kop kanonik (sumber tunggal) — identik dgn pratinjau Profil Institusi -->
        <div v-if="printData.clinic?.letterhead_html" class="rp-kop-canon" v-html="printData.clinic.letterhead_html"></div>
        <header v-else class="rp-kop">
          <img v-if="printData.clinic?.logo_url" :src="printData.clinic.logo_url" alt="Logo" class="rp-logo" />
          <div class="rp-kop-text">
            <div class="rp-clinic">{{ printData.clinic?.name ?? 'Rumah Sakit' }}</div>
            <div v-if="printData.clinic?.address" class="rp-line">{{ printData.clinic.address }}</div>
            <div class="rp-line">
              <span v-if="printData.clinic?.phone">Telp: {{ printData.clinic.phone }}</span>
              <span v-if="printData.clinic?.email"> · Email: {{ printData.clinic.email }}</span>
            </div>
          </div>
        </header>

        <h1 :class="['rp-title', `rp-svc-${svcCode(printData.service_type).toLowerCase()}`]">{{ svcTitle(printData.service_type) }}</h1>
        <div class="rp-subtitle">No. {{ printData.invoice?.number ?? '—' }}</div>

        <table class="rp-meta">
          <tbody>
            <tr>
              <td class="k">No. Rekam Medis</td><td class="s">:</td><td class="v">{{ printData.patient?.no_rm ?? '—' }}</td>
              <td class="k">Tanggal</td><td class="s">:</td><td class="v">{{ printData.invoice?.date ?? '—' }}</td>
            </tr>
            <tr>
              <td class="k">Nama Pasien</td><td class="s">:</td><td class="v">{{ printData.patient?.name ?? '—' }}</td>
              <td class="k">Metode Bayar</td><td class="s">:</td><td class="v">{{ printData.invoice?.payment_method ? metodeLabel(printData.invoice.payment_method) : '—' }}</td>
            </tr>
            <tr>
              <td class="k">NIK</td><td class="s">:</td><td class="v">{{ printData.patient?.nik ?? '—' }}</td>
              <td class="k">Penjamin</td><td class="s">:</td>
              <td class="v">{{ penjaminFull(printData.patient) }}</td>
            </tr>
            <tr>
              <td class="k">Dokter (DPJP)</td><td class="s">:</td><td class="v">{{ printData.patient?.dpjp ?? '—' }}</td>
              <td class="k">Jenis Layanan</td><td class="s">:</td><td class="v">{{ svcLabel(printData.service_type) }}</td>
            </tr>
          </tbody>
        </table>

        <!-- BLOK RAWAT INAP (hanya untuk kwitansi RI) -->
        <table v-if="printData.inpatient" class="rp-meta rp-meta-ranap">
          <tbody>
            <tr>
              <td class="k">Ruang / Bed</td><td class="s">:</td>
              <td class="v">{{ printData.inpatient.room || '—' }}<span v-if="printData.inpatient.bed"> / {{ printData.inpatient.bed }}</span></td>
              <td class="k">Kelas Hak</td><td class="s">:</td>
              <td class="v">{{ printData.inpatient.kelas_rawat_hak || '—' }}<span v-if="printData.inpatient.titip_note"> ({{ printData.inpatient.titip_note }})</span></td>
            </tr>
            <tr>
              <td class="k">Tgl Masuk</td><td class="s">:</td><td class="v">{{ printData.inpatient.admission_at || '—' }}</td>
              <td class="k">Tgl Keluar</td><td class="s">:</td><td class="v">{{ printData.inpatient.discharge_at || '—' }}</td>
            </tr>
            <tr>
              <td class="k">Lama Rawat</td><td class="s">:</td><td class="v"><strong>{{ printData.inpatient.los ?? '—' }} malam</strong></td>
              <td class="k">Cara Keluar</td><td class="s">:</td><td class="v">{{ printData.inpatient.discharge_type || '—' }}</td>
            </tr>
          </tbody>
        </table>

        <div class="rp-items">
          <div v-for="grp in groupedPrintItems" :key="grp.name" class="rp-group">
            <div class="rp-group-head">
              <span class="rp-group-name">{{ grp.name }}</span>
              <span class="rp-group-sub">{{ rupiah(grp.subtotal) }}</span>
            </div>
            <div v-for="(item, i) in grp.items" :key="item.id ?? `${grp.name}-${i}`" class="rp-row">
              <span class="rp-row-desc">
                {{ item.description }}<span v-if="Number(item.quantity) > 1" class="rp-row-qty"> ({{ item.quantity }}×)</span>
                <span v-if="Number(item.discount_amount) > 0" class="rp-row-disc">
                  diskon −{{ rupiah(item.discount_amount) }}<span v-if="Number(item.discount_percent) > 0"> ({{ Number(item.discount_percent) }}%)</span>
                </span>
              </span>
              <span class="rp-dots"></span>
              <span class="rp-row-amt">
                <span v-if="Number(item.discount_amount) > 0" class="rp-row-gross">{{ rupiah(item.total_price) }}</span>
                {{ rupiah(item.net_price ?? item.total_price) }}
              </span>
            </div>
          </div>
          <div v-if="!(printData.items ?? []).length" class="rp-empty">Tidak ada item</div>
        </div>

        <div class="rp-summary">
          <table>
            <tbody>
              <tr><td>Subtotal</td><td class="c-num">{{ rupiah(printData.summary?.subtotal) }}</td></tr>
              <tr v-if="Number(printData.summary?.item_discount)"><td>Diskon Item</td><td class="c-num">− {{ rupiah(printData.summary?.item_discount) }}</td></tr>
              <tr v-if="Number(printData.summary?.discount)">
                <td>Diskon Global<span v-if="Number(printData.summary?.discount_percent) > 0"> ({{ Number(printData.summary?.discount_percent) }}%)</span></td>
                <td class="c-num">− {{ rupiah(printData.summary?.discount) }}</td>
              </tr>
              <tr v-if="Number(printData.summary?.tax)"><td>Pajak</td><td class="c-num">{{ rupiah(printData.summary?.tax) }}</td></tr>
              <tr class="rp-grand"><td>TOTAL TAGIHAN</td><td class="c-num">{{ rupiah(printData.summary?.total) }}</td></tr>
              <tr v-if="Number(printData.summary?.covered_amount)"><td>Ditanggung Asuransi</td><td class="c-num">− {{ rupiah(printData.summary?.covered_amount) }}</td></tr>
              <tr><td>Dibayar Pasien</td><td class="c-num">{{ rupiah(printData.summary?.paid_amount) }}</td></tr>
              <tr v-if="printData.invoice?.is_paid && Number(printData.summary?.change)"><td>Kembalian</td><td class="c-num">{{ rupiah(printData.summary?.change) }}</td></tr>
              <tr v-if="Number(printData.summary?.sisa)" class="rp-sisa"><td>Sisa Tagihan</td><td class="c-num">{{ rupiah(printData.summary?.sisa) }}</td></tr>
            </tbody>
          </table>
        </div>

        <div :class="['rp-status', printData.invoice?.is_paid ? 'lunas' : 'belum']">
          {{ printData.invoice?.is_paid ? 'LUNAS' : 'BELUM LUNAS / PRO FORMA' }}
        </div>

        <div class="rp-sign">
          <div class="rp-sign-col">
            <div class="rp-sign-lbl">Kasir</div>
            <div v-if="printData.print_settings?.show_esign !== false && printData.cashier" class="rp-esign">
              <span class="rp-esign-badge">✓ Ditandatangani elektronik</span>
              <div class="rp-esign-name">{{ printData.cashier }}</div>
              <div class="rp-esign-meta">
                {{ printData.invoice?.number }}<span v-if="printData.invoice?.paid_at"> · {{ printData.invoice.paid_at }}</span>
              </div>
            </div>
            <template v-else>
              <div class="rp-sign-space"></div>
              <div class="rp-sign-name">( ......................................... )</div>
            </template>
          </div>
        </div>

        <footer class="rp-footer">
          <span v-if="printData.print_settings?.show_footer !== false && printData.clinic?.director_name">
            Penanggung Jawab Rumah Sakit: {{ printData.clinic.director_name }}<span v-if="printData.clinic?.director_sip"> · SIP: {{ printData.clinic.director_sip }}</span> ·
          </span>
          Dicetak: {{ new Date().toLocaleString('id-ID') }} · Arumed Apps
        </footer>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.kasir { padding: 0; }
/* Konten dipusatkan + lega di layar ultra-wide; antrean bisa diciutkan → kolom kerja luas. */
.grid { display: grid; grid-template-columns: 290px 1fr; gap: 1rem; align-items: start; max-width: 1680px; margin-inline: auto; transition: grid-template-columns .22s ease; }
.kasir.q-collapsed .grid { grid-template-columns: 56px 1fr; }

/* ─── Rail antrean (saat diciutkan) ─────────────────────────────────────── */
.queue-rail { display: none; }
.kasir.q-collapsed .queue-rail {
  display: flex; flex-direction: column; align-items: center; gap: 10px;
  width: 56px; padding: 12px 0; cursor: pointer;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; color: var(--td);
  position: sticky; top: 0;
}
.kasir.q-collapsed .queue-rail:hover { border-color: var(--ga); color: var(--ga); }
.queue-rail .qr-chevron { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.2; }
.queue-rail .qr-count { font-size: 14px; font-weight: 700; color: var(--ga); font-variant-numeric: tabular-nums; }
.queue-rail .qr-label { writing-mode: vertical-rl; transform: rotate(180deg); font-size: 11px; font-weight: 600; letter-spacing: .04em; color: var(--tu); }
.kasir.q-collapsed .col-queue .card { display: none; }

/* Tombol ciutkan di header antrean */
.head-actions { display: flex; align-items: center; gap: 6px; }
.panel-collapse { width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--gb); border-radius: 6px; background: var(--bs); color: var(--tu); cursor: pointer; padding: 0; }
.panel-collapse:hover { border-color: var(--ga); color: var(--ga); }
.panel-collapse svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; }

/* ─── LEFT QUEUE (meniru PerawatView) ───────────────────────────────────── */
.col-queue .card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
.card-head { padding: 0.7rem 1rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.card-head-title { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--td); }
.card-head-title svg { width: 14px; height: 14px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.card-head-sub { font-size: 11px; color: var(--tu); margin-top: 3px; }
.pill-live { font-size: 9.5px; font-weight: 700; padding: 2px 8px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; letter-spacing: 0.05em; }
.queue-scroll { padding: 0.6rem; max-height: calc(100vh - 200px); overflow-y: auto; }

.stats-bar { display: flex; align-items: center; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 8px 12px; margin-bottom: 0.65rem; gap: 0; }
.stat-item { flex: 1; text-align: center; }
.stat-divider { width: 1px; height: 28px; background: var(--gb); flex-shrink: 0; }
.stat-label { display: block; font-size: 9.5px; color: var(--tu); letter-spacing: 0.03em; margin-bottom: 2px; }
.stat-num { display: block; font-size: 17px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.stat-waiting { color: #d97706; }
.stat-done    { color: var(--st); }

.primary-filter { display: flex; gap: 4px; margin-bottom: 0.5rem; }
.pf-btn { flex: 1; height: 32px; font-size: 11.5px; font-weight: 500; border: 1.5px solid var(--gb); border-radius: 8px; background: var(--bs); color: var(--tm); cursor: pointer; font-family: 'Inter', sans-serif; transition: all .13s; display: flex; align-items: center; justify-content: center; gap: 5px; }
.pf-btn:hover { border-color: var(--ga); color: var(--ga); }
.pf-btn.a { background: var(--gd); color: #fff; border-color: var(--gd); }
.pf-ct { font-size: 9px; font-weight: 700; padding: 0 5px; border-radius: 10px; background: rgba(255,255,255,.25); }

.ptype-tabs { display: flex; gap: 3px; margin-bottom: 0.55rem; }
.ptype-tab { flex: 1; padding: 5px 4px; font-size: 10px; font-weight: 600; border: 1.5px solid var(--gb); border-radius: 7px; background: var(--bs); color: var(--tu); cursor: pointer; font-family: 'Inter',sans-serif; text-align: center; transition: all .13s; white-space: nowrap; }
.ptype-tab:hover { border-color: var(--ga); color: var(--ga); }
.ptype-tab.a { color: #fff; font-weight: 700; }
.ptype-tab.ptype-bpjs.a { background: #1e40af; border-color: #1e40af; }
.ptype-tab.ptype-umum.a { background: var(--ga); border-color: var(--ga); }
.ptype-tab.a:not(.ptype-bpjs):not(.ptype-umum) { background: var(--gd); border-color: var(--gd); }

.q-search-wrap { margin-bottom: 0.5rem; }
.q-search { width: 100%; height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 10px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.q-search:focus { border-color: var(--ga); background: #fff; }

.q-skeleton { height: 78px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; margin-bottom: 5px; animation: shimmer 1.2s ease-in-out infinite; }
@keyframes shimmer { 0%, 100% { opacity: .6 } 50% { opacity: .35 } }
.empty-section { text-align: center; padding: 0.75rem 1rem; font-size: 11px; color: var(--th); background: var(--bi); border-radius: 7px; margin-bottom: 6px; border: 1px dashed var(--gb); }

.q-item { display: flex; gap: 8px; padding: 8px 10px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; margin-bottom: 5px; cursor: pointer; transition: all 0.14s; width: 100%; text-align: left; font-family: 'Inter', sans-serif; flex-wrap: wrap; }
.q-item:hover { border-color: var(--lm); background: var(--gl); }
.q-item.active { border-color: var(--ga); background: var(--gl); }
.q-item.done { opacity: .55; }
.q-item:focus-visible { outline: 2px solid var(--ga); outline-offset: 2px; }
.qi-left { display: flex; flex-direction: column; gap: 4px; min-width: 56px; }
.q-num { font-weight: 700; font-size: 13.5px; color: var(--ga); letter-spacing: 0.03em; }
.q-info { flex: 1; min-width: 0; }
.q-name { font-size: 12.5px; font-weight: 500; color: var(--td); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.q-meta { font-size: 10px; color: var(--tu); margin-top: 2px; }
.q-tags { display: flex; gap: 3px; margin-top: 3px; flex-wrap: wrap; }
.qi-time { font-size: 10px; color: var(--tu); font-variant-numeric: tabular-nums; }

.q-actions { display: flex; gap: 4px; margin-top: 5px; padding-top: 5px; border-top: 1px dashed var(--gb); width: 100%; }
.q-act-btn { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; font-size: 10px; font-weight: 600; border-radius: 5px; border: 1px solid; cursor: pointer; font-family: 'Inter',sans-serif; transition: background .12s, color .12s, border-color .12s, transform .07s, box-shadow .07s; background: none; user-select: none; }
.q-act-btn svg { width: 10px; height: 10px; }
.q-act-btn.call { color: var(--ga); border-color: var(--ga); background: var(--gl); }
.q-act-btn.call:hover { background: var(--ga); color: #fff; }
.q-act-btn.call.recall { color: #b45309; border-color: #fbbf24; background: #fef3c7; }
.q-act-btn.call.recall:hover { background: #f59e0b; color: #fff; border-color: #f59e0b; }
.q-act-btn.skip { color: var(--tu); border-color: var(--gb); }
.q-act-btn.skip:hover { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
/* Tier-3 button-press */
.q-act-btn:active:not(:disabled) { transform: scale(0.93); box-shadow: inset 0 1px 3px rgba(0,0,0,.12); }
.q-act-btn.call:active:not(:disabled) { background: var(--gd); color: #fff; border-color: var(--gd); }
.q-act-btn.call.recall:active:not(:disabled) { background: #b45309; color: #fff; border-color: #b45309; }
.q-act-btn.skip:active:not(:disabled) { background: var(--wt); color: #fff; border-color: var(--wt); }
.q-act-btn:disabled { opacity: .55; cursor: wait; }

.pill { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px; }
.pill-icon { width: 8px; height: 8px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; flex-shrink: 0; }
.pill-waiting   { background: #fef3c7; color: #92400e; }
.pill-called    { background: #dbeafe; color: #1e40af; }
.pill-in_progress { background: #dbeafe; color: #1e40af; }
.pill-completed { background: var(--sb); color: var(--st); }
.pill-bpjs  { background: #dbeafe; color: #1e40af; }
.pill-umum  { background: var(--gl); color: var(--ga); }
.pill-asn   { background: var(--pb); color: var(--pt); }
.pill-done  { background: var(--sb); color: var(--st); }
.pill-belum { background: var(--wb); color: var(--wt); }

/* ─── RIGHT ──────────────────────────────────────────────────────────────── */
.rp { display: flex; flex-direction: column; gap: 0.75rem; }
.nvt { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); padding: 0 0.5rem; }
.nt { padding: 0.6rem 1rem; font-size: 12px; font-weight: 500; color: var(--tu); background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: 'Inter', sans-serif; display: inline-flex; align-items: center; gap: 6px; }
.nt:hover { color: var(--td); }
.nt.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }
.nt svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

.empty-state { padding: 4rem 2rem; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; display: flex; flex-direction: column; align-items: center; gap: 0.85rem; color: var(--th); text-align: center; }
.empty-state svg { width: 56px; height: 56px; fill: none; stroke: var(--gb); stroke-width: 1.5; stroke-linecap: round; }
.empty-state p { font-size: 13px; }


.pt-banner { background: linear-gradient(135deg, var(--gm), var(--gd)); color: #fff; padding: 0.85rem 1.1rem; border-radius: 12px; display: flex; align-items: center; gap: 0.85rem; margin-bottom: 0.85rem; }
.pt-info { flex: 1; min-width: 0; }
.pt-name { font-family: 'Space Grotesk', serif; font-size: 18px; line-height: 1.1; }
.pt-meta { font-size: 11px; color: rgba(255, 255, 255, 0.6); margin-top: 3px; }
.pt-contact { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 5px; }
.pt-contact-item { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; color: rgba(255, 255, 255, 0.82); max-width: 340px; }
.pt-contact-item svg { width: 12px; height: 12px; fill: none; stroke: rgba(255, 255, 255, 0.7); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
.pt-contact-item span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pt-contact-empty { color: rgba(255, 255, 255, 0.5); font-style: italic; }
.pt-tags { display: flex; gap: 4px; margin-top: 5px; flex-wrap: wrap; }
.ptg { font-size: 9px; font-weight: 700; padding: 2px 7px; border-radius: 20px; }
.ptg-b { background: rgba(147, 197, 253, 0.2); color: #93c5fd; border: 1px solid rgba(147, 197, 253, 0.2); }
.ptg-a { background: rgba(217, 70, 239, 0.18); color: #f0abfc; border: 1px solid rgba(217, 70, 239, 0.25); }
.ptg-u { background: rgba(56, 189, 248, 0.2); color: var(--lm); border: 1px solid rgba(56, 189, 248, 0.25); }
.ptg-ok { background: rgba(134, 239, 172, 0.2); color: var(--sbd); border: 1px solid rgba(134, 239, 172, 0.25); }
.pt-total { text-align: right; flex-shrink: 0; }
.pt-total-v { font-size: 22px; font-weight: 700; color: var(--lm); font-variant-numeric: tabular-nums; line-height: 1; }
.pt-total-l { font-size: 9.5px; color: rgba(255, 255, 255, 0.45); text-transform: uppercase; letter-spacing: 0.08em; margin-top: 4px; }

.layout { display: grid; grid-template-columns: 1fr 340px; gap: 0.85rem; }
.col-right { display: flex; flex-direction: column; gap: 0.7rem; }

/* Kirim kwitansi ke email */
.email-send { margin-top: 0.6rem; padding-top: 0.6rem; border-top: 1px dashed var(--gb); }
.email-send-lbl { display: flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; color: var(--tm); margin-bottom: 6px; }
.email-send-lbl svg { width: 13px; height: 13px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.email-send-row { display: flex; gap: 6px; }
.email-send-input { flex: 1; min-width: 0; }
.email-send-btn { flex-shrink: 0; white-space: nowrap; }
.email-send-hint { font-size: 9.5px; color: var(--th); margin-top: 5px; line-height: 1.4; }
.email-status { display: flex; align-items: flex-start; gap: 5px; font-size: 10.5px; font-weight: 500; margin-top: 7px; padding: 5px 8px; border-radius: 6px; line-height: 1.4; }
.email-status svg { width: 13px; height: 13px; flex-shrink: 0; margin-top: 1px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.email-status.es-sent { background: var(--sb); color: var(--st); }
.email-status.es-queued { background: var(--ib); color: var(--it); }
.email-status.es-failed { background: var(--eb); color: var(--et); }

.note-warning { display: flex; gap: 8px; align-items: flex-start; padding: 9px 13px; background: var(--wb); border: 1px solid var(--wbd); border-radius: 9px; color: var(--wt); font-size: 11.5px; margin-bottom: 0.7rem; }
.note-warning svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; margin-top: 1px; }

/* Asuransi warning panel — non-blocker, supervisor harus konfirmasi (Sprint 4) */
.insurance-alert { display: flex; gap: 10px; align-items: flex-start; padding: 11px 14px; border-radius: 9px; font-size: 12px; margin-bottom: 0.7rem; border: 1px solid; }
.insurance-alert.ia-pending { background: var(--wb); border-color: var(--wbd); color: var(--wt); }
.insurance-alert.ia-issue { background: var(--eb); border-color: var(--ebd); color: var(--et); }
.insurance-alert svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; margin-top: 1px; }
.insurance-alert .ia-msg { font-size: 11px; margin-top: 3px; line-height: 1.4; opacity: 0.95; }

/* Panel info eligibility asuransi — readonly referensi, BUKAN auto-calculator */
.elig-panel { background: var(--bc); border: 1.5px solid var(--ga); border-radius: 10px; padding: 0.75rem 0.85rem; margin-bottom: 0.7rem; }
.elig-head { display: flex; align-items: center; gap: 7px; font-size: 11.5px; font-weight: 700; color: var(--td); text-transform: uppercase; letter-spacing: .04em; padding-bottom: 7px; border-bottom: 1px solid var(--gb); margin-bottom: 9px; }
.elig-head svg { width: 14px; height: 14px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.elig-status { margin-left: auto; font-size: 9px; padding: 2px 8px; border-radius: 10px; letter-spacing: 0; text-transform: none; font-weight: 700; }
.elig-status.es-verified { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.elig-status.es-pending { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }
.elig-status.es-issue, .elig-status.es-rejected, .elig-status.es-needs_clarification { background: var(--eb); color: var(--et); border: 1px solid var(--ebd); }
.elig-status.es-none { background: var(--bs); color: var(--tu); border: 1px solid var(--gb); }

.elig-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; }
.elig-cell { background: var(--bs); border-radius: 7px; padding: 7px 9px; }
.elig-lbl { font-size: 9.5px; color: var(--tu); font-weight: 600; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 2px; }
.elig-val { font-size: 13px; font-weight: 700; color: var(--td); font-family: 'Geist Mono', monospace; }
.elig-val.val-warn { color: var(--et); }
.elig-val.val-emphasis { color: var(--td); }

.elig-meta { margin-top: 9px; font-size: 11px; color: var(--tm); padding-top: 7px; border-top: 1px dashed var(--gb); }
.elig-notes { margin-top: 6px; font-size: 11px; color: var(--tm); padding: 5px 8px; background: var(--bs); border-radius: 5px; }
.elig-excl { margin-top: 5px; font-size: 11px; color: var(--et); padding: 5px 8px; background: var(--eb); border: 1px solid var(--ebd); border-radius: 5px; }
.elig-plafon-warn { margin-top: 6px; font-size: 11px; padding: 6px 9px; background: var(--wb); border: 1px solid var(--wbd); color: var(--wt); border-radius: 5px; font-weight: 500; }
.elig-hint { margin-top: 7px; font-size: 10.5px; color: var(--tu); font-style: italic; line-height: 1.45; }

/* Layar sempit: antrean stack di atas konten, rail/collapse disembunyikan
   (CSS override agar tetap tampil penuh tanpa scroll horizontal). */
@media (max-width: 1180px) {
  .grid, .kasir.q-collapsed .grid { grid-template-columns: 1fr; }
  .queue-rail, .panel-collapse { display: none !important; }
  .kasir.q-collapsed .col-queue .card { display: block !important; }
  .queue-scroll { max-height: 440px; }
}
/* Layar sedang: konten utama (layout tagihan) stack supaya tak tergencet. */
@media (max-width: 1000px) {
  .layout { grid-template-columns: 1fr; }
}

@media (max-width: 700px) {
  .elig-grid { grid-template-columns: 1fr 1fr; }
}

.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
.card-body { padding: 1rem; }

.tbl { width: 100%; border-collapse: collapse; }
.tbl th { font-size: 9.5px; font-weight: 600; color: var(--tu); letter-spacing: 0.07em; text-transform: uppercase; padding: 8px 14px; border-bottom: 1px solid var(--gb); text-align: left; background: var(--bs); }
.tbl td { padding: 8px 14px; font-size: 12px; color: var(--td); vertical-align: middle; }
/* Garis pemisah hanya antar item dalam satu grup — bukan tiap baris (lebih clean). */
.kat-group tr + tr td { border-top: 1px solid rgba(0, 0, 0, 0.045); }
.tbl tbody.kat-group tr:hover:not(.kat-group-head) td { background: var(--gl); }
.tbl .num { text-align: right; font-variant-numeric: tabular-nums; }
.tbl .strong { font-weight: 600; }
.tbl .muted { color: var(--tu); font-size: 10.5px; }
.muted-strike { color: var(--tu); text-decoration: line-through; display: block; font-size: 10px; font-weight: 400; }
/* Baris diskon paket — net negatif, warna potongan */
.tbl tbody.kat-group tr.is-diskon td { color: var(--ga); }
.tbl tbody.kat-group tr.is-diskon td.strong { font-weight: 600; }
.empty-row { text-align: center !important; color: var(--th); padding: 1.5rem !important; }

.kat-pill { font-size: 9.5px; font-weight: 600; padding: 2px 7px; border-radius: 20px; white-space: nowrap; }
.kat-registrasi { background: var(--ib); color: var(--it); }
.kat-tindakan   { background: var(--wb); color: var(--wt); }
.kat-obat       { background: var(--gl); color: var(--ga); }
.kat-iol        { background: var(--pb); color: var(--pt); }
.kat-bhp        { background: var(--sb); color: var(--st); }
.kat-penunjang  { background: var(--pb); color: var(--pt); }
.kat-medical_equipment { background: #dbeafe; color: #1e40af; }
.kat-lainnya    { background: var(--bi); color: var(--tm); }

/* ─── Group header per kategori ──────────────────────────────────────────── */
.kat-group-head td { background: var(--bs); padding: 6px 14px; border-top: 1px solid var(--gb); }
.kat-group-head td:first-child { border-left: 3px solid var(--ga); }
.kat-group-name { font-weight: 700; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--td); }
.kat-group-count { font-size: 9.5px; color: var(--tu); margin-left: 8px; font-weight: 500; }
.kat-group-head .num.strong { color: var(--td); font-size: 11.5px; }
.kat-group:first-of-type .kat-group-head td { border-top: none; }
.kat-group tr:first-child td { border-top: none; }
.kat-bpjs { background: #dbeafe; color: #1e40af; }
.kat-umum { background: var(--gl); color: var(--ga); }
.kat-asn  { background: var(--pb); color: var(--pt); }

/* Pill jenis layanan (Rawat Inap / Jalan / IGD) di history. */
.care-pill { display: inline-block; margin-left: 5px; font-size: 9.5px; font-weight: 600; padding: 2px 7px; border-radius: 20px; white-space: nowrap; }
.care-rajal { background: #dbeafe; color: #1e3a8a; }
.care-ranap { background: #dcfce7; color: #14532d; }
.care-igd   { background: #ffedd5; color: #9a3412; }

/* Segmented tab pemisah history Rawat Inap / Jalan / IGD. */
.care-tabs { display: inline-flex; gap: 4px; margin-right: 0.6rem; background: var(--gl); padding: 3px; border-radius: 8px; }
.care-tab { border: none; background: none; cursor: pointer; font-size: 11px; font-weight: 500; color: var(--tu); padding: 4px 10px; border-radius: 6px; font-family: 'Inter', sans-serif; }
.care-tab:hover { color: var(--td); }
.care-tab.a { background: var(--bc); color: var(--ga); font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,.08); }

/* Badge jenis layanan (antrean & kartu pasien) — warna seragam dgn kwitansi. */
.pill-care, .ptg-care { background: #dbeafe; color: #1e3a8a; }
.pill-care.care-ranap, .ptg-care.care-ranap { background: #dcfce7; color: #14532d; }
.pill-care.care-igd,   .ptg-care.care-igd   { background: #ffedd5; color: #9a3412; }
.pill-care.care-rajal, .ptg-care.care-rajal { background: #dbeafe; color: #1e3a8a; }
/* Badge DPJP di kartu antrean & identitas pasien. */
.q-dpjp { display: inline-flex; align-items: center; gap: 4px; margin-top: 4px; max-width: 100%; font-size: 10px; font-weight: 600; color: #4338ca; background: #eef2ff; padding: 2px 7px; border-radius: 6px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.q-dpjp .pill-icon { width: 11px; height: 11px; flex: 0 0 auto; fill: none; stroke: currentColor; stroke-width: 2; }
.ptg-dpjp { background: #eef2ff; color: #4338ca; max-width: 220px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }

.tbl-fi { width: 100%; box-sizing: border-box; height: 30px; font-size: 11px; padding: 0 8px; border-radius: 6px; border: 1px solid var(--gb); background: var(--bc); }
.tbl-fi:focus { border-color: var(--ga); outline: none; }
.tbl-num { width: 78px; text-align: right; }
.tbl-select { font-size: 11px; }
.del-btn { width: 26px; height: 26px; border-radius: 5px; border: 1px solid var(--ebd); background: var(--eb); color: var(--et); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all .12s; }
.del-btn:hover:not(:disabled) { background: var(--et); color: #fff; }
.del-btn:disabled { opacity: .35; cursor: not-allowed; }
.del-btn svg { width: 12px; height: 12px; }

/* ── Tambah tindakan: search-driven picker (konsep sama DokterView Tab 3) ──── */
.add-tindakan-bar { padding: 0.75rem 1rem 0.25rem; }
.tindakan-search-wrap { position: relative; }
.tindakan-search-field {
  position: relative; display: flex; align-items: center; gap: 8px;
  padding: 0 12px 0 36px; height: 38px;
  border: 1.5px solid var(--gb); border-radius: 9px;
  background: var(--bs); transition: border-color .13s, background .13s;
}
.tindakan-search-field:focus-within { border-color: var(--ga); background: var(--bc); }
.tindakan-search-icon {
  position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
  width: 14px; height: 14px; fill: none; stroke: var(--tu); stroke-width: 2; stroke-linecap: round;
}
.tindakan-search-input {
  flex: 1; min-width: 0; border: none; background: transparent;
  padding: 0; height: auto; font-size: 13px; color: var(--td);
  outline: none; font-family: 'Inter', sans-serif;
}
.tindakan-search-input::placeholder { color: var(--th); }
.tindakan-search-clear {
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; border-radius: 50%; border: none;
  background: var(--gb); color: var(--tu); cursor: pointer; padding: 0;
  flex-shrink: 0; transition: background .12s;
}
.tindakan-search-clear:hover { background: var(--th); color: #fff; }
.tindakan-search-clear svg { width: 11px; height: 11px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }
.tindakan-search-drop {
  position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 30;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 9px;
  box-shadow: 0 8px 24px rgba(0,0,0,.10);
  max-height: 320px; overflow-y: auto; padding: 4px;
}
.tindakan-search-hint {
  padding: 7px 10px; font-size: 10.5px; color: var(--tu);
  border-top: 1px dashed var(--gb); text-align: center; font-style: italic;
}
.tarif-list-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 7px; cursor: pointer; transition: background .12s; }
.tarif-list-item:hover { background: var(--gl); }
.tarif-list-item.in-list { background: var(--gl); }
.tarif-list-item.is-adding { opacity: .55; pointer-events: none; }
.tarif-list-name { flex: 1; font-size: 12px; font-weight: 500; color: var(--td); min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tarif-list-price { font-size: 11.5px; font-weight: 600; color: var(--td); white-space: nowrap; font-variant-numeric: tabular-nums; }
.tarif-list-icon { width: 14px; height: 14px; flex-shrink: 0; fill: none; stroke-width: 2; stroke-linecap: round; }
.tarif-list-icon.add { stroke: var(--ga); }
.tarif-list-icon.check { stroke: var(--st); stroke-width: 2.5; }
.tarif-kode { font-size: 9.5px; font-weight: 700; color: var(--ga); letter-spacing: 0.03em; white-space: nowrap; }
.tarif-kat { font-size: 8px; font-weight: 700; padding: 1px 5px; border-radius: 3px; letter-spacing: 0.03em; white-space: nowrap; }
.tarif-kat.td { background: var(--gl); color: var(--td); border: 1px solid rgba(31,125,74,0.2); }
.tarif-empty { text-align: center; padding: 1rem; font-size: 11px; color: var(--th); }

/* Filter kategori buku tarif (chips) */
.tarif-type-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
.tarif-type-chip {
  font-size: 10.5px; font-weight: 600; padding: 3px 9px; border-radius: 20px;
  border: 1px solid var(--gb); background: var(--bc); color: var(--tm); cursor: pointer; transition: all .12s;
}
.tarif-type-chip:hover { border-color: var(--ga); color: var(--td); }
.tarif-type-chip.a { background: var(--ga); border-color: var(--ga); color: #fff; }

/* Badge tipe item di hasil pencarian */
.tarif-type-badge {
  font-size: 8px; font-weight: 700; padding: 1px 5px; border-radius: 3px;
  letter-spacing: 0.03em; white-space: nowrap; flex-shrink: 0; background: var(--bs); color: var(--tm);
}
.tarif-type-badge.tt-tindakan { background: var(--gl); color: var(--ga); }
.tarif-type-badge.tt-obat { background: var(--ib); color: var(--it); }
.tarif-type-badge.tt-bhp { background: var(--wb); color: var(--wt); }
.tarif-type-badge.tt-iol { background: var(--pb); color: var(--pt); }
.tarif-type-badge.tt-medical_equipment { background: var(--sb); color: var(--st); }

.tbl-foot { padding: 0.75rem 1.1rem; border-top: 2px solid var(--gb); background: var(--bi); }
.fg { display: flex; flex-direction: column; gap: 4px; }
.fl { font-size: 10px; font-weight: 600; color: var(--tm); letter-spacing: 0.05em; text-transform: uppercase; }
.totals { padding-top: 0.4rem; border-top: 1px solid var(--gb); }
.totals .row { display: flex; justify-content: space-between; align-items: center; font-size: 12px; padding: 3px 0; color: var(--tm); }
.totals .row.blue { color: var(--it); }
.totals .row.red { color: var(--et); }
.totals .row.green { color: var(--st); }
.totals .row.grand { font-size: 15px; font-weight: 700; color: var(--td); padding: 6px 0 0; border-top: 1px dashed var(--gb); margin-top: 4px; }
.totals .global-disc .disc-inputs { display: inline-flex; align-items: center; gap: 6px; }
.totals .global-disc .fi { height: 28px; font-size: 11px; padding: 0 6px; }
.totals .global-disc .disc-pc { width: 60px; text-align: right; }
.totals .global-disc .disc-rp { width: 110px; text-align: right; }
.totals .global-disc .disc-suffix { color: var(--tm); font-weight: 600; font-size: 11px; }
.totals .global-disc .disc-sep { color: var(--tu); font-size: 10px; }
.totals .global-disc .disc-rp-wrap { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; color: var(--tm); }

.cover-confirm-box { text-align: center; padding: 1.1rem 1rem; background: var(--sb); border: 1px solid var(--sbd); border-radius: 12px; margin-bottom: .7rem; }
.cover-confirm-box svg { width: 38px; height: 38px; fill: none; stroke: var(--st); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.cover-confirm-title { font-size: 12px; font-weight: 700; color: var(--st); text-transform: uppercase; letter-spacing: .04em; margin-top: 6px; }
.cover-confirm-amount { font-size: 24px; font-weight: 800; color: var(--st); font-family: 'Geist Mono', monospace; margin: 3px 0; }
.cover-confirm-sub { font-size: 11.5px; color: var(--tm); line-height: 1.4; }
/* Varian BPJS — biru (bedakan dari ditanggung asuransi/TPA yang hijau). */
.cover-confirm-box.cover-bpjs { background: #dbeafe; border-color: #93c5fd; }
.cover-confirm-box.cover-bpjs svg { stroke: #1e40af; }
.cover-confirm-box.cover-bpjs .cover-confirm-title,
.cover-confirm-box.cover-bpjs .cover-confirm-amount { color: #1e40af; }

/* ── Setting Print: popover toggle elemen kwitansi ──────────────────────────── */
.print-set-wrap { position: relative; display: inline-flex; }
.btn.btn-icon svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.print-set-pop {
  position: absolute; right: 0; top: calc(100% + 6px); z-index: 40;
  width: 268px; background: var(--bc); border: 1px solid var(--gb);
  border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,.14); padding: 0.6rem;
}
.print-set-head { display: flex; align-items: center; justify-content: space-between; font-size: 10.5px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: .04em; padding: 0 4px 7px; border-bottom: 1px solid var(--gb); margin-bottom: 5px; }
.print-set-saving { font-size: 9px; font-weight: 600; color: var(--ga); text-transform: none; letter-spacing: 0; font-style: italic; }
.print-set-row { display: flex; align-items: center; gap: 9px; padding: 7px 4px; font-size: 12px; color: var(--td); cursor: pointer; border-radius: 6px; transition: background .12s; }
.print-set-row:hover { background: var(--gl); }
.print-set-row input { width: 15px; height: 15px; accent-color: var(--ga); cursor: pointer; flex-shrink: 0; }
.print-set-foot { font-size: 10px; color: var(--tu); padding: 6px 4px 2px; border-top: 1px dashed var(--gb); margin-top: 4px; line-height: 1.4; }

.mixed-header { display: flex; justify-content: space-between; align-items: center; padding: .5rem .6rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; margin-bottom: .55rem; }
.mixed-lbl { font-size: 11px; color: var(--tu); font-weight: 600; }
.mixed-total { font-size: 14px; font-weight: 700; color: var(--td); }
.mixed-list { display: flex; flex-direction: column; gap: 5px; margin-bottom: .5rem; }
.mixed-row { display: flex; align-items: center; gap: 8px; }
.pm-icon.sm { width: 24px; height: 24px; border-radius: 6px; flex-shrink: 0; }
.pm-icon.sm svg { width: 11px; height: 11px; fill: none; stroke-width: 2; stroke-linecap: round; }
.mixed-name { font-size: 11.5px; color: var(--td); width: 90px; flex-shrink: 0; }
.mixed-input { height: 28px; font-size: 11.5px; text-align: right; }
.mixed-status { display: flex; justify-content: space-between; font-size: 11px; font-weight: 600; padding: 5px 9px; border-radius: 7px; margin-bottom: .55rem; }
.mixed-status.ok { background: var(--sb); color: var(--st); }
.mixed-status.warn { background: var(--wb); color: var(--wt); }

.pm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 0.6rem; }
.pm { display: flex; flex-direction: column; align-items: center; gap: 5px; padding: 9px 6px; border: 1.5px solid var(--gb); border-radius: 9px; cursor: pointer; transition: all 0.14s; background: var(--bs); }
.pm:hover { border-color: var(--lm); }
.pm.sel { border-color: var(--ga); background: var(--gl); }
.pm-icon { width: 26px; height: 26px; border-radius: 7px; display: flex; align-items: center; justify-content: center; }
.pm-icon svg { width: 13px; height: 13px; fill: none; stroke-width: 2; stroke-linecap: round; }
.pm span { font-size: 11px; font-weight: 500; color: var(--td); }
.pm.sel span { color: var(--td); font-weight: 600; }
.cash-row { margin-bottom: 0.6rem; }
.kembalian { font-size: 11px; color: var(--st); margin-top: 3px; font-weight: 600; }

.fi { width: 100%; height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 10px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.fi:focus { border-color: var(--ga); background: #fff; box-shadow: 0 0 0 3px rgba(31, 125, 74, 0.09); }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 14px; height: 36px; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 12.5px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; }
.btn-sm { height: 28px; padding: 0 10px; font-size: 11px; }
.btn-lg { height: 42px; padding: 0 18px; font-size: 13px; font-weight: 600; }
.btn-full { width: 100%; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover { background: var(--gm); }
.btn-success { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-success:hover:not(:disabled) { background: var(--gm); }
.btn-success:disabled { background: var(--th); cursor: not-allowed; }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover { border-color: var(--ga); color: var(--td); background: var(--gl); }
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.sp { width: 14px; height: 14px; border-radius: 50%; border: 2px solid rgba(255, 255, 255, 0.3); border-top-color: #fff; animation: spin 0.7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.stat-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.6rem; margin-bottom: 0.75rem; }
.stat-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 11px; padding: 0.75rem; display: flex; align-items: center; gap: 9px; }
.stat-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-icon svg { width: 16px; height: 16px; fill: none; stroke-width: 2; stroke-linecap: round; }
.stat-val { font-size: 16px; font-weight: 700; color: var(--td); line-height: 1; }
.stat-lbl { font-size: 10px; color: var(--tu); margin-top: 2px; }

.filter-row { display: flex; gap: 0.4rem; align-items: center; }
.filter-row .fi { width: 150px; height: 28px; font-size: 11px; }
.filter-row input[type="date"].fi { width: 138px; }

.hist-print-btn {
  display: inline-flex; align-items: center; gap: 5px;
  height: 26px; padding: 0 10px; border-radius: 6px;
  border: 1.5px solid var(--gb); background: var(--bs); color: var(--td);
  font-family: 'Inter', sans-serif; font-size: 11px; font-weight: 500; cursor: pointer;
  transition: all .12s;
}
.hist-print-btn svg { width: 13px; height: 13px; }
.hist-print-btn:hover:not(:disabled) { border-color: var(--ga); color: var(--ga); background: var(--gl); }
.hist-print-btn:disabled { opacity: .5; cursor: not-allowed; }

.toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 999; display: flex; flex-direction: column; gap: 6px; }
.toast { padding: 9px 13px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); min-width: 230px; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
</style>

<!-- ═══ DOKUMEN CETAK A4 — gaya GLOBAL (tidak scoped) supaya rule @media print bekerja ═══ -->
<style>
.rincian-print { display: none; }

@media print {
  @page { size: A4 portrait; margin: 14mm 15mm; }

  /* Reset base.css (body { min-width:1280px } & html,body { height:100% }) yang
     bikin kanvas raksasa → halaman A4 jadi blank. Tanpa ini print kosong. */
  html, body {
    width: auto !important;
    min-width: 0 !important;
    height: auto !important;
    overflow: visible !important;
    background: #fff !important;
  }

  /* .rincian-print di-teleport ke <body> (sibling #app). Sembunyikan seluruh
     app, sisakan node cetak. Pakai #app display:none (bukan body > *:not())
     karena lebih andal dengan reset di atas. */
  #app { display: none !important; }

  .rincian-print {
    display: block !important;
    position: relative;
    color: #000;
    font-family: 'Inter', Arial, sans-serif;
    font-size: 11px;
    line-height: 1.5;
  }

  .rincian-print .rp-watermark {
    position: fixed; top: 45%; left: 50%;
    transform: translate(-50%, -50%) rotate(-30deg);
    font-size: 92px; font-weight: 800; letter-spacing: .12em;
    color: rgba(0, 0, 0, 0.06); z-index: 0; pointer-events: none;
  }

  .rincian-print .rp-kop { display: flex; align-items: center; gap: 14px; border-bottom: 3px double #000; padding-bottom: 9px; }
  .rincian-print .rp-logo { height: 62px; width: auto; object-fit: contain; }
  .rincian-print .rp-clinic { font-size: 19px; font-weight: 800; letter-spacing: .02em; }
  .rincian-print .rp-line { font-size: 10.5px; }

  .rincian-print .rp-title { text-align: center; font-size: 14px; font-weight: 800; letter-spacing: .06em; text-decoration: underline; margin: 12px 0 1px; }
  .rincian-print .rp-title.rp-svc-ranap { color: #14532d; }
  .rincian-print .rp-title.rp-svc-igd   { color: #9a3412; }
  .rincian-print .rp-title.rp-svc-rajal { color: #1e3a8a; }
  .rincian-print .rp-subtitle { text-align: center; font-size: 11px; margin-bottom: 12px; }

  .rincian-print .rp-meta { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  .rincian-print .rp-meta td { padding: 1.5px 0; vertical-align: top; font-size: 11px; }
  .rincian-print .rp-meta .k { width: 15%; color: #333; }
  .rincian-print .rp-meta .s { width: 10px; }
  .rincian-print .rp-meta .v { width: 35%; font-weight: 600; }

  /* Rincian item — daftar tanpa garis/tabel (list style). */
  .rincian-print .rp-items { margin-bottom: 12px; }
  .rincian-print .rp-group { margin-bottom: 9px; page-break-inside: avoid; }
  .rincian-print .rp-group-head { display: flex; align-items: baseline; justify-content: space-between; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }
  .rincian-print .rp-group-sub { font-weight: 700; white-space: nowrap; }
  .rincian-print .rp-row { display: flex; align-items: baseline; font-size: 10.8px; padding: 1.5px 0 1.5px 12px; }
  .rincian-print .rp-row-desc { flex: 0 1 auto; }
  .rincian-print .rp-row-qty { color: #555; }
  .rincian-print .rp-row-disc { color: #b45309; font-size: 9.5px; margin-left: 6px; }
  .rincian-print .rp-dots { flex: 1 1 auto; border-bottom: 1px dotted #bbb; margin: 0 6px; transform: translateY(-2px); min-width: 14px; }
  .rincian-print .rp-row-amt { flex: 0 0 auto; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .rincian-print .rp-row-gross { color: #999; text-decoration: line-through; font-size: 9.5px; margin-right: 5px; }
  .rincian-print .rp-empty { font-style: italic; color: #777; padding: 4px 12px; }

  .rincian-print .rp-summary { display: flex; justify-content: flex-end; margin-bottom: 16px; }
  .rincian-print .rp-summary table { border-collapse: collapse; min-width: 280px; }
  .rincian-print .rp-summary td { padding: 2.5px 7px; font-size: 11px; }
  .rincian-print .rp-summary td.c-num { text-align: right; white-space: nowrap; }
  .rincian-print .rp-summary .rp-grand td { border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; font-weight: 800; font-size: 12.5px; }
  .rincian-print .rp-summary .rp-sisa td { font-weight: 700; }

  .rincian-print .rp-status { display: inline-block; border: 2px solid #000; padding: 3px 14px; font-weight: 800; letter-spacing: .08em; font-size: 12px; margin-bottom: 24px; }
  .rincian-print .rp-status.lunas { color: #15803d; border-color: #15803d; }
  .rincian-print .rp-status.belum { color: #b45309; border-color: #b45309; }

  .rincian-print .rp-sign { display: flex; justify-content: flex-end; page-break-inside: avoid; }
  .rincian-print .rp-sign-col { width: 45%; text-align: center; }
  .rincian-print .rp-sign-lbl { font-size: 11px; margin-bottom: 4px; }
  .rincian-print .rp-sign-space { height: 62px; }
  .rincian-print .rp-sign-name { font-size: 11px; }
  /* E-sign kasir — badge teks ringan (bukan TTD basah). */
  .rincian-print .rp-esign { display: inline-block; padding-top: 6px; }
  .rincian-print .rp-esign-badge { display: inline-block; font-size: 9px; font-weight: 700; color: #15803d; border: 1px solid #15803d; border-radius: 4px; padding: 2px 8px; letter-spacing: .02em; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .rincian-print .rp-esign-name { font-size: 11.5px; font-weight: 700; margin-top: 5px; }
  .rincian-print .rp-esign-meta { font-size: 8.5px; color: #555; margin-top: 1px; }

  .rincian-print .rp-footer { margin-top: 28px; padding-top: 7px; border-top: 1px solid #999; text-align: center; font-size: 9px; color: #444; }
}
</style>
