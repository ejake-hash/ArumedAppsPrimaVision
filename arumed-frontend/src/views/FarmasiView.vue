<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { farmasiApi, unitRequestApi, unitReturnApi } from '@/services/api'
import RequestObatModal from '@/components/farmasi/RequestObatModal.vue'
import ReturObatModal from '@/components/farmasi/ReturObatModal.vue'
import Pager from '@/components/common/Pager.vue'

const pgTab = ref('dispensing')

// ─── Antrean Farmasi ────────────────────────────────────────────────────────
const queue          = ref([])
const queueLoading   = ref(false)
const queueError     = ref('')
const rxPrimaryFilter   = ref('waiting')   // 'waiting' | 'done'
const rxSecondaryFilter = ref('semua')     // 'semua' | 'bpjs' | 'umum'
const rxSearch          = ref('')

// Verifikasi adalah langkah UI-only (tidak ada endpoint terpisah).
// Tandai resep yang sudah diverifikasi tapi belum mulai dispensing di sini.
const verifiedRxIds  = ref(new Set())

function pickActiveRx(prescriptions = []) {
  if (!Array.isArray(prescriptions) || !prescriptions.length) return null
  const active = prescriptions.find((r) => ['DRAFT', 'SUBMITTED', 'DISPENSING'].includes(r.status))
  return active ?? prescriptions.find((r) => r.status === 'DISPENSED') ?? prescriptions[0]
}

// Buang ID resep yang sudah tidak ada lagi di antrean hari ini, supaya Set
// "verifikasi UI-only" tidak tumbuh tanpa batas & status tidak salah lintas-hari.
function pruneVerifiedRxIds() {
  if (!verifiedRxIds.value.size) return
  const live = new Set()
  for (const q of queue.value) {
    for (const rx of (q.visit?.prescriptions ?? [])) live.add(rx.id)
  }
  const next = new Set()
  for (const id of verifiedRxIds.value) if (live.has(id)) next.add(id)
  if (next.size !== verifiedRxIds.value.size) verifiedRxIds.value = next
}

function rxStatusOf(q) {
  if (q.status === 'COMPLETED') return 'done'
  const rx = pickActiveRx(q.visit?.prescriptions)
  if (!rx) return 'menunggu'
  if (rx.status === 'DISPENSED') return 'done'
  if (rx.status === 'DISPENSING') return 'disiapkan'
  if (verifiedRxIds.value.has(rx.id)) return 'verifikasi'
  return 'menunggu'
}

function guarantorType(q) {
  return (q.visit?.guarantor_type ?? '').toUpperCase() === 'BPJS' ? 'bpjs' : 'umum'
}

function formatTime(ts) {
  if (!ts) return '--:--'
  const d = new Date(ts)
  return Number.isNaN(d.getTime()) ? '--:--'
    : d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false })
}

const belumCount   = computed(() => queue.value.filter((q) => rxStatusOf(q) !== 'done').length)
const selesaiCount = computed(() => queue.value.filter((q) => rxStatusOf(q) === 'done').length)

const filtRx = computed(() => {
  let l = queue.value

  // Primary: belum diserahkan vs selesai
  if (rxPrimaryFilter.value === 'waiting') l = l.filter((q) => rxStatusOf(q) !== 'done')
  else                                     l = l.filter((q) => rxStatusOf(q) === 'done')

  // Secondary: jenis penjamin
  if (rxSecondaryFilter.value === 'bpjs')      l = l.filter((q) => guarantorType(q) === 'bpjs')
  else if (rxSecondaryFilter.value === 'umum') l = l.filter((q) => guarantorType(q) !== 'bpjs')

  if (rxSearch.value) {
    const s = rxSearch.value.toLowerCase()
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
  queueError.value   = ''
  try {
    const { data } = await farmasiApi.antrian()
    queue.value = data.data ?? []
    pruneVerifiedRxIds()
  } catch (err) {
    queueError.value = err.response?.data?.message ?? 'Gagal memuat antrean'
    toast('w', queueError.value)
  } finally {
    queueLoading.value = false
  }
}

// ─── Detail resep terpilih ──────────────────────────────────────────────────
const selQ          = ref(null)   // queue item dipilih
const selRx         = ref(null)   // resep penuh (dengan items.medication)
const selRxLoading  = ref(false)

const dispSteps = ['Verifikasi', 'Siapkan', 'Serah Terima']
const dispStep = computed(() => {
  if (!selRx.value) return 0
  if (selRx.value.status === 'DISPENSED') return 3
  if (selRx.value.status === 'DISPENSING') return 2
  if (verifiedRxIds.value.has(selRx.value.id)) return 1
  return 0
})

/* Normalisasi resep dari backend:
   - _origQty: snapshot quantity awal untuk diff sebelum kirim ke backend
   - checked : pertahankan centang lama bila item sama di-load ulang (mis. setelah
     startDispensing/selesaiDispensing), supaya centang tidak ter-reset di tengah alur. */
function hydrateRx(rx, prev = null) {
  if (!rx) return rx
  const prevChecked = new Map((prev?.items ?? []).map((d) => [d.id, !!d.checked]))
  rx.items = (rx.items ?? []).map((d) => ({
    ...d,
    _origQty: Number(d.quantity ?? 0),
    checked:  prevChecked.get(d.id) ?? false,
  }))
  return rx
}

async function pickRx(q) {
  selQ.value  = q
  selRx.value = null
  resetAddObat()

  const stub = pickActiveRx(q.visit?.prescriptions)
  // Tanpa resep dokter → panel akan menawarkan "Buat Penjualan OTC" (obat tambahan).
  if (!stub) return

  selRxLoading.value = true
  try {
    const { data } = await farmasiApi.showResep(stub.id)
    selRx.value = hydrateRx(data.data)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat resep')
  } finally {
    selRxLoading.value = false
  }
}

// ─── Tambah obat di luar resep dokter (TAMBAHAN apotek / OTC) ────────────────
const addObatOpen   = ref(false)
const addObatSaving = ref(false)
const addObatForm   = ref({ medication_id: '', quantity: 1, dosage: '', instructions: '' })

// Hanya obat bebas/bebas terbatas/suplemen/jamu yang boleh jadi tambahan apotek.
// Master `golongan` tidak seragam → cek via kata kunci (mirror guard backend).
function isObatOtc(g) {
  const s = String(g ?? '').toUpperCase().trim()
  if (!s) return false
  if (s.includes('KERAS') || s.includes('NARKOTIKA') || s.includes('PSIKOTROPIKA')) return false
  return s.includes('BEBAS') || s.includes('SUPLEMEN') || s.includes('JAMU')
}
const otcMedications = computed(() => stokList.value.filter((m) => isObatOtc(m.golongan)))

// Picker obat tambahan (OTC) — typeahead pencarian (nama/generik/kode), ganti
// <select> bawaan yang sulit dicari saat daftar obat bebas panjang.
const addObatSearch     = ref('')
const addObatPickerOpen = ref(false)
const addObatResults = computed(() => {
  const s = addObatSearch.value.toLowerCase().trim()
  const list = otcMedications.value
  if (!s) return list.slice(0, 30)
  return list.filter((m) =>
    (m.name ?? '').toLowerCase().includes(s)
    || (m.generic_name ?? '').toLowerCase().includes(s)
    || (m.code ?? '').toLowerCase().includes(s),
  ).slice(0, 30)
})
const addObatSelected = computed(
  () => otcMedications.value.find((m) => m.id === addObatForm.value.medication_id) ?? null,
)
function pickAddObat(m) {
  addObatForm.value.medication_id = m.id
  addObatSearch.value = ''
  addObatPickerOpen.value = false
}
function clearAddObatPick() {
  addObatForm.value.medication_id = ''
  addObatSearch.value = ''
}

// ─── Preview harga obat tambahan ────────────────────────────────────────────
// Harga = yang DITAGIH KASIR (medication_tariffs per-penjamin), bukan HJA POS —
// di-resolve backend lewat /farmasi/harga-obat sesuai penjamin visit terpilih.
const hargaPreview = ref(null)   // { unit_price, billed_via, guarantor_type } | null
const hargaLoading = ref(false)
let _hargaSeq = 0   // anti-race: hanya pakai respons request terakhir

async function fetchHargaPreview() {
  const medId = addObatForm.value.medication_id
  if (!medId) { hargaPreview.value = null; return }
  const seq = ++_hargaSeq
  hargaLoading.value = true
  try {
    const { data } = await farmasiApi.hargaObat({
      medication_id: medId,
      visit_id:      selQ.value?.visit?.id ?? undefined,
    })
    if (seq !== _hargaSeq) return   // sudah ada request lebih baru
    hargaPreview.value = data.data
  } catch {
    if (seq === _hargaSeq) hargaPreview.value = null
  } finally {
    if (seq === _hargaSeq) hargaLoading.value = false
  }
}

// Subtotal preview = harga satuan × jumlah.
const hargaSubtotal = computed(() => {
  const unit = Number(hargaPreview.value?.unit_price ?? 0)
  const qty  = Number(addObatForm.value.quantity ?? 0)
  return unit > 0 && qty > 0 ? unit * qty : 0
})
// RANAP/IGD: obat ditagih lewat tagihan rawat inap, bukan invoice resep biasa.
const hargaInpatient = computed(() => ['RANAP', 'IGD'].includes(hargaPreview.value?.billed_via))

// Ambil harga tiap kali obat berganti (qty cukup dihitung lokal di subtotal).
watch(() => addObatForm.value.medication_id, fetchHargaPreview)

function resetAddObat() {
  addObatOpen.value   = false
  addObatSaving.value = false
  addObatForm.value   = { medication_id: '', quantity: 1, dosage: '', instructions: '' }
  addObatSearch.value = ''
  addObatPickerOpen.value = false
  hargaPreview.value  = null
  _hargaSeq++   // batalkan request preview yang masih in-flight
}

function toggleAddObat() {
  addObatOpen.value = !addObatOpen.value
  addObatSearch.value = ''
  addObatPickerOpen.value = false
  if (addObatOpen.value && !stokList.value.length) fetchStok()
}

function buildAddObatItem() {
  const f = addObatForm.value
  if (!f.medication_id) { toast('w', 'Pilih obat tambahan dulu'); return null }
  if (!Number.isFinite(Number(f.quantity)) || Number(f.quantity) < 1) {
    toast('w', 'Jumlah obat minimal 1'); return null
  }
  return {
    medication_id: f.medication_id,
    quantity:      Number(f.quantity),
    dosage:        f.dosage || null,
    instructions:  f.instructions || null,
    source:        'TAMBAHAN',
  }
}

// Tambah ke resep yang sedang dispensing (pasien sudah punya resep dokter).
async function submitAddObat() {
  const item = buildAddObatItem()
  if (!item) return
  addObatSaving.value = true
  try {
    if (selRx.value?.id) {
      await farmasiApi.storeItem(selRx.value.id, [item])
      const { data } = await farmasiApi.showResep(selRx.value.id)
      selRx.value = hydrateRx(data.data, selRx.value)
    } else {
      // Belum ada resep → buat penjualan OTC baru untuk visit ini.
      const { data } = await farmasiApi.storeOtc(selQ.value?.visit?.id, [item])
      selRx.value = hydrateRx(data.data)
      refreshQueueForRx(data.data)
    }
    toast('s', 'Obat tambahan ditambahkan')
    resetAddObat()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menambah obat')
  } finally {
    addObatSaving.value = false
  }
}

async function callRx(q, e) {
  e.stopPropagation()
  try {
    const { data } = await farmasiApi.panggilAntrian(q.id)
    Object.assign(q, data.data)
    toast('i', `Memanggil ${q.visit?.patient?.name ?? ''} (${q.queue_number ?? ''})`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memanggil pasien')
  }
}

// Lewati pasien yang tidak hadir → digeser ke belakang (tukar urutan dgn pasien
// berikutnya). Backend QueueService::lewati menangani penukaran + broadcast TV.
async function lewatiRx(q, e) {
  e.stopPropagation()
  try {
    await farmasiApi.lewatiAntrian(q.id)
    toast('i', `${q.visit?.patient?.name ?? 'Pasien'} (${q.queue_number ?? ''}) dilewati`)
    // Bila pasien yang dilewati sedang terbuka di panel, tutup supaya tak rancu.
    if (selQ.value?.id === q.id) { selQ.value = null; selRx.value = null }
    await fetchQueue()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal melewati pasien')
  }
}

function verifikasiRx() {
  if (!selRx.value) return
  verifiedRxIds.value = new Set([...verifiedRxIds.value, selRx.value.id])
  toast('s', 'Resep diverifikasi')
}

async function siapkanRx() {
  if (!selRx.value) return
  try {
    const { data } = await farmasiApi.startDispensing(selRx.value.id)
    selRx.value = hydrateRx(data.data, selRx.value)
    refreshQueueForRx(data.data)
    toast('s', 'Obat disiapkan, cek kembali sebelum diserahkan')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal mulai dispensing')
  }
}

const serahkanLoading = ref(false)
async function serahkanRx() {
  if (!selRx.value || serahkanLoading.value) return
  const items = selRx.value.items ?? []
  if (!items.length) { toast('w', 'Resep tidak punya item obat'); return }
  if (!items.every((d) => d.checked)) {
    toast('w', 'Cek semua item terlebih dahulu'); return
  }
  // Validasi quantity terkoreksi (>=1) sebelum kirim.
  const invalid = items.find((d) => !Number.isFinite(Number(d.quantity)) || Number(d.quantity) < 1)
  if (invalid) {
    toast('w', `Jumlah obat ${invalid.medication?.name ?? ''} tidak valid (min. 1)`); return
  }
  serahkanLoading.value = true
  try {
    // 1) Persist quantity yang diubah petugas (diff dari _origQty) → backend,
    //    supaya stok inventori Farmasi dipotong sesuai jumlah final saat serah.
    const changed = items.filter((d) => d.id && Number(d.quantity) !== Number(d._origQty))
    for (const d of changed) {
      await farmasiApi.updateItem(d.id, { quantity: Number(d.quantity) })
    }

    // 2) DISPENSING → DISPENSED: backend consume() kurangi stok inventory_stocks
    //    lokasi FARMASI (FEFO per-batch) sesuai quantity tiap item.
    const { data } = await farmasiApi.selesaiDispensing(selRx.value.id)
    selRx.value = hydrateRx(data.data, selRx.value)
    refreshQueueForRx(data.data)

    // 3) Selesaikan antrean farmasi → pasien PULANG.
    //    Resep SUDAH DISPENSED (stok terpotong); kalau langkah ini gagal, pasien
    //    masih nyangkut di antrean → JANGAN telan diam, beri tahu petugas.
    if (selQ.value?.id) {
      try {
        const { data: qData } = await farmasiApi.selesaiAntrian(selQ.value.id)
        const updated = qData.data?.queue ?? qData.data
        if (updated?.id) Object.assign(selQ.value, updated)
      } catch (e) {
        toast('w', e.response?.data?.message ?? 'Obat sudah diserahkan, tetapi antrean gagal ditutup. Tutup manual bila perlu.')
      }
    }
    toast('s', 'Obat diserahkan ke pasien, stok Farmasi diperbarui')
    // Refresh stok unit Farmasi supaya tampilan stok ikut turun.
    fetchStok()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyelesaikan dispensing')
  } finally {
    serahkanLoading.value = false
  }
}

function refreshQueueForRx(rx) {
  if (!rx || !selQ.value) return
  const prescriptions = selQ.value.visit?.prescriptions ?? []
  const idx = prescriptions.findIndex((p) => p.id === rx.id)
  if (idx !== -1) prescriptions[idx] = { ...prescriptions[idx], status: rx.status }
}

// ─── Dispensing Rawat Inap (permintaan obat pasien dirawat → serah ke ruangan) ─
const ranapQueue   = ref([])
const ranapLoading = ref(false)
const selRanap     = ref(null)   // permintaan terpilih (hydrate _origQty utk diff qty)
const ranapBusy    = ref(false)

const ranapSteps = ['Disiapkan', 'Serah ke Ruangan']
const ranapStep = computed(() => {
  if (!selRanap.value) return 0
  if (selRanap.value.status === 'DISPENSED') return 2
  if (selRanap.value.status === 'DISPENSING') return 1
  return 0
})
const ranapWaitingCount = computed(
  () => ranapQueue.value.filter((p) => ['SUBMITTED', 'DISPENSING'].includes(p.status)).length,
)

function ranapStatusLabel(s) {
  return s === 'DISPENSED' ? 'diserahkan' : s === 'DISPENSING' ? 'disiapkan' : 'diminta'
}
function ranapRoomLabel(p) {
  const room = p.visit?.room?.name ?? p.visit?.room?.code ?? ''
  const bed  = p.visit?.bed?.label ?? p.visit?.bed?.code ?? ''
  return [room, bed].filter(Boolean).join(' · ') || 'Rawat Inap'
}

async function fetchRanapQueue() {
  ranapLoading.value = true
  try {
    const { data } = await farmasiApi.ranapList()
    ranapQueue.value = data.data ?? []
    // Sinkron status panel terpilih bila berubah dari sisi lain (tanpa hapus edit qty).
    if (selRanap.value) {
      const fresh = ranapQueue.value.find((r) => r.id === selRanap.value.id)
      if (fresh && fresh.status !== selRanap.value.status) selRanap.value = hydrateRx({ ...fresh }, selRanap.value)
    }
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat permintaan rawat inap')
  } finally {
    ranapLoading.value = false
  }
}

function pickRanap(p) {
  selRanap.value = hydrateRx({ ...p }, selRanap.value?.id === p.id ? selRanap.value : null)
}

async function siapkanRanap() {
  if (!selRanap.value) return
  try {
    const { data } = await farmasiApi.ranapSiapkan(selRanap.value.id)
    selRanap.value = hydrateRx(data.data, selRanap.value)
    toast('s', 'Permintaan obat disiapkan, cek kembali sebelum diserahkan')
    fetchRanapQueue()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyiapkan permintaan')
  }
}

async function serahRanap() {
  if (!selRanap.value || ranapBusy.value) return
  const items = selRanap.value.items ?? []
  if (!items.length) { toast('w', 'Permintaan tidak punya item obat'); return }
  const invalid = items.find((d) => !Number.isFinite(Number(d.quantity)) || Number(d.quantity) < 1)
  if (invalid) { toast('w', `Jumlah obat ${invalid.medication?.name ?? ''} tidak valid (min. 1)`); return }
  ranapBusy.value = true
  try {
    // Persist qty teredit dulu → backend potong stok + tagih inpatient_charges
    // sesuai jumlah final yang benar-benar diserahkan ke ruangan.
    const changed = items.filter((d) => d.id && Number(d.quantity) !== Number(d._origQty))
    for (const d of changed) await farmasiApi.updateItem(d.id, { quantity: Number(d.quantity) })

    const { data } = await farmasiApi.ranapSerah(selRanap.value.id)
    selRanap.value = hydrateRx(data.data, selRanap.value)
    toast('s', 'Obat diserahkan ke ruangan, stok & tagihan rawat inap diperbarui')
    fetchRanapQueue()
    fetchStok()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyerahkan obat')
  } finally {
    ranapBusy.value = false
  }
}

async function tolakRanap() {
  if (!selRanap.value) return
  if (!confirm('Batalkan permintaan obat ini? Stok & tagihan tidak terpengaruh.')) return
  try {
    await farmasiApi.ranapTolak(selRanap.value.id)
    toast('i', 'Permintaan obat dibatalkan')
    selRanap.value = null
    fetchRanapQueue()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal membatalkan permintaan')
  }
}

// ─── Stok Obat ──────────────────────────────────────────────────────────────
const stokList     = ref([])
const stokSearch   = ref('')
const stokLoading  = ref(false)

async function fetchStok() {
  stokLoading.value = true
  try {
    const { data } = await farmasiApi.stokObat({ per_page: 'all' })
    const payload = data.data
    stokList.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat stok')
  } finally {
    stokLoading.value = false
  }
}

const stokFiltered = computed(() => {
  const s = stokSearch.value.toLowerCase()
  return s ? stokList.value.filter((x) => (x.name ?? '').toLowerCase().includes(s)) : stokList.value
})

// Paginasi tampilan stok (client-side, 100/halaman). Data lengkap tetap dimuat
// (dibutuhkan dispensing on-hand / OTC / low-stock / laporan / opname); hanya
// render tabel yang dipotong agar daftar obat besar (≤4000) tidak membebani DOM.
const STOK_PER_PAGE = 100
const stokPage = ref(1)
const stokLastPage = computed(() => Math.max(1, Math.ceil(stokFiltered.value.length / STOK_PER_PAGE)))
const stokPaged = computed(() => {
  const start = (stokPage.value - 1) * STOK_PER_PAGE
  return stokFiltered.value.slice(start, start + STOK_PER_PAGE)
})
// Reset ke halaman 1 saat pencarian berubah / data menyusut di bawah halaman aktif.
watch(stokSearch, () => { stokPage.value = 1 })
watch(stokLastPage, (lp) => { if (stokPage.value > lp) stokPage.value = lp })

// On-hand RIIL unit FARMASI per medication_id (dari getStokObat: field `stock` =
// inventory_stocks lokasi FARMASI, BUKAN kolom legacy medications.stock yang ikut
// di relasi items.medication). Dipakai panel dispensing supaya angka stok = yang
// benar-benar dipotong consume() saat serah. Lihat memory feature-farmasi-dispensing.
const farmasiOnHand = computed(() => {
  const m = new Map()
  for (const s of stokList.value) m.set(s.id, Number(s.stock ?? 0))
  return m
})
function itemStok(d) {
  const id = d.medication_id ?? d.medication?.id
  const onHand = id != null ? farmasiOnHand.value.get(id) : undefined
  // Fallback ke legacy hanya bila stok unit belum termuat (stokList kosong).
  return onHand ?? Number(d.medication?.stock ?? 0)
}
const lowStockCount = computed(
  () => stokList.value.filter((s) => Number(s.stock) <= Number(s.min_stock ?? 0)).length,
)

// ─── Request / Retur ke gudang Inventori Farmasi ─────────────────────────────
const requestOpen = ref(false)
const returOpen   = ref(false)

// Dipanggil oleh modal saat ada perubahan: tampilkan toast + refresh stok bila perlu.
function onUnitChanged({ type, message, refreshStok } = {}) {
  if (message) toast(type ?? 'i', message)
  if (refreshStok) fetchStok()
}

// ─── Notifikasi gudang (request/retur status) ───────────────────────────────
// Buffer event 'unit-notified' dari WS — tampil sebagai bell di tab Manajemen Stok.
const stokNotifs       = ref([])   // [{ id, kind, action, number, status, message, ts, read }]
const stokNotifOpen    = ref(false)
let _notifSeq = 0

function pushStokNotif(payload, action = 'updated') {
  const id = ++_notifSeq
  stokNotifs.value.unshift({
    id,
    kind:    payload?.kind    ?? 'request',
    action:  payload?.action  ?? action,
    number:  payload?.number  ?? '',
    status:  payload?.status  ?? '',
    message: payload?.message ?? 'Pembaruan dari gudang',
    ts:      Date.now(),
    read:    false,
  })
  if (stokNotifs.value.length > 50) stokNotifs.value.length = 50
}

const stokNotifUnread = computed(() => stokNotifs.value.filter((n) => !n.read).length)

function toggleStokNotif() {
  stokNotifOpen.value = !stokNotifOpen.value
  if (stokNotifOpen.value) {
    stokNotifs.value.forEach((n) => (n.read = true))
  }
}

function clearStokNotifs() {
  stokNotifs.value = []
  stokNotifOpen.value = false
}

function formatNotifTime(ts) {
  const diff = Date.now() - ts
  const mins = Math.floor(diff / 60000)
  if (mins < 1)   return 'baru saja'
  if (mins < 60)  return `${mins}m lalu`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24)   return `${hrs}j lalu`
  return new Date(ts).toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })
}

function notifActionLabel(n) {
  const k = n.kind === 'return' ? 'Retur' : 'Request'
  const a = ({
    approved: 'disetujui',
    rejected: 'ditolak',
    delivered: 'dikirim',
    received: 'diterima',
    closed:   'ditutup',
  })[n.action] ?? n.action
  return `${k} ${a}`
}

function notifBadgeCls(n) {
  if (n.action === 'rejected') return 'nb-err'
  if (n.action === 'delivered' || n.action === 'received') return 'nb-ok'
  if (n.action === 'approved') return 'nb-info'
  return 'nb-muted'
}

function closeNotifOnOutside(e) {
  if (!stokNotifOpen.value) return
  const el = e.target.closest('.stok-notif-wrap')
  if (!el) stokNotifOpen.value = false
}

// Fallback polling — banyak setup tidak punya Reverb aktif, jadi kita pull berkala
// dan diff status request/retur milik Farmasi. Snapshot Map(id → status).
const _reqStatusSnap = new Map()
const _retStatusSnap = new Map()
let   _notifInit = false   // first sync = isi snapshot tanpa toast

const REQ_ACTION_BY_STATUS = {
  APPROVED:  { action: 'approved',  msg: (n) => `Permintaan ${n} disetujui` },
  DELIVERED: { action: 'delivered', msg: (n) => `Permintaan ${n} sudah dikirim, stok ditambahkan` },
  REJECTED:  { action: 'rejected',  msg: (n) => `Permintaan ${n} ditolak` },
  CLOSED:    { action: 'closed',    msg: (n) => `Permintaan ${n} ditutup` },
}
const RET_ACTION_BY_STATUS = {
  RECEIVED: { action: 'received', msg: (n) => `Retur ${n} diterima, stok dikurangi` },
  REJECTED: { action: 'rejected', msg: (n) => `Retur ${n} ditolak` },
}

function _extractRows(res) {
  // Fleksibel: bisa jadi { data: { data: [...] } } (paginator) atau { data: [...] }
  const root = res?.data?.data
  if (Array.isArray(root)) return root
  if (Array.isArray(root?.data)) return root.data
  return []
}

async function pollNotifs() {
  try {
    const [reqRes, retRes] = await Promise.all([
      unitRequestApi.list({ station: 'FARMASI', per_page: 50 }),
      unitReturnApi.list({ station: 'FARMASI', per_page: 50 }),
    ])
    const reqRows = _extractRows(reqRes)
    const retRows = _extractRows(retRes)

    if (import.meta.env.DEV) {
      console.debug('[stok-notif] poll', { req: reqRows.length, ret: retRows.length, init: _notifInit, snapReq: _reqStatusSnap.size })
    }

    if (_notifInit) {
      for (const r of reqRows) {
        const prev = _reqStatusSnap.get(r.id)
        if (prev && prev !== r.status) {
          if (import.meta.env.DEV) console.debug('[stok-notif] req status change', r.request_number, prev, '->', r.status)
          const cfg = REQ_ACTION_BY_STATUS[r.status]
          if (cfg) {
            pushStokNotif({ kind: 'request', action: cfg.action, number: r.request_number, status: r.status, message: cfg.msg(r.request_number) })
            if (r.status === 'DELIVERED') fetchStok()
          }
        }
      }
      for (const r of retRows) {
        const prev = _retStatusSnap.get(r.id)
        if (prev && prev !== r.status) {
          if (import.meta.env.DEV) console.debug('[stok-notif] ret status change', r.return_number, prev, '->', r.status)
          const cfg = RET_ACTION_BY_STATUS[r.status]
          if (cfg) {
            pushStokNotif({ kind: 'return', action: cfg.action, number: r.return_number, status: r.status, message: cfg.msg(r.return_number) })
          }
        }
      }
    }

    _reqStatusSnap.clear()
    for (const r of reqRows) _reqStatusSnap.set(r.id, r.status)
    _retStatusSnap.clear()
    for (const r of retRows) _retStatusSnap.set(r.id, r.status)
    _notifInit = true
  } catch (e) {
    if (import.meta.env.DEV) console.warn('[stok-notif] poll error', e?.response?.status, e?.response?.data ?? e?.message)
  }
}

// ─── Edit / koreksi stok (opname manual) ─────────────────────────────────────
const editStok = ref(null)   // { id, name, stock, min_stock, batch_number, expiry_date }
const savingStok = ref(false)

function openEditStok(s) {
  editStok.value = {
    id:           s.id,
    name:         s.name,
    stock:        Number(s.stock ?? 0),
    min_stock:    Number(s.min_stock ?? 0),
    batch_number: s.batch_number ?? '',
    expiry_date:  s.expiry_date ? String(s.expiry_date).slice(0, 10) : '',
  }
}

async function saveEditStok() {
  if (!editStok.value) return
  savingStok.value = true
  try {
    await farmasiApi.updateStokObat(editStok.value.id, {
      stock:        Number(editStok.value.stock),
      min_stock:    Number(editStok.value.min_stock),
      batch_number: editStok.value.batch_number || null,
      expiry_date:  editStok.value.expiry_date || null,
    })
    toast('s', `Stok ${editStok.value.name} diperbarui`)
    editStok.value = null
    fetchStok()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memperbarui stok')
  } finally {
    savingStok.value = false
  }
}

// ─── Stok Opname (rekonsiliasi fisik vs sistem) ──────────────────────────────
const opnameRows   = ref([])
const opnameSearch = ref('')
const opnameSaving = ref(false)

function loadOpname() {
  opnameRows.value = stokList.value.map((s) => ({
    id:          s.id,
    name:        s.name,
    unit:        s.unit ?? '',
    formularium: s.formularium ?? '',
    system:      Number(s.stock ?? 0),
    fisik:       Number(s.stock ?? 0),
  }))
}

// Stok fisik valid? Field kosong/invalid TIDAK dihitung sebagai selisih — mencegah
// stok ter-nol-kan tak sengaja saat kolom dikosongkan (BUG: dulu Number('')=0 →
// dianggap fisik 0 → Simpan men-set stok ke 0). Hanya angka ≥ 0 yang dihitung.
function opnameFisik(r) {
  if (r.fisik === '' || r.fisik === null || r.fisik === undefined) return null
  const n = Number(r.fisik)
  return Number.isFinite(n) && n >= 0 ? n : null
}
function opnameDiff(r) {
  const f = opnameFisik(r)
  return f === null ? 0 : f - r.system
}

const opnameFiltered = computed(() => {
  const q = opnameSearch.value.toLowerCase()
  return q ? opnameRows.value.filter((r) => (r.name ?? '').toLowerCase().includes(q)) : opnameRows.value
})
const opnameChanged = computed(() => opnameRows.value.filter((r) => {
  const f = opnameFisik(r)
  return f !== null && f !== r.system
}))
const opnameStats = computed(() => {
  const changed = opnameChanged.value
  return {
    total:   opnameRows.value.length,
    changed: changed.length,
    plus:    changed.filter((r) => opnameDiff(r) > 0).length,
    minus:   changed.filter((r) => opnameDiff(r) < 0).length,
  }
})

// Paginasi tampilan opname (client-side, 100/halaman). Statistik & penyimpanan
// tetap dihitung atas SELURUH baris (opnameRows), bukan hanya halaman aktif.
const OPNAME_PER_PAGE = 100
const opnamePage = ref(1)
const opnameLastPage = computed(() => Math.max(1, Math.ceil(opnameFiltered.value.length / OPNAME_PER_PAGE)))
const opnamePaged = computed(() => {
  const start = (opnamePage.value - 1) * OPNAME_PER_PAGE
  return opnameFiltered.value.slice(start, start + OPNAME_PER_PAGE)
})
watch(opnameSearch, () => { opnamePage.value = 1 })
watch(opnameLastPage, (lp) => { if (opnamePage.value > lp) opnamePage.value = lp })

async function reloadOpname() {
  await fetchStok()
  loadOpname()
  toast('i', 'Data opname dimuat ulang dari sistem')
}

async function saveOpname() {
  const changed = opnameChanged.value
  if (!changed.length) { toast('i', 'Tidak ada selisih untuk disimpan'); return }
  if (!confirm(`Terapkan penyesuaian ${changed.length} item? Stok sistem akan disamakan dengan stok fisik.`)) return
  opnameSaving.value = true
  let ok = 0, fail = 0
  for (const r of changed) {
    const f = opnameFisik(r)
    if (f === null) continue
    try {
      await farmasiApi.updateStokObat(r.id, { stock: f })
      ok++
    } catch { fail++ }
  }
  opnameSaving.value = false
  toast(fail ? 'w' : 's', `Penyesuaian selesai: ${ok} berhasil${fail ? `, ${fail} gagal` : ''}`)
  await fetchStok()
  loadOpname()
}

// Saat tab opname dibuka: muat bila kosong; bila sudah ada baris TANPA selisih
// tertunda, segarkan baseline dari stok terbaru (cegah "Stok Sistem" basi setelah
// dispensing). Bila ada hitungan fisik yang belum disimpan, biarkan agar tak hilang.
function openOpname() {
  if (!opnameRows.value.length || !opnameChanged.value.length) loadOpname()
}

// Muat / segarkan data opname saat tab dibuka.
watch(() => pgTab.value, (t) => { if (t === 'opname') openOpname() })

// Export lembar kerja stok opname ke Excel (xlsx) — kolom Fisik/Selisih kosong.
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
const opnameExporting = ref(false)
async function exportOpnameExcel() {
  opnameExporting.value = true
  try {
    const res = await farmasiApi.opnameExport({ format: 'xlsx' })
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '')
    triggerDownload(res.data, `stok-opname-${today}.xlsx`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal mengekspor stok opname')
  } finally {
    opnameExporting.value = false
  }
}

// ─── Laporan Farmasi (derivasi dari stok) ────────────────────────────────────
function rp(n) { return 'Rp ' + Number(n || 0).toLocaleString('id-ID') }
// Selisih HARI KALENDER lokal (WIB). expiry_date 'YYYY-MM-DD' di-parse new Date() sbg
// UTC-midnight → kalau dibanding Date.now() WIB hasilnya off-by-one. Jadi bandingkan
// midnight-lokal vs midnight-lokal. Lihat memory feedback-timezone-wib.
function daysToExpiry(d) {
  const exp = new Date(d)
  if (Number.isNaN(exp.getTime())) return NaN
  const expLocal = new Date(exp.getUTCFullYear(), exp.getUTCMonth(), exp.getUTCDate())
  const now = new Date()
  const todayLocal = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  return Math.round((expLocal.getTime() - todayLocal.getTime()) / 86_400_000)
}

const lapNilaiStok = computed(
  () => stokList.value.reduce((sum, s) => sum + Number(s.stock || 0) * Number(s.price || 0), 0),
)
const lapLowOut = computed(
  () => stokList.value
    .filter((s) => Number(s.stock) <= Number(s.min_stock ?? 0))
    .sort((a, b) => Number(a.stock) - Number(b.stock)),
)
const lapExpiring = computed(
  () => stokList.value
    .filter((s) => s.expiry_date)
    .map((s) => ({ ...s, _days: daysToExpiry(s.expiry_date) }))
    .filter((s) => s._days <= 90)
    .sort((a, b) => a._days - b._days),
)

// ─── Riwayat pemberian obat ("obat ini diberikan ke siapa") ──────────────────
const riwayatSearch = ref('')
const riwayatList = computed(() => {
  const q = riwayatSearch.value.toLowerCase().trim()
  const l = q ? stokList.value.filter((s) => (s.name ?? '').toLowerCase().includes(q)) : stokList.value
  return l.slice(0, 100)
})
const riwayatModal   = ref(null)   // { med, rows } | null
const riwayatLoading = ref(false)
async function openRiwayat(med) {
  riwayatModal.value = { med, rows: [] }
  riwayatLoading.value = true
  try {
    const { data } = await farmasiApi.obatRiwayat(med.id, { limit: 200 })
    if (riwayatModal.value) riwayatModal.value.rows = data.data ?? []
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat riwayat pemberian')
  } finally {
    riwayatLoading.value = false
  }
}

// ─── Tab Riwayat Pemberian (global, server-side) ─────────────────────────────
// Daftar SEMUA obat yang diberikan ke pasien (resep ter-dispense + penjualan POS),
// dengan pencarian (obat/pasien/no.RM), rentang tanggal, dan paginasi 50/halaman.
const rpRows    = ref([])
const rpSearch  = ref('')
const rpFrom    = ref('')
const rpTo      = ref('')
const rpLoading = ref(false)
const rpMeta    = ref({ current_page: 1, last_page: 1, total: 0, per_page: 50 })
let _rpDebounce = null

async function fetchRiwayatPemberian(page = 1) {
  rpLoading.value = true
  try {
    const { data } = await farmasiApi.riwayatPemberian({
      search:    rpSearch.value.trim() || undefined,
      date_from: rpFrom.value || undefined,
      date_to:   rpTo.value || undefined,
      per_page:  50,
      page,
    })
    const p = data.data ?? {}
    rpRows.value = p.data ?? []
    rpMeta.value = {
      current_page: p.current_page ?? 1,
      last_page:    p.last_page ?? 1,
      total:        p.total ?? 0,
      per_page:     p.per_page ?? 50,
    }
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat riwayat pemberian')
  } finally {
    rpLoading.value = false
  }
}
function rpSearchInput() {
  clearTimeout(_rpDebounce)
  _rpDebounce = setTimeout(() => fetchRiwayatPemberian(1), 350)
}
watch([rpFrom, rpTo], () => fetchRiwayatPemberian(1))
function rpResetFilter() {
  rpSearch.value = ''
  rpFrom.value = ''
  rpTo.value = ''
  fetchRiwayatPemberian(1)
}
function fmtRpDate(ts) {
  if (!ts) return '—'
  const d = new Date(ts)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

// ─── Lifecycle / polling + WS notifikasi gudang ──────────────────────────────
let _poll = null
let _notifPoll = null
let _pusher = null
let _channel = null

function connectInventoriWs() {
  const appKey = import.meta.env.VITE_REVERB_APP_KEY
  if (!appKey) return   // tanpa Reverb: toast tetap jalan dari aksi lokal
  try {
    import('pusher-js').then(({ default: Pusher }) => {
      _pusher = new Pusher(appKey, {
        wsHost:            import.meta.env.VITE_REVERB_HOST ?? 'localhost',
        wsPort:            Number(import.meta.env.VITE_REVERB_PORT ?? 6001),
        wssPort:           Number(import.meta.env.VITE_REVERB_PORT ?? 6001),
        forceTLS:          (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats:      true,
      })
      _channel = _pusher.subscribe('inventori-farmasi-FARMASI')
      _channel.bind('unit-notified', (p) => {
        const type = p?.action === 'rejected' ? 'w' : 's'
        toast(type, p?.message ?? 'Pembaruan dari gudang Inventori Farmasi')
        pushStokNotif(p ?? {})
        fetchStok()
      })
    })
  } catch { /* abaikan — fallback diam */ }
}

// ─── Cetak Etiket Obat (Permenkes 73/2016) ──────────────────────────────────
// Satu etiket per item, ukuran 8×5 cm. Header BIRU untuk obat luar (tetes/salep
// mata, krim, suntik), PUTIH untuk obat dalam/oral (tablet, kapsul, sirup).
function escHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
}

// Heuristik: obat mata mayoritas sediaan luar (tetes/salep) → default BIRU bila ragu.
function isObatLuar(item) {
  const hay = `${item.medication?.name ?? ''} ${item.medication?.form ?? ''} ${item.dosage ?? ''}`.toLowerCase()
  const luar = ['tetes', 'eye drop', 'minidose', 'salep', 'salf', 'zalf', 'ointment', 'krim', 'cream', 'gel', 'suntik', 'inject', 'midriatil', ' ed', 'eod', 'tetes mata', 'tetes telinga']
  const oral = ['tablet', 'tab ', 'kaplet', 'kapsul', 'capsule', 'kapl', 'sirup', 'syrup', 'pulv', 'puyer', 'oral', 'po ']
  if (oral.some((k) => hay.includes(k))) return false
  if (luar.some((k) => hay.includes(k))) return true
  return true   // default obat mata = sediaan luar
}

// Apakah perlu label "Kocok dahulu" (suspensi/emulsi/sirup kering).
function needsKocok(item) {
  const hay = `${item.medication?.name ?? ''} ${item.medication?.form ?? ''}`.toLowerCase()
  return ['suspensi', 'suspension', 'emulsi', 'emulsion', 'kering'].some((k) => hay.includes(k))
}

function todayStrId() {
  return new Date().toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
function fmtDateId(d) {
  if (!d) return '—'
  const dt = new Date(d)
  return Number.isNaN(dt.getTime()) ? '—' : dt.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function printEtiket() {
  printEtiketFor(selRx.value, selQ.value?.visit?.patient ?? {})
}
// Etiket untuk permintaan obat rawat inap (pasien dari panel Dispensing Rawat Inap).
function printRanapEtiket() {
  printEtiketFor(selRanap.value, selRanap.value?.visit?.patient ?? {})
}
function printEtiketFor(rx, pt) {
  const items = (rx?.items ?? [])
  if (!items.length) { toast('w', 'Resep belum punya item obat'); return }

  const clinic = 'RS MATA PRIMA VISION'
  const cards = items.map((d) => {
    const luar  = isObatLuar(d)
    const headBg = luar ? '#1d4ed8' : '#ffffff'
    const headFg = luar ? '#ffffff' : '#000000'
    const headBorder = luar ? '#1d4ed8' : '#000000'
    const jenis  = luar ? 'OBAT LUAR' : 'OBAT DALAM'
    const kocok  = needsKocok(d) ? '<div class="kocok">★ KOCOK DAHULU SEBELUM DIPAKAI</div>' : ''
    return `
      <div class="etiket">
        <div class="head" style="background:${headBg};color:${headFg};border-bottom:1px solid ${headBorder}">
          <span class="clinic">${escHtml(clinic)}</span>
          <span class="jenis">${jenis}</span>
        </div>
        <div class="body">
          <div class="row"><span class="k">Tgl</span><span class="v">${escHtml(todayStrId())}</span></div>
          <div class="row"><span class="k">Nama</span><span class="v">${escHtml(pt.name ?? '—')}</span></div>
          <div class="row"><span class="k">No.RM / Lahir</span><span class="v">${escHtml(pt.no_rm ?? '—')} · ${escHtml(fmtDateId(pt.date_of_birth))}</span></div>
          <div class="obat">${escHtml(d.medication?.name ?? '—')} <span class="qty">(${escHtml(d.quantity ?? '-')} ${escHtml(d.medication?.unit ?? '')})</span></div>
          <div class="aturan">${escHtml(d.dosage ?? '')}${d.dosage && d.instructions ? ' — ' : ''}${escHtml(d.instructions ?? '')}</div>
          ${d.notes ? `<div class="note">${escHtml(d.notes)}</div>` : ''}
          ${kocok}
        </div>
      </div>`
  }).join('')

  const html = `
    <html><head><title>Etiket — ${escHtml(pt.name ?? '')}</title>
    <style>
      @page { size: 80mm 50mm; margin: 0; }
      * { margin:0; padding:0; box-sizing:border-box; font-family:Arial,'Helvetica Neue',sans-serif; color:#000; }
      body { width:80mm; }
      .etiket { width:80mm; height:50mm; padding:2mm 3mm; page-break-after:always; display:flex; flex-direction:column; }
      .head { display:flex; justify-content:space-between; align-items:center; padding:1mm 2mm; border-radius:2px; margin-bottom:1.5mm; }
      .clinic { font-size:8pt; font-weight:700; letter-spacing:.02em; }
      .jenis { font-size:6.5pt; font-weight:700; }
      .body { flex:1; font-size:8pt; line-height:1.35; }
      .row { display:flex; gap:3mm; }
      .row .k { width:22mm; color:#333; flex-shrink:0; }
      .row .v { font-weight:600; }
      .obat { margin-top:1mm; font-size:9.5pt; font-weight:700; border-top:1px dashed #000; padding-top:1mm; }
      .obat .qty { font-weight:400; font-size:8pt; }
      .aturan { font-size:9pt; font-weight:700; margin-top:.5mm; }
      .note { font-size:7.5pt; font-style:italic; margin-top:.5mm; }
      .kocok { font-size:7.5pt; font-weight:700; margin-top:1mm; }
    </style></head><body>${cards}</body></html>`

  const w = window.open('', '_blank', 'width=360,height=260')
  if (!w) { toast('w', 'Popup diblokir browser — izinkan popup untuk cetak etiket'); return }
  w.document.write(html)
  w.document.close()
  w.focus()
  // Cetak SEKALI saja: onload & setTimeout sama-sama bisa terpicu (race) → dialog dobel.
  let printed = false
  const doPrint = () => { if (printed) return; printed = true; try { w.print() } catch {} }
  w.onload = doPrint
  setTimeout(doPrint, 400)
  toast('i', `Etiket ${items.length} obat dikirim ke printer`)
}

// ─── POS: Penjualan Obat Bebas (walk-in tanpa resep) ────────────────────────
// Kebijakan owner: SEMUA golongan obat boleh dijual di POS (RS mata tak punya
// narkotika/psikotropika). Gate satu-satunya = HJA (harga jual apotek) terisi.
const posMedications = computed(() =>
  stokList.value.filter((m) => Number(m.hja ?? 0) > 0),
)

const posSearch  = ref('')
const posCart    = ref([])   // [{ medication_id, name, unit, hja, stock, quantity }]
const posBuyer   = ref('')
const posPhone   = ref('')
const posPay     = ref('CASH')
const posPaid    = ref(0)
const posDisc    = ref(0)    // diskon global (Rp)
const posSaving  = ref(false)

const posSearchResults = computed(() => {
  const s = posSearch.value.toLowerCase().trim()
  const list = posMedications.value
  if (!s) return list.slice(0, 20)
  return list.filter((m) =>
    (m.name ?? '').toLowerCase().includes(s)
    || (m.generic_name ?? '').toLowerCase().includes(s)
    || (m.code ?? '').toLowerCase().includes(s),
  ).slice(0, 20)
})

const posSubtotal = computed(() =>
  posCart.value.reduce((sum, it) => sum + Number(it.hja) * Number(it.quantity), 0),
)
const posTotal  = computed(() => Math.max(0, posSubtotal.value - Number(posDisc.value || 0)))
const posChange = computed(() => Math.max(0, Number(posPaid.value || 0) - posTotal.value))

function posAddItem(m) {
  const exist = posCart.value.find((it) => it.medication_id === m.id)
  if (exist) {
    if (exist.quantity < Number(m.stock ?? 0)) exist.quantity++
    else toast('w', `Stok ${m.name} tidak cukup`)
  } else {
    if (Number(m.stock ?? 0) < 1) { toast('w', `Stok ${m.name} habis`); return }
    posCart.value.push({
      medication_id: m.id, name: m.name, unit: m.unit ?? '',
      hja: Number(m.hja), stock: Number(m.stock ?? 0), quantity: 1,
    })
  }
  posSearch.value = ''
}
function posRemoveItem(i) { posCart.value.splice(i, 1) }
function posClampQty(it) {
  let q = Number(it.quantity)
  if (!Number.isFinite(q) || q < 1) q = 1
  if (q > it.stock) { q = it.stock; toast('w', `Maks stok ${it.name}: ${it.stock}`) }
  it.quantity = q
}
function resetPos() {
  posCart.value = []; posBuyer.value = ''; posPhone.value = ''
  posPay.value = 'CASH'; posPaid.value = 0; posDisc.value = 0; posSearch.value = ''
}

async function posCheckout() {
  if (!posCart.value.length) { toast('w', 'Keranjang masih kosong'); return }
  if (Number(posPaid.value || 0) < posTotal.value) { toast('w', 'Uang dibayar kurang dari total'); return }
  posSaving.value = true
  try {
    const payload = {
      buyer_name:     posBuyer.value || null,
      buyer_phone:    posPhone.value || null,
      payment_method: posPay.value,
      paid_amount:    Number(posPaid.value || 0),
      discount:       Number(posDisc.value || 0),
      items: posCart.value.map((it) => ({ medication_id: it.medication_id, quantity: it.quantity })),
    }
    const { data } = await farmasiApi.penjualanCreate(payload)
    const sale = data.data
    toast('s', `Penjualan ${sale.sale_number} berhasil`)
    printStrukPos(sale)
    resetPos()
    fetchStok()
    if (pgTab.value === 'penjualan') loadPosHistory()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memproses penjualan')
  } finally {
    posSaving.value = false
  }
}

// ─── Riwayat penjualan ───
const posHistory = ref([])
const posHistoryLoading = ref(false)
const posHistorySearch = ref('')

async function loadPosHistory() {
  posHistoryLoading.value = true
  try {
    const { data } = await farmasiApi.penjualanList({ search: posHistorySearch.value || undefined, per_page: 50 })
    const payload = data.data
    posHistory.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat riwayat')
  } finally {
    posHistoryLoading.value = false
  }
}

async function posReprint(saleId) {
  try {
    const { data } = await farmasiApi.penjualanShow(saleId)
    printStrukPos(data.data)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat struk')
  }
}

async function posCancel(sale) {
  if (sale.status === 'CANCELLED') return
  const reason = window.prompt(`Batalkan penjualan ${sale.sale_number}? Stok akan dikembalikan.\n\nAlasan (opsional):`, '')
  if (reason === null) return
  try {
    await farmasiApi.penjualanBatal(sale.id, { reason: reason || null })
    toast('s', `Penjualan ${sale.sale_number} dibatalkan`)
    fetchStok()
    loadPosHistory()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal membatalkan penjualan')
  }
}

function fmtDateTime(ts) {
  if (!ts) return '—'
  const d = new Date(ts)
  return Number.isNaN(d.getTime()) ? '—'
    : d.toLocaleString('id-ID', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' })
}

// Struk thermal 80mm — reuse pola printEtiket/escHtml.
function printStrukPos(sale) {
  if (!sale) return
  const rows = (sale.items ?? []).map((it) => `
    <tr>
      <td class="l">${escHtml(it.medication_name)}<br/><span class="sm">${it.quantity} x ${rp(it.unit_price)}</span></td>
      <td class="r">${rp(it.total_price)}</td>
    </tr>`).join('')

  const html = `
    <html><head><title>Struk ${escHtml(sale.sale_number)}</title>
    <style>
      @page { size: 80mm auto; margin: 0; }
      * { margin:0; padding:0; box-sizing:border-box; font-family:'Helvetica Neue',Arial,sans-serif; color:#000; }
      body { width:80mm; padding:4mm 4mm 6mm; font-size:9pt; }
      .h { text-align:center; font-size:11pt; font-weight:700; }
      .sub { text-align:center; font-size:7.5pt; text-transform:uppercase; letter-spacing:.05em; margin-bottom:2mm; }
      .meta { font-size:8pt; line-height:1.4; }
      .meta b { font-weight:700; }
      .sep { border-top:1px dashed #000; margin:2mm 0; }
      table { width:100%; border-collapse:collapse; }
      td { font-size:8.5pt; padding:.6mm 0; vertical-align:top; }
      td.l { text-align:left; } td.r { text-align:right; white-space:nowrap; padding-left:2mm; }
      .sm { font-size:7pt; color:#000; }
      .tot td { font-size:9pt; }
      .tot .r { font-weight:700; }
      .grand td { font-size:11pt; font-weight:700; border-top:1px solid #000; padding-top:1mm; }
      .ft { text-align:center; font-size:7.5pt; margin-top:3mm; }
    </style></head><body>
      <div class="h">RS MATA PRIMA VISION</div>
      <div class="sub">Struk Penjualan Apotek</div>
      <div class="meta">
        <div>No&nbsp;&nbsp;: <b>${escHtml(sale.sale_number)}</b></div>
        <div>Tgl&nbsp;: ${escHtml(fmtDateTime(sale.created_at))}</div>
        ${sale.buyer_name ? `<div>Pembeli: ${escHtml(sale.buyer_name)}</div>` : ''}
      </div>
      <div class="sep"></div>
      <table>${rows}</table>
      <div class="sep"></div>
      <table>
        <tr class="tot"><td class="l">Subtotal</td><td class="r">${rp(sale.subtotal)}</td></tr>
        ${Number(sale.discount) > 0 ? `<tr class="tot"><td class="l">Diskon</td><td class="r">- ${rp(sale.discount)}</td></tr>` : ''}
        <tr class="grand"><td class="l">TOTAL</td><td class="r">${rp(sale.total)}</td></tr>
        <tr class="tot"><td class="l">Bayar (${escHtml(sale.payment_method)})</td><td class="r">${rp(sale.paid_amount)}</td></tr>
        <tr class="tot"><td class="l">Kembali</td><td class="r">${rp(sale.change_amount)}</td></tr>
      </table>
      ${sale.status === 'CANCELLED' ? '<div class="sep"></div><div class="h" style="font-size:10pt">** DIBATALKAN **</div>' : ''}
      <div class="ft">Terima kasih atas kunjungan Anda<br/>Semoga lekas sembuh</div>
    </body></html>`

  const w = window.open('', '_blank', 'width=340,height=560')
  if (!w) { toast('w', 'Popup diblokir browser — izinkan popup untuk cetak struk'); return }
  w.document.write(html)
  w.document.close()
  w.focus()
  // Cetak SEKALI saja (lihat printEtiket): hindari dialog cetak dobel akibat race.
  let printed = false
  const doPrint = () => { if (printed) return; printed = true; try { w.print() } catch {} }
  w.onload = doPrint
  setTimeout(doPrint, 400)
}

// Muat riwayat saat tab penjualan dibuka pertama kali.
watch(() => pgTab.value, (t) => {
  if (t === 'penjualan') {
    if (!stokList.value.length) fetchStok()
    if (!posHistory.value.length) loadPosHistory()
  }
  if (t === 'ranap') fetchRanapQueue()
  if (t === 'riwayat' && !rpRows.value.length) fetchRiwayatPemberian(1)
})

// Antrean rawat jalan + permintaan rawat inap di-poll bersama (8s).
function pollFarmasi() {
  fetchQueue()
  if (pgTab.value === 'ranap') fetchRanapQueue()
}

onMounted(() => {
  fetchQueue()
  fetchStok()
  fetchRanapQueue()
  _poll = setInterval(pollFarmasi, 8_000)
  connectInventoriWs()
  pollNotifs()
  _notifPoll = setInterval(pollNotifs, 10_000)
  document.addEventListener('click', closeNotifOnOutside)
})
onUnmounted(() => {
  if (_poll) clearInterval(_poll)
  if (_notifPoll) clearInterval(_notifPoll)
  _channel?.unbind_all()
  _pusher?.disconnect()
  document.removeEventListener('click', closeNotifOnOutside)
})

// ─── Toast ──────────────────────────────────────────────────────────────────
const toasts = ref([])
let tid = 0
function toast(type, msg) {
  const id = ++tid
  toasts.value.push({ id, type, msg })
  setTimeout(() => (toasts.value = toasts.value.filter((t) => t.id !== id)), 3000)
}
</script>

<template>
  <div class="farmasi">
    <!-- NAV TABS -->
    <div class="nav-tabs">
      <button :class="['nt', pgTab === 'dispensing' ? 'a' : '']" @click="pgTab = 'dispensing'">
        <svg viewBox="0 0 24 24"><path d="M3 3h18v18H3zM3 9h18M9 21V9"/></svg>
        Dispensing Rawat Jalan
        <span class="ntbg alert">{{ queue.filter((q) => rxStatusOf(q) !== 'done').length }}</span>
      </button>
      <button :class="['nt', pgTab === 'ranap' ? 'a' : '']" @click="pgTab = 'ranap'">
        <svg viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/></svg>
        Dispensing Rawat Inap
        <span v-if="ranapWaitingCount" class="ntbg alert">{{ ranapWaitingCount }}</span>
      </button>
      <button :class="['nt', pgTab === 'stok' ? 'a' : '']" @click="pgTab = 'stok'">
        <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        Manajemen Stok
        <span v-if="lowStockCount" class="ntbg alert">{{ lowStockCount }} low</span>
      </button>
      <button :class="['nt', pgTab === 'opname' ? 'a' : '']" @click="pgTab = 'opname'">
        <svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
        Stok Opname
      </button>
      <button :class="['nt', pgTab === 'penjualan' ? 'a' : '']" @click="pgTab = 'penjualan'">
        <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
        Penjualan Obat Bebas
      </button>
      <button :class="['nt', pgTab === 'riwayat' ? 'a' : '']" @click="pgTab = 'riwayat'">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Riwayat Pemberian
      </button>
      <button :class="['nt', pgTab === 'laporan' ? 'a' : '']" @click="pgTab = 'laporan'">
        <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        Laporan
      </button>
    </div>

    <!-- DISPENSING -->
    <div v-if="pgTab === 'dispensing'" class="tab-pane">
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background: var(--ib)"><svg viewBox="0 0 24 24" stroke="var(--it)"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg></div>
          <div><div class="stat-val">{{ queue.length }}</div><div class="stat-lbl">Total Resep</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background: var(--wb)"><svg viewBox="0 0 24 24" stroke="var(--wt)"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
          <div><div class="stat-val" style="color: var(--wt)">{{ queue.filter((q) => rxStatusOf(q) === 'menunggu').length }}</div><div class="stat-lbl">Menunggu</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background: var(--ib)"><svg viewBox="0 0 24 24" stroke="var(--it)"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/></svg></div>
          <div><div class="stat-val" style="color: var(--it)">{{ queue.filter((q) => rxStatusOf(q) === 'disiapkan').length }}</div><div class="stat-lbl">Disiapkan</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background: var(--sb)"><svg viewBox="0 0 24 24" stroke="var(--st)"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg></div>
          <div><div class="stat-val" style="color: var(--st)">{{ queue.filter((q) => rxStatusOf(q) === 'done').length }}</div><div class="stat-lbl">Selesai</div></div>
        </div>
        <div :class="['stat-card', lowStockCount ? 'alert-card' : '']">
          <div class="stat-icon" :style="{ background: lowStockCount ? 'var(--eb)' : 'var(--gl)' }">
            <svg viewBox="0 0 24 24" :stroke="lowStockCount ? 'var(--et)' : 'var(--ga)'"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
          </div>
          <div><div class="stat-val" :style="{ color: lowStockCount ? 'var(--et)' : '' }">{{ lowStockCount }}</div><div class="stat-lbl">Stok Low/Habis</div></div>
        </div>
      </div>

      <div class="disp-grid">
        <!-- Queue -->
        <div class="rx-col">
          <div class="card">
            <div class="card-head">
              <div>
                <div class="card-head-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                  Antrean Resep
                </div>
                <div class="card-head-sub">{{ queue.length }} resep hari ini</div>
              </div>
              <span class="pill-live">LIVE</span>
            </div>

            <div class="card-body queue-scroll" role="region" aria-label="Daftar antrean resep">

              <!-- Primary filter -->
              <div class="primary-filter" role="group" aria-label="Filter utama antrean">
                <button :class="['pf-btn', rxPrimaryFilter === 'waiting' ? 'a' : '']" @click="rxPrimaryFilter = 'waiting'">
                  Belum Dipanggil
                  <span v-if="belumCount" class="pf-ct">{{ belumCount }}</span>
                </button>
                <button :class="['pf-btn', rxPrimaryFilter === 'done' ? 'a' : '']" @click="rxPrimaryFilter = 'done'">
                  Selesai
                  <span v-if="selesaiCount" class="pf-ct">{{ selesaiCount }}</span>
                </button>
              </div>

              <!-- Secondary filter -->
              <div class="ptype-tabs" role="group" aria-label="Filter jenis penjamin">
                <button :class="['ptype-tab', rxSecondaryFilter === 'semua' ? 'a' : '']" @click="rxSecondaryFilter = 'semua'">Semua</button>
                <button :class="['ptype-tab ptype-bpjs', rxSecondaryFilter === 'bpjs' ? 'a' : '']" @click="rxSecondaryFilter = 'bpjs'">BPJS</button>
                <button :class="['ptype-tab ptype-umum', rxSecondaryFilter === 'umum' ? 'a' : '']" @click="rxSecondaryFilter = 'umum'">Umum/Asuransi</button>
              </div>

              <!-- Search -->
              <div class="q-search-wrap">
                <input v-model="rxSearch" class="q-search" placeholder="Cari nama / no. antrean / RM…" />
              </div>
              <div class="rx-list">
            <div v-if="queueLoading && !queue.length" class="empty-rx">Memuat antrean…</div>
            <div v-for="q in filtRx" :key="q.id"
              :class="['rx-card', selQ && selQ.id === q.id ? 'active' : '', rxStatusOf(q) === 'done' ? 'done' : '']"
              @click="pickRx(q)">
              <div :class="['rx-bar', `bar-${rxStatusOf(q)}`]"></div>
              <div class="rx-body">
                <div class="rx-top">
                  <div class="rx-num">{{ q.queue_number }}</div>
                  <div class="rx-time">{{ formatTime(q.called_at ?? q.created_at) }}</div>
                </div>
                <div class="rx-name">{{ q.visit?.patient?.name ?? '—' }}</div>
                <div class="rx-meta">{{ q.visit?.patient?.no_rm ?? '—' }}</div>
                <div class="rx-tags">
                  <span :class="['rxt', guarantorType(q) === 'bpjs' ? 'rxt-b' : 'rxt-u']">{{ guarantorType(q) === 'bpjs' ? 'BPJS' : 'Umum' }}</span>
                  <span v-if="q.visit?.visit_type === 'RAWAT_INAP'" class="rxt rxt-ranap">Rawat Inap (Pulang)</span>
                  <span :class="['rxt', `rxt-${rxStatusOf(q)}`]">{{ rxStatusOf(q) }}</span>
                </div>
                <div class="rx-items">
                  <div class="rx-item muted">{{ (q.visit?.prescriptions?.length ?? 0) }} resep · status antrean {{ q.status }}</div>
                </div>
                <div v-if="rxStatusOf(q) !== 'done'" class="rx-actions" @click.stop>
                  <button class="rx-act-btn call" @click="callRx(q, $event)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 014.69 12a19.79 19.79 0 01-3.07-8.67A2 2 0 013.6 1.27h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.91 8.91a16 16 0 006.18 6.18l.96-.96a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                    Panggil
                  </button>
                  <button class="rx-act-btn skip" title="Lewati pasien (geser ke belakang)" @click="lewatiRx(q, $event)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 4 15 12 5 20 5 4"/><line x1="19" y1="5" x2="19" y2="19"/></svg>
                    Lewati
                  </button>
                </div>
              </div>
            </div>
            <div v-if="!queueLoading && !filtRx.length" class="empty-rx">Tidak ada resep</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Dispensing -->
        <div class="disp-col">
          <div v-if="!selQ" class="disp-empty">
            <svg viewBox="0 0 24 24"><path d="M3 3h18v18H3zM3 9h18M9 21V9"/></svg>
            <p>Pilih resep dari antrean untuk memulai dispensing</p>
          </div>
          <div v-else-if="selRxLoading" class="disp-empty">
            <p>Memuat resep…</p>
          </div>
          <div v-else-if="!selRx" class="disp-empty">
            <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
            <p>Pasien ini belum punya resep dokter.<br/>Bisa buat <b>penjualan obat tambahan</b> (obat bebas) langsung di apotek.</p>
            <button class="btn btn-primary btn-sm" @click="toggleAddObat">
              <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Buat Penjualan OTC
            </button>
            <!-- Form tambah obat (mode OTC tanpa resep) -->
            <div v-if="addObatOpen" class="otc-form" style="margin-top:.9rem; text-align:left; width:100%; max-width:420px">
              <div class="otc-form-title">Obat Tambahan (Bebas / OTC)</div>
              <div class="otc-fields">
                <div class="otc-field otc-wide">
                  <label class="otc-label">Obat (bebas/bebas terbatas)</label>
                  <div v-if="addObatSelected" class="otc-picked">
                    <span><b>{{ addObatSelected.name }}</b> <span class="muted">({{ addObatSelected.golongan }})</span></span>
                    <button type="button" class="otc-picked-x" title="Ganti obat" @click="clearAddObatPick">✕</button>
                  </div>
                  <div v-else class="otc-picker">
                    <input v-model="addObatSearch" class="fi otc-input" placeholder="Ketik nama / generik / kode obat…"
                           @focus="addObatPickerOpen = true" @blur="addObatPickerOpen = false" />
                    <div v-if="addObatPickerOpen" class="otc-picker-drop">
                      <div v-if="!addObatResults.length" class="otc-pick-empty">Tidak ada obat bebas cocok</div>
                      <div v-for="m in addObatResults" :key="m.id" class="otc-picker-item" @mousedown.prevent="pickAddObat(m)">
                        <span class="otc-pi-name">{{ m.name }}</span>
                        <span class="otc-pi-meta"><span class="kategori-pill">{{ m.golongan }}</span><span>stok {{ m.stock }}</span></span>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="otc-field otc-narrow">
                  <label class="otc-label">Jumlah</label>
                  <input v-model.number="addObatForm.quantity" type="number" min="1" class="fi otc-input" />
                </div>
                <div class="otc-field">
                  <label class="otc-label">Dosis</label>
                  <input v-model="addObatForm.dosage" class="fi otc-input" placeholder="mis. 1 tablet" />
                </div>
                <div class="otc-field">
                  <label class="otc-label">Aturan pakai</label>
                  <input v-model="addObatForm.instructions" class="fi otc-input" placeholder="mis. 3x/hari" />
                </div>
              </div>
              <!-- Preview harga (harga yang ditagih kasir, sesuai penjamin pasien) -->
              <div v-if="addObatForm.medication_id" class="otc-harga">
                <template v-if="hargaLoading">
                  <span class="otc-harga-load">Menghitung harga…</span>
                </template>
                <template v-else-if="hargaInpatient">
                  <span class="otc-harga-note">Obat rawat inap/IGD — ditagih pada tagihan rawat inap (bukan invoice resep).</span>
                </template>
                <template v-else>
                  <div class="otc-harga-row">
                    <span>Harga satuan ({{ hargaPreview?.guarantor_type ?? 'UMUM' }})</span>
                    <b>{{ rp(hargaPreview?.unit_price ?? 0) }}</b>
                  </div>
                  <div class="otc-harga-row total">
                    <span>Subtotal ({{ addObatForm.quantity || 0 }} ×)</span>
                    <b>{{ rp(hargaSubtotal) }}</b>
                  </div>
                  <div v-if="Number(hargaPreview?.unit_price ?? 0) === 0" class="otc-hint">
                    Obat ini belum punya tarif untuk penjamin pasien — akan tertagih Rp 0. Atur di Metode Bayar / Tarif Obat.
                  </div>
                </template>
              </div>
              <div class="otc-form-actions">
                <button class="btn btn-success btn-sm" :disabled="addObatSaving" @click="submitAddObat">
                  {{ addObatSaving ? 'Menyimpan…' : 'Tambahkan' }}
                </button>
                <button class="btn btn-secondary btn-sm" @click="resetAddObat">Batal</button>
              </div>
              <div v-if="!otcMedications.length" class="otc-hint">Tidak ada obat bebas di stok. Lengkapi golongan obat di master.</div>
            </div>
          </div>
          <div v-else class="disp-panel">
            <div class="disp-head">
              <div>
                <div class="disp-title">{{ selQ.visit?.patient?.name ?? '—' }} — {{ selQ.queue_number }}</div>
                <div class="disp-sub">{{ selQ.visit?.patient?.no_rm ?? '—' }} · dr. {{ selRx.prescribed_by?.name ?? '—' }} · {{ selRx.items?.length ?? 0 }} item</div>
              </div>
              <button class="btn btn-etiket btn-sm" :disabled="!(selRx.items ?? []).length" @click="printEtiket">
                <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Etiket
              </button>
            </div>

            <div class="disp-steps">
              <div v-for="(s, i) in dispSteps" :key="s" :class="['ds', dispStep > i ? 'done' : dispStep === i ? 'a' : '']">
                <div class="dsc">
                  <svg v-if="dispStep > i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                  <span v-else>{{ i + 1 }}</span>
                </div>
                <span class="ds-label">{{ s }}</span>
                <div v-if="i < dispSteps.length - 1" :class="['ds-line', dispStep > i ? 'done' : '']"></div>
              </div>
            </div>

            <div class="sec-title">Item Obat Resep</div>
            <div v-for="(d, i) in selRx.items" :key="d.id ?? i" :class="['dd', { otc: d.source === 'TAMBAHAN' }]">
              <input type="checkbox" v-model="d.checked" />
              <div class="dd-info">
                <div class="dd-name">
                  {{ d.medication?.name ?? '—' }}
                  <span v-if="d.source === 'TAMBAHAN'" class="otc-tag">TAMBAHAN APOTEK</span>
                </div>
                <div class="dd-dose">{{ d.dosage ?? '-' }} · {{ d.instructions ?? '-' }}</div>
                <div :class="['dd-stock', itemStok(d) > 10 ? 'ok' : itemStok(d) > 0 ? 'low' : 'out']">
                  Stok: {{ itemStok(d) }} {{ d.medication?.unit ?? '' }}{{ itemStok(d) === 0 ? ' — HABIS' : itemStok(d) <= 3 ? ' — LOW' : '' }}
                </div>
                <div v-if="d.notes" class="dd-dose">Catatan: {{ d.notes }}</div>
              </div>
              <div class="dd-qty-col">
                <span class="dd-qty-label">Jumlah</span>
                <input v-model.number="d.quantity" type="number" min="1" class="dd-qty" :disabled="selRx.status === 'DISPENSED'" />
                <span class="dd-unit">{{ d.medication?.unit ?? '' }}</span>
              </div>
            </div>
            <div v-if="!selRx.items?.length" class="empty-rx">Resep belum punya item obat.</div>

            <!-- Tambah obat di luar resep dokter (hanya selama belum diserahkan) -->
            <div v-if="selRx.status !== 'DISPENSED'" class="otc-section">
              <button class="btn btn-secondary btn-sm otc-toggle" @click="toggleAddObat">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                {{ addObatOpen ? 'Tutup' : 'Tambah Obat (di luar resep)' }}
              </button>
              <div v-if="addObatOpen" class="otc-form" style="margin-top:.6rem">
                <div class="otc-form-title">Obat Tambahan (Bebas / OTC)</div>
                <div class="otc-fields">
                  <div class="otc-field otc-wide">
                    <label class="otc-label">Obat (bebas/bebas terbatas)</label>
                    <div v-if="addObatSelected" class="otc-picked">
                      <span><b>{{ addObatSelected.name }}</b> <span class="muted">({{ addObatSelected.golongan }})</span></span>
                      <button type="button" class="otc-picked-x" title="Ganti obat" @click="clearAddObatPick">✕</button>
                    </div>
                    <div v-else class="otc-picker">
                      <input v-model="addObatSearch" class="fi otc-input" placeholder="Ketik nama / generik / kode obat…"
                             @focus="addObatPickerOpen = true" @blur="addObatPickerOpen = false" />
                      <div v-if="addObatPickerOpen" class="otc-picker-drop">
                        <div v-if="!addObatResults.length" class="otc-pick-empty">Tidak ada obat bebas cocok</div>
                        <div v-for="m in addObatResults" :key="m.id" class="otc-picker-item" @mousedown.prevent="pickAddObat(m)">
                          <span class="otc-pi-name">{{ m.name }}</span>
                          <span class="otc-pi-meta"><span class="kategori-pill">{{ m.golongan }}</span><span>stok {{ m.stock }}</span></span>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="otc-field otc-narrow">
                    <label class="otc-label">Jumlah</label>
                    <input v-model.number="addObatForm.quantity" type="number" min="1" class="fi otc-input" />
                  </div>
                  <div class="otc-field">
                    <label class="otc-label">Dosis</label>
                    <input v-model="addObatForm.dosage" class="fi otc-input" placeholder="mis. 1 tablet" />
                  </div>
                  <div class="otc-field">
                    <label class="otc-label">Aturan pakai</label>
                    <input v-model="addObatForm.instructions" class="fi otc-input" placeholder="mis. 3x/hari" />
                  </div>
                </div>
                <div class="otc-form-actions">
                  <button class="btn btn-success btn-sm" :disabled="addObatSaving" @click="submitAddObat">
                    {{ addObatSaving ? 'Menyimpan…' : 'Tambahkan' }}
                  </button>
                  <button class="btn btn-secondary btn-sm" @click="resetAddObat">Batal</button>
                </div>
                <div v-if="!otcMedications.length" class="otc-hint">Tidak ada obat bebas di stok. Lengkapi golongan obat di master.</div>
              </div>
            </div>

            <div v-if="selRx.pharmacy_note" class="doc-note"><b>Catatan untuk Farmasi:</b> {{ selRx.pharmacy_note }}</div>

            <div class="disp-actions">
              <button v-if="dispStep === 0" class="btn btn-info btn-lg" @click="verifikasiRx">
                <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/></svg>
                Verifikasi Resep
              </button>
              <button v-if="dispStep === 1" class="btn btn-warning btn-lg" @click="siapkanRx">
                <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/></svg>
                Siapkan Obat
              </button>
              <button v-if="dispStep === 2" class="btn btn-success btn-lg" :disabled="serahkanLoading || !(selRx.items ?? []).length || !(selRx.items ?? []).every((d) => d.checked)" @click="serahkanRx">
                <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                {{ serahkanLoading ? 'Memproses…' : 'Serahkan ke Pasien' }}
              </button>
              <span v-if="dispStep === 3" class="done-pill">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                Obat sudah diserahkan
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- DISPENSING RAWAT INAP -->
    <div v-if="pgTab === 'ranap'" class="tab-pane">
      <div class="loc-note">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Permintaan obat pasien <b>rawat inap</b> dari ruangan. Siapkan lalu <b>serah ke ruangan</b> — stok unit Farmasi dipotong &amp; biaya obat masuk tagihan rawat inap saat serah.</span>
      </div>

      <div class="disp-grid">
        <!-- Daftar permintaan -->
        <div class="rx-col">
          <div class="card">
            <div class="card-head">
              <div>
                <div class="card-head-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/></svg>
                  Permintaan Obat
                </div>
                <div class="card-head-sub">{{ ranapWaitingCount }} menunggu dilayani</div>
              </div>
              <span class="pill-live">LIVE</span>
            </div>
            <div class="card-body queue-scroll" role="region" aria-label="Daftar permintaan obat rawat inap">
              <div class="rx-list">
                <div v-if="ranapLoading && !ranapQueue.length" class="empty-rx">Memuat permintaan…</div>
                <div v-for="p in ranapQueue" :key="p.id"
                  :class="['rx-card', selRanap && selRanap.id === p.id ? 'active' : '', p.status === 'DISPENSED' ? 'done' : '']"
                  @click="pickRanap(p)">
                  <div :class="['rx-bar', p.status === 'DISPENSED' ? 'bar-done' : p.status === 'DISPENSING' ? 'bar-disiapkan' : 'bar-menunggu']"></div>
                  <div class="rx-body">
                    <div class="rx-top">
                      <div class="rx-num">{{ ranapRoomLabel(p) }}</div>
                      <div class="rx-time">{{ formatTime(p.created_at) }}</div>
                    </div>
                    <div class="rx-name">{{ p.visit?.patient?.name ?? '—' }}</div>
                    <div class="rx-meta">{{ p.visit?.patient?.no_rm ?? '—' }}</div>
                    <div class="rx-tags">
                      <span class="rxt rxt-ranap">Rawat Inap</span>
                      <span :class="['rxt', p.status === 'DISPENSED' ? 'rxt-done' : p.status === 'DISPENSING' ? 'rxt-disiapkan' : 'rxt-menunggu']">{{ ranapStatusLabel(p.status) }}</span>
                    </div>
                    <div class="rx-items">
                      <div class="rx-item muted">{{ (p.items?.length ?? 0) }} item obat</div>
                    </div>
                  </div>
                </div>
                <div v-if="!ranapLoading && !ranapQueue.length" class="empty-rx">Tidak ada permintaan obat rawat inap.</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Detail / serah -->
        <div class="disp-col">
          <div v-if="!selRanap" class="disp-empty">
            <svg viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/></svg>
            <p>Pilih permintaan obat dari daftar untuk menyiapkan</p>
          </div>
          <div v-else class="disp-panel">
            <div class="disp-head">
              <div>
                <div class="disp-title">{{ selRanap.visit?.patient?.name ?? '—' }}</div>
                <div class="disp-sub">{{ selRanap.visit?.patient?.no_rm ?? '—' }} · {{ ranapRoomLabel(selRanap) }} · {{ selRanap.items?.length ?? 0 }} item</div>
              </div>
              <button class="btn btn-etiket btn-sm" :disabled="!(selRanap.items ?? []).length" @click="printRanapEtiket">
                <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Etiket
              </button>
            </div>

            <div class="disp-steps">
              <div v-for="(s, i) in ranapSteps" :key="s" :class="['ds', ranapStep > i ? 'done' : ranapStep === i ? 'a' : '']">
                <div class="dsc">
                  <svg v-if="ranapStep > i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                  <span v-else>{{ i + 1 }}</span>
                </div>
                <span class="ds-label">{{ s }}</span>
                <div v-if="i < ranapSteps.length - 1" :class="['ds-line', ranapStep > i ? 'done' : '']"></div>
              </div>
            </div>

            <div class="sec-title">Item Obat Diminta</div>
            <div v-for="(d, i) in selRanap.items" :key="d.id ?? i" class="dd">
              <div class="dd-info">
                <div class="dd-name">{{ d.medication?.name ?? '—' }}</div>
                <div class="dd-dose">{{ d.dose ?? '-' }} · {{ d.frequency ?? '-' }}<span v-if="d.route"> · {{ d.route }}</span></div>
                <div :class="['dd-stock', itemStok(d) > 10 ? 'ok' : itemStok(d) > 0 ? 'low' : 'out']">
                  Stok Farmasi: {{ itemStok(d) }} {{ d.medication?.unit ?? '' }}{{ itemStok(d) === 0 ? ' — HABIS' : itemStok(d) <= 3 ? ' — LOW' : '' }}
                </div>
                <div v-if="d.instructions" class="dd-dose">Aturan: {{ d.instructions }}</div>
              </div>
              <div class="dd-qty-col">
                <span class="dd-qty-label">Jumlah</span>
                <input v-model.number="d.quantity" type="number" min="1" class="dd-qty" :disabled="selRanap.status === 'DISPENSED'" />
                <span class="dd-unit">{{ d.medication?.unit ?? '' }}</span>
              </div>
            </div>
            <div v-if="!selRanap.items?.length" class="empty-rx">Permintaan belum punya item obat.</div>

            <div v-if="selRanap.pharmacy_note" class="doc-note"><b>Catatan untuk Farmasi:</b> {{ selRanap.pharmacy_note }}</div>

            <div class="disp-actions">
              <button v-if="ranapStep === 0" class="btn btn-warning btn-lg" @click="siapkanRanap">
                <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/></svg>
                Siapkan Obat
              </button>
              <button v-if="ranapStep === 1" class="btn btn-success btn-lg" :disabled="ranapBusy || !(selRanap.items ?? []).length" @click="serahRanap">
                <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                {{ ranapBusy ? 'Memproses…' : 'Serah ke Ruangan' }}
              </button>
              <span v-if="ranapStep === 2" class="done-pill">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                Obat sudah diserahkan ke ruangan
              </span>
              <button v-if="ranapStep < 2" class="btn btn-secondary btn-sm" @click="tolakRanap">Batalkan</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- STOK -->
    <div v-if="pgTab === 'stok'" class="tab-pane">
      <div class="loc-note">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Stok yang ditampilkan = <b>stok unit Farmasi</b> (yang dipakai saat penyerahan obat), bukan stok gudang. Minta transfer lewat <b>Minta Barang</b> bila kurang.</span>
      </div>
      <div class="stok-head">
        <div class="stok-actions">
          <div class="stok-search">
            <svg viewBox="0 0 24 24" class="stok-search-ico"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input v-model="stokSearch" class="fi stok-search-input" placeholder="Cari obat..." />
          </div>
          <button class="btn btn-primary btn-sm" @click="requestOpen = true">
            <svg viewBox="0 0 24 24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17M17 13v4a2 2 0 01-2 2H9"/></svg>
            Minta Barang
          </button>
          <button class="btn btn-secondary btn-sm" @click="returOpen = true">
            <svg viewBox="0 0 24 24"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 00-4-4H4"/></svg>
            Retur Obat
          </button>

          <div class="stok-notif-wrap">
          <button
            class="stok-bell"
            :class="{ active: stokNotifUnread > 0 }"
            :title="`${stokNotifUnread} notifikasi baru`"
            @click.stop="toggleStokNotif"
          >
            <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <span v-if="stokNotifUnread > 0" class="stok-bell-badge">{{ stokNotifUnread > 99 ? '99+' : stokNotifUnread }}</span>
          </button>

          <div v-if="stokNotifOpen" class="stok-notif-panel" @click.stop>
            <div class="stok-notif-head">
              <strong>Notifikasi Gudang</strong>
              <div style="display: flex; gap: 8px; align-items: center;">
                <button class="stok-notif-clear" @click="pollNotifs">Refresh</button>
                <button v-if="stokNotifs.length" class="stok-notif-clear" @click="clearStokNotifs">Bersihkan</button>
              </div>
            </div>
            <div class="stok-notif-body">
              <div v-if="!stokNotifs.length" class="stok-notif-empty">Belum ada notifikasi</div>
              <div
                v-for="n in stokNotifs"
                :key="n.id"
                class="stok-notif-row"
              >
                <span class="stok-notif-badge" :class="notifBadgeCls(n)">{{ notifActionLabel(n) }}</span>
                <div class="stok-notif-main">
                  <div class="stok-notif-title">
                    <code v-if="n.number">{{ n.number }}</code>
                    <span class="stok-notif-time">{{ formatNotifTime(n.ts) }}</span>
                  </div>
                  <div class="stok-notif-msg">{{ n.message }}</div>
                </div>
              </div>
            </div>
          </div>
          </div>
        </div>
      </div>
      <div v-if="lowStockCount" class="low-alert">
        <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
        {{ lowStockCount }} item stok low/habis: {{ stokList.filter((s) => Number(s.stock) <= Number(s.min_stock ?? 0)).map((s) => s.name).join(', ') }}
      </div>
      <div class="po-table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th style="width:48px" class="c">No.</th>
              <th>Nama Produk</th>
              <th>Formularium</th>
              <th class="r">Stok</th>
              <th class="r">Min</th>
              <th>Unit</th>
              <th>Batch</th>
              <th>Exp</th>
              <th class="c">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="stokLoading && !stokList.length"><td colspan="9" class="po-state">Memuat stok…</td></tr>
            <tr v-for="(s, i) in stokPaged" :key="s.id ?? s.name">
              <td class="c muted">{{ (stokPage - 1) * STOK_PER_PAGE + i + 1 }}</td>
              <td><strong>{{ s.name }}</strong></td>
              <td><span class="kategori-pill">{{ s.formularium ?? '—' }}</span></td>
              <td class="r">
                <div class="stok-cell">
                  <span :class="{ out: Number(s.stock) === 0, low: Number(s.stock) > 0 && Number(s.stock) <= Number(s.min_stock ?? 0) }">{{ s.stock }}</span>
                  <div class="bar"><div :class="['bar-fill', Number(s.stock) === 0 ? 'out' : Number(s.stock) <= Number(s.min_stock ?? 0) ? 'low' : 'ok']" :style="{ width: Math.min((Number(s.stock) / Math.max(Number(s.min_stock ?? 0) * 5, 1)) * 100, 100) + '%' }"></div></div>
                </div>
              </td>
              <td class="r muted">{{ s.min_stock ?? '—' }}</td>
              <td class="muted">{{ s.unit ?? '—' }}</td>
              <td class="muted">{{ s.batch_number ?? '—' }}</td>
              <td class="muted">{{ s.expiry_date ? new Date(s.expiry_date).toLocaleDateString('id-ID', { month: '2-digit', year: 'numeric' }) : '—' }}</td>
              <td class="c">
                <button class="po-icon-btn" title="Koreksi stok" @click="openEditStok(s)">
                  <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                </button>
              </td>
            </tr>
            <tr v-if="!stokLoading && !stokFiltered.length"><td colspan="9" class="po-state">Tidak ada data stok</td></tr>
          </tbody>
        </table>
        <Pager v-model:page="stokPage" :last-page="stokLastPage" :total="stokFiltered.length" />
      </div>
    </div>

    <!-- STOK OPNAME -->
    <div v-if="pgTab === 'opname'" class="tab-pane">
      <div class="loc-note">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Opname terhadap <b>stok unit Farmasi</b>. Penyesuaian akan menyamakan stok sistem unit Farmasi dengan stok fisik di rak Farmasi (bukan gudang).</span>
      </div>
      <div class="opname-head">
        <div class="opname-stats">
          <div class="ostat"><span class="ostat-lbl">Item</span><b>{{ opnameStats.total }}</b></div>
          <div class="ostat"><span class="ostat-lbl">Ada Selisih</span><b :class="{ warn: opnameStats.changed }">{{ opnameStats.changed }}</b></div>
          <div class="ostat"><span class="ostat-lbl">Lebih</span><b class="plus">{{ opnameStats.plus }}</b></div>
          <div class="ostat"><span class="ostat-lbl">Kurang</span><b class="minus">{{ opnameStats.minus }}</b></div>
        </div>
        <div class="opname-actions">
          <input v-model="opnameSearch" class="fi" placeholder="Cari obat..." style="width: 200px" />
          <button class="btn btn-secondary btn-sm" @click="reloadOpname">
            <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
            Muat Ulang
          </button>
          <button class="btn btn-secondary btn-sm" :disabled="opnameExporting" @click="exportOpnameExcel">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            {{ opnameExporting ? 'Mengekspor…' : 'Export Excel' }}
          </button>
          <button class="btn btn-primary btn-sm" :disabled="opnameSaving || !opnameStats.changed" @click="saveOpname">
            <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            {{ opnameSaving ? 'Menyimpan…' : 'Simpan Penyesuaian' }}
          </button>
        </div>
      </div>

      <div class="po-table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th style="width:48px" class="c">No.</th>
              <th>Nama Produk</th>
              <th>Formularium</th>
              <th>Unit</th>
              <th class="r">Stok Sistem</th>
              <th class="r" style="width:120px">Stok Fisik</th>
              <th class="r">Selisih</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!opnameRows.length"><td colspan="7" class="po-state">Belum ada data. Klik "Muat Ulang" untuk memuat stok sistem.</td></tr>
            <tr v-for="(r, i) in opnamePaged" :key="r.id"
              :class="{ 'op-diff': opnameDiff(r) !== 0 }">
              <td class="c muted">{{ (opnamePage - 1) * OPNAME_PER_PAGE + i + 1 }}</td>
              <td><strong>{{ r.name }}</strong></td>
              <td><span class="kategori-pill">{{ r.formularium || '—' }}</span></td>
              <td class="muted">{{ r.unit || '—' }}</td>
              <td class="r">{{ r.system }}</td>
              <td class="r"><input v-model="r.fisik" type="number" min="0" class="op-input" /></td>
              <td class="r">
                <span :class="['op-sel', opnameDiff(r) > 0 ? 'plus' : opnameDiff(r) < 0 ? 'minus' : '']">
                  {{ opnameDiff(r) > 0 ? '+' : '' }}{{ opnameDiff(r) }}
                </span>
              </td>
            </tr>
            <tr v-if="opnameRows.length && !opnameFiltered.length"><td colspan="7" class="po-state">Tidak ada obat cocok pencarian</td></tr>
          </tbody>
        </table>
        <Pager v-model:page="opnamePage" :last-page="opnameLastPage" :total="opnameFiltered.length" />
      </div>
    </div>
    <!-- PENJUALAN OBAT BEBAS (POS) -->
    <div v-if="pgTab === 'penjualan'" class="tab-pane">
      <div class="loc-note">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Penjualan <b>obat bebas</b> untuk pembeli walk-in (tanpa resep dokter). Hanya obat golongan bebas/bebas terbatas/suplemen/jamu yang punya <b>harga jual (HJA)</b>. Stok dipotong dari unit Farmasi.</span>
      </div>

      <div class="pos-grid">
        <!-- Pencarian + keranjang -->
        <div class="card pos-cart-col">
          <div class="card-head">
            <div class="card-head-title">
              <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
              Kasir Apotek
            </div>
          </div>
          <div class="pos-body">
            <div class="q-search-wrap" style="position:relative">
              <input v-model="posSearch" class="q-search" placeholder="Cari obat bebas untuk dijual…" />
              <div v-if="posSearch.trim()" class="pos-search-drop">
                <div v-if="!posSearchResults.length" class="empty-rx">Tidak ada obat bebas cocok</div>
                <div v-for="m in posSearchResults" :key="m.id" class="pos-search-item" @click="posAddItem(m)">
                  <div class="pos-si-name">{{ m.name }}</div>
                  <div class="pos-si-meta">
                    <span class="kategori-pill">{{ m.golongan }}</span>
                    <span>{{ rp(m.hja) }}</span>
                    <span :class="Number(m.stock) > 0 ? '' : 'pos-out'">stok {{ m.stock }}</span>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="!posCart.length" class="empty-rx">Keranjang kosong — cari & klik obat untuk menambah.</div>
            <table v-else class="pos-cart-table">
              <thead>
                <tr><th>Obat</th><th class="r">Harga</th><th class="c">Qty</th><th class="r">Subtotal</th><th></th></tr>
              </thead>
              <tbody>
                <tr v-for="(it, i) in posCart" :key="it.medication_id">
                  <td><strong>{{ it.name }}</strong><div class="sm muted">{{ it.unit }}</div></td>
                  <td class="r">{{ rp(it.hja) }}</td>
                  <td class="c">
                    <input v-model.number="it.quantity" type="number" min="1" :max="it.stock" class="op-input pos-qty" @change="posClampQty(it)" />
                  </td>
                  <td class="r"><strong>{{ rp(it.hja * it.quantity) }}</strong></td>
                  <td class="c"><button class="po-icon-btn" title="Hapus" @click="posRemoveItem(i)">
                    <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Ringkasan + bayar -->
        <div class="card pos-pay-col">
          <div class="card-head"><div class="card-head-title">Pembayaran</div></div>
          <div class="pos-body">
            <div class="pos-field">
              <label>Nama Pembeli (opsional)</label>
              <input v-model="posBuyer" class="fi pos-input" placeholder="mis. Umum" />
            </div>
            <div class="pos-field">
              <label>No. Telp (opsional)</label>
              <input v-model="posPhone" class="fi pos-input" placeholder="08…" />
            </div>

            <div class="pos-summary">
              <div class="pos-row"><span>Subtotal</span><b>{{ rp(posSubtotal) }}</b></div>
              <div class="pos-row">
                <span>Diskon (Rp)</span>
                <input v-model.number="posDisc" type="number" min="0" class="op-input" style="width:110px" />
              </div>
              <div class="pos-row pos-grand"><span>Total</span><b>{{ rp(posTotal) }}</b></div>
            </div>

            <div class="pos-field">
              <label>Metode Bayar</label>
              <select v-model="posPay" class="fi pos-input">
                <option value="CASH">Tunai</option>
                <option value="CARD">Kartu (Debit/Kredit)</option>
                <option value="TRANSFER">Transfer</option>
              </select>
            </div>
            <div class="pos-field">
              <label>Uang Dibayar</label>
              <input v-model.number="posPaid" type="number" min="0" class="fi pos-input" />
            </div>
            <div class="pos-row pos-change"><span>Kembalian</span><b>{{ rp(posChange) }}</b></div>

            <button class="btn btn-success btn-lg" style="width:100%; justify-content:center; margin-top:.8rem"
              :disabled="posSaving || !posCart.length || posPaid < posTotal" @click="posCheckout">
              <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              {{ posSaving ? 'Memproses…' : 'Bayar & Cetak Struk' }}
            </button>
            <button v-if="posCart.length" class="btn btn-secondary btn-sm" style="width:100%; justify-content:center; margin-top:.4rem" @click="resetPos">Kosongkan</button>
          </div>
        </div>
      </div>

      <!-- Riwayat penjualan -->
      <div class="pos-history">
        <div class="opname-head">
          <div class="lap-section" style="margin:0">Riwayat Penjualan Hari Ini</div>
          <div class="opname-actions">
            <input v-model="posHistorySearch" class="fi" placeholder="Cari no/pembeli…" style="width:180px" @keyup.enter="loadPosHistory" />
            <button class="btn btn-secondary btn-sm" @click="loadPosHistory">
              <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
              Muat Ulang
            </button>
          </div>
        </div>
        <div class="po-table-wrap">
          <table class="po-table">
            <thead>
              <tr>
                <th>No. Transaksi</th><th>Pembeli</th><th class="r">Total</th>
                <th>Bayar</th><th>Waktu</th><th class="c">Status</th><th class="c">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="posHistoryLoading && !posHistory.length"><td colspan="7" class="po-state">Memuat…</td></tr>
              <tr v-for="s in posHistory" :key="s.id" :class="{ 'pos-cancelled': s.status === 'CANCELLED' }">
                <td><strong>{{ s.sale_number }}</strong></td>
                <td>{{ s.buyer_name || '—' }}</td>
                <td class="r"><strong>{{ rp(s.total) }}</strong></td>
                <td class="muted">{{ s.payment_method }}</td>
                <td class="muted">{{ fmtDateTime(s.created_at) }}</td>
                <td class="c">
                  <span class="lap-badge" :class="s.status === 'CANCELLED' ? 'b-out' : 'b-low'" style="background:var(--sb);color:var(--st)" v-if="s.status !== 'CANCELLED'">PAID</span>
                  <span class="lap-badge b-out" v-else>BATAL</span>
                </td>
                <td class="c">
                  <button class="po-icon-btn" title="Cetak ulang struk" @click="posReprint(s.id)">
                    <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                  </button>
                  <button v-if="s.status !== 'CANCELLED'" class="po-icon-btn" title="Batalkan" @click="posCancel(s)">
                    <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </td>
              </tr>
              <tr v-if="!posHistoryLoading && !posHistory.length"><td colspan="7" class="po-state">Belum ada penjualan</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- RIWAYAT PEMBERIAN OBAT (global: resep + POS) -->
    <div v-if="pgTab === 'riwayat'" class="tab-pane">
      <div class="loc-note">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Riwayat <b>obat yang diberikan ke pasien</b> — dari resep yang sudah diserahkan (rawat jalan/inap) dan penjualan obat bebas (POS). Saring dengan pencarian &amp; rentang tanggal.</span>
      </div>

      <div class="rp-head">
        <div class="rp-search">
          <svg viewBox="0 0 24 24" class="stok-search-ico"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input v-model="rpSearch" class="fi stok-search-input" placeholder="Cari obat / pasien / no. RM…" @input="rpSearchInput" />
        </div>
        <div class="rp-dates">
          <label class="rp-date-lbl">Dari
            <input v-model="rpFrom" type="date" class="fi" />
          </label>
          <label class="rp-date-lbl">s/d
            <input v-model="rpTo" type="date" class="fi" />
          </label>
          <button class="btn btn-secondary btn-sm" @click="rpResetFilter">Reset</button>
          <button class="btn btn-secondary btn-sm" :disabled="rpLoading" @click="fetchRiwayatPemberian(rpMeta.current_page)">
            <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
            Muat Ulang
          </button>
        </div>
      </div>

      <div class="po-table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th style="width:48px" class="c">No.</th>
              <th style="width:160px">Tanggal</th>
              <th>Pasien</th>
              <th>No. RM</th>
              <th>Obat</th>
              <th class="r">Jumlah</th>
              <th>Sumber</th>
              <th>Petugas</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="rpLoading"><td colspan="8" class="po-state">Memuat riwayat…</td></tr>
            <tr v-else-if="!rpRows.length"><td colspan="8" class="po-state">Tidak ada riwayat pemberian pada filter ini.</td></tr>
            <tr v-for="(r, i) in rpRows" :key="r.id">
              <td class="c muted">{{ (rpMeta.current_page - 1) * rpMeta.per_page + i + 1 }}</td>
              <td class="muted">{{ fmtRpDate(r.tanggal) }}</td>
              <td><strong>{{ r.pasien }}</strong></td>
              <td class="muted">{{ r.no_rm || '—' }}</td>
              <td>{{ r.obat }}</td>
              <td class="r">{{ Number(r.quantity) }}</td>
              <td><span class="kategori-pill">{{ r.sumber }}</span></td>
              <td class="muted">{{ r.petugas || '—' }}</td>
            </tr>
          </tbody>
        </table>
        <Pager
          :page="rpMeta.current_page"
          :last-page="rpMeta.last_page"
          :total="rpMeta.total"
          @change="fetchRiwayatPemberian"
        />
      </div>
    </div>

    <!-- LAPORAN -->
    <div v-if="pgTab === 'laporan'" class="tab-pane">
      <div class="lap-grid">
        <div class="lap-card">
          <div class="lap-lbl">Total Item Obat</div>
          <div class="lap-val">{{ stokList.length }}</div>
        </div>
        <div class="lap-card">
          <div class="lap-lbl">Nilai Stok</div>
          <div class="lap-val">{{ rp(lapNilaiStok) }}</div>
        </div>
        <div class="lap-card">
          <div class="lap-lbl">Stok Rendah / Habis</div>
          <div class="lap-val warn">{{ lapLowOut.length }}</div>
        </div>
        <div class="lap-card">
          <div class="lap-lbl">Mendekati / Lewat Exp</div>
          <div class="lap-val err">{{ lapExpiring.length }}</div>
        </div>
      </div>

      <!-- Stok rendah & habis -->
      <div>
        <div class="lap-section">Stok Rendah &amp; Habis</div>
        <div class="po-table-wrap">
          <table class="po-table">
            <thead>
              <tr>
                <th style="width:48px" class="c">No.</th>
                <th>Nama Produk</th>
                <th>Formularium</th>
                <th class="r">Stok</th>
                <th class="r">Min</th>
                <th>Unit</th>
                <th class="c">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!lapLowOut.length"><td colspan="7" class="po-state">Semua stok aman 👍</td></tr>
              <tr v-for="(s, i) in lapLowOut" :key="s.id ?? s.name">
                <td class="c muted">{{ i + 1 }}</td>
                <td><strong>{{ s.name }}</strong></td>
                <td><span class="kategori-pill">{{ s.formularium || '—' }}</span></td>
                <td class="r">{{ s.stock }}</td>
                <td class="r muted">{{ s.min_stock ?? '—' }}</td>
                <td class="muted">{{ s.unit || '—' }}</td>
                <td class="c">
                  <span class="lap-badge" :class="Number(s.stock) === 0 ? 'b-out' : 'b-low'">{{ Number(s.stock) === 0 ? 'HABIS' : 'LOW' }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mendekati / lewat kadaluarsa -->
      <div>
        <div class="lap-section">Mendekati / Lewat Kadaluarsa (≤ 90 hari)</div>
        <div class="po-table-wrap">
          <table class="po-table">
            <thead>
              <tr>
                <th style="width:48px" class="c">No.</th>
                <th>Nama Produk</th>
                <th class="r">Stok</th>
                <th>Batch</th>
                <th>Kadaluarsa</th>
                <th class="r">Sisa</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!lapExpiring.length"><td colspan="6" class="po-state">Tidak ada item mendekati kadaluarsa</td></tr>
              <tr v-for="(s, i) in lapExpiring" :key="s.id ?? s.name">
                <td class="c muted">{{ i + 1 }}</td>
                <td><strong>{{ s.name }}</strong></td>
                <td class="r">{{ s.stock }}</td>
                <td class="muted">{{ s.batch_number || '—' }}</td>
                <td class="muted">{{ new Date(s.expiry_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) }}</td>
                <td class="r">
                  <span :class="['lap-days', s._days < 0 ? 'err' : s._days <= 30 ? 'warn' : '']">
                    {{ s._days < 0 ? `lewat ${-s._days} hr` : `${s._days} hr` }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Riwayat pemberian obat: "obat ini diberikan ke siapa" -->
      <div>
        <div class="opname-head">
          <div class="lap-section" style="margin:0">Riwayat Pemberian Obat</div>
          <div class="opname-actions">
            <input v-model="riwayatSearch" class="fi" placeholder="Cari obat untuk lihat riwayat…" style="width:240px" />
          </div>
        </div>
        <div class="po-table-wrap">
          <table class="po-table">
            <thead>
              <tr>
                <th style="width:48px" class="c">No.</th>
                <th>Nama Produk</th>
                <th>Formularium</th>
                <th class="r">Stok</th>
                <th class="c" style="width:120px">Diberikan ke</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!riwayatList.length"><td colspan="5" class="po-state">Tidak ada obat cocok pencarian</td></tr>
              <tr v-for="(s, i) in riwayatList" :key="s.id ?? s.name">
                <td class="c muted">{{ i + 1 }}</td>
                <td><strong>{{ s.name }}</strong></td>
                <td><span class="kategori-pill">{{ s.formularium || '—' }}</span></td>
                <td class="r">{{ s.stock }}</td>
                <td class="c">
                  <button class="btn btn-secondary btn-sm" @click="openRiwayat(s)">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Lihat
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modal: riwayat pemberian satu obat -->
    <div v-if="riwayatModal" class="es-overlay" @click.self="riwayatModal = null">
      <div class="es-modal" style="max-width:640px">
        <div class="es-head">
          <h3>Riwayat Pemberian — {{ riwayatModal.med?.name }}</h3>
          <button class="es-x" @click="riwayatModal = null" aria-label="Tutup">
            <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
        <div class="es-body">
          <div v-if="riwayatLoading" class="po-state">Memuat riwayat…</div>
          <div v-else-if="!riwayatModal.rows.length" class="po-state">Belum ada riwayat pemberian obat ini.</div>
          <div v-else class="po-table-wrap" style="max-height:60vh; overflow:auto">
            <table class="po-table">
              <thead>
                <tr>
                  <th>Tanggal</th>
                  <th>Pasien / Pembeli</th>
                  <th>No. RM</th>
                  <th class="r">Jumlah</th>
                  <th>Sumber</th>
                  <th>Petugas</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(r, i) in riwayatModal.rows" :key="i">
                  <td class="muted">{{ fmtDateTime(r.tanggal) }}</td>
                  <td><strong>{{ r.pasien }}</strong></td>
                  <td class="muted">{{ r.no_rm || '—' }}</td>
                  <td class="r">{{ r.quantity }}</td>
                  <td><span class="kategori-pill">{{ r.sumber }}</span></td>
                  <td class="muted">{{ r.petugas || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="es-foot">
          <button class="btn btn-secondary btn-sm" @click="riwayatModal = null">Tutup</button>
        </div>
      </div>
    </div>

    <!-- Modal: Minta Barang / Retur ke gudang -->
    <RequestObatModal :open="requestOpen" :medications="stokList" @close="requestOpen = false" @changed="onUnitChanged" />
    <ReturObatModal   :open="returOpen"   :medications="stokList" @close="returOpen = false"   @changed="onUnitChanged" />

    <!-- Modal: koreksi stok manual -->
    <div v-if="editStok" class="es-overlay" @click.self="editStok = null">
      <div class="es-modal">
        <div class="es-head">
          <h3>Koreksi Stok</h3>
          <button class="es-x" @click="editStok = null" aria-label="Tutup">
            <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
        <div class="es-body">
          <div class="es-name">{{ editStok.name }}</div>
          <div class="es-grid">
            <div class="es-field">
              <label>Stok</label>
              <input v-model.number="editStok.stock" type="number" min="0" class="es-input" />
            </div>
            <div class="es-field">
              <label>Min. Stok</label>
              <input v-model.number="editStok.min_stock" type="number" min="0" class="es-input" />
            </div>
            <div class="es-field">
              <label>Batch</label>
              <input v-model="editStok.batch_number" class="es-input" placeholder="—" />
            </div>
            <div class="es-field">
              <label>Kadaluarsa</label>
              <input v-model="editStok.expiry_date" type="date" class="es-input" />
            </div>
          </div>
          <p class="es-hint">Koreksi manual (opname). Penambahan stok rutin sebaiknya lewat "Minta Barang".</p>
        </div>
        <div class="es-foot">
          <button class="btn btn-secondary btn-sm" @click="editStok = null">Batal</button>
          <button class="btn btn-primary btn-sm" :disabled="savingStok" @click="saveEditStok">
            {{ savingStok ? 'Menyimpan…' : 'Simpan' }}
          </button>
        </div>
      </div>
    </div>

    <div class="toast-wrap">
      <div v-for="t in toasts" :key="t.id" :class="['toast', `toast-${t.type}`]">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
.farmasi { display: flex; flex-direction: column; gap: 1rem; }
.tab-pane { display: flex; flex-direction: column; gap: 1rem; }

.nav-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); padding: 0 4px; }
.nt { padding: 0.6rem 1rem; font-size: 12px; font-weight: 500; color: var(--tu); background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: 'Inter', sans-serif; display: inline-flex; align-items: center; gap: 6px; }
.nt:hover { color: var(--td); }
.nt.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }
.nt svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.ntbg { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 20px; background: var(--gb); color: var(--tu); }
.ntbg.alert { background: var(--eb); color: var(--et); }

.stat-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.6rem; }
.stat-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 11px; padding: 0.75rem; display: flex; align-items: center; gap: 9px; }
.stat-card.alert-card { border-color: var(--ebd); }
.stat-icon { width: 32px; height: 32px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-icon svg { width: 16px; height: 16px; fill: none; stroke-width: 2; stroke-linecap: round; }
.stat-val { font-size: 18px; font-weight: 700; color: var(--td); line-height: 1; }
.stat-lbl { font-size: 10px; color: var(--tu); margin-top: 2px; }

.disp-grid { display: grid; grid-template-columns: 300px 1fr; gap: 0.75rem; }
.rx-col { display: flex; flex-direction: column; }
.card-head { padding: 0.85rem 1.1rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.card-head-title { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--td); }
.card-head-title svg { width: 14px; height: 14px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.card-head-sub { font-size: 11px; color: var(--tu); margin-top: 3px; }
.card-body { padding: 1rem; }
.queue-scroll { padding: 0.6rem; max-height: calc(100vh - 240px); overflow-y: auto; }
.pill-live { font-size: 9.5px; font-weight: 700; padding: 2px 8px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; letter-spacing: 0.05em; }
.fi { height: 28px; font-size: 11px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 9px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); width: 110px; }
.fi:focus { border-color: var(--ga); background: #fff; }

/* Primary filter (Belum Dipanggil / Selesai) */
.primary-filter { display: flex; gap: 4px; margin-bottom: 0.5rem; }
.pf-btn { flex: 1; height: 32px; font-size: 11.5px; font-weight: 500; border: 1.5px solid var(--gb); border-radius: 8px; background: var(--bs); color: var(--tm); cursor: pointer; font-family: 'Inter', sans-serif; transition: all .13s; display: flex; align-items: center; justify-content: center; gap: 5px; }
.pf-btn:hover { border-color: var(--ga); color: var(--ga); }
.pf-btn.a { background: var(--gd); color: #fff; border-color: var(--gd); }
.pf-ct { font-size: 9px; font-weight: 700; padding: 0 5px; border-radius: 10px; background: rgba(255,255,255,.25); }

/* Secondary filter (penjamin) */
.ptype-tabs { display: flex; gap: 3px; margin-bottom: 0.55rem; }
.ptype-tab { flex: 1; padding: 5px 4px; font-size: 10px; font-weight: 600; border: 1.5px solid var(--gb); border-radius: 7px; background: var(--bs); color: var(--tu); cursor: pointer; font-family: 'Inter',sans-serif; text-align: center; transition: all .13s; white-space: nowrap; }
.ptype-tab:hover { border-color: var(--ga); color: var(--ga); }
.ptype-tab.a { color: #fff; font-weight: 700; }
.ptype-bpjs.a { background: #1d4ed8; border-color: #1d4ed8; }
.ptype-umum.a { background: var(--ga); border-color: var(--ga); }

/* Search */
.q-search-wrap { margin-bottom: 0.5rem; }
.q-search { width: 100%; height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 10px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.q-search:focus { border-color: var(--ga); background: #fff; }

.rx-list { display: flex; flex-direction: column; gap: 5px; }
.rx-card { background: var(--bc); border: 1.5px solid var(--gb); border-radius: 9px; cursor: pointer; display: flex; overflow: hidden; transition: all 0.14s; }
.rx-card:hover { border-color: var(--lm); }
.rx-card.active { border-color: var(--ga); background: var(--gl); }
.rx-card.done { opacity: 0.55; }
.rx-card.urgent { border-color: var(--ebd); background: var(--eb); }
.rx-bar { width: 3px; }
.bar-menunggu { background: var(--wt); }
.bar-verifikasi { background: var(--it); }
.bar-disiapkan { background: var(--lm); }
.bar-done { background: var(--st); }
.bar-dilewati { background: var(--tu); }
.rx-body { flex: 1; padding: 8px 10px; min-width: 0; }
.rx-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 3px; }
.rx-num { font-weight: 700; font-size: 12.5px; color: var(--ga); letter-spacing: 0.03em; }
.rx-time { font-size: 9.5px; color: var(--tu); font-variant-numeric: tabular-nums; }
.rx-name { font-size: 12.5px; font-weight: 500; color: var(--td); }
.rx-meta { font-size: 10px; color: var(--tu); margin-top: 1px; }
.rx-tags { display: flex; gap: 3px; margin-top: 4px; flex-wrap: wrap; }
.rxt { font-size: 8.5px; font-weight: 700; padding: 1px 5px; border-radius: 4px; }
.rxt-b { background: #dbeafe; color: #1e40af; }
.rxt-u { background: var(--gl); color: var(--ga); }
.rxt-ranap { background: #e0e7ff; color: #3730a3; }
.rxt-racik { background: var(--wb); color: var(--wt); }
.rxt-menunggu { background: #fef3c7; color: #92400e; }
.rxt-verifikasi { background: #dbeafe; color: #1e40af; }
.rxt-disiapkan { background: #ede9fe; color: #5b21b6; }
.rxt-done { background: var(--sb); color: var(--st); }
.rxt-dilewati { background: var(--bs); color: var(--tu); }
.rx-items { margin-top: 5px; padding-top: 5px; border-top: 1px dashed var(--gb); }
.rx-item { font-size: 10px; color: var(--tm); padding: 1px 0; }
.rx-item.muted { color: var(--tu); }
.empty-rx { text-align: center; padding: 1.5rem; font-size: 11px; color: var(--th); }

/* Panggil / Lewati actions */
.rx-actions { display: flex; gap: 4px; margin-top: 6px; padding-top: 5px; border-top: 1px dashed var(--gb); }
.rx-act-btn { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; font-size: 10px; font-weight: 600; border-radius: 5px; border: 1px solid; cursor: pointer; font-family: 'Inter', sans-serif; transition: all .12s; }
.rx-act-btn svg { width: 10px; height: 10px; }
.rx-act-btn.call { background: var(--gl); color: var(--ga); border-color: var(--ga); }
.rx-act-btn.call:hover { background: var(--ga); color: #fff; }
.rx-act-btn.skip { background: var(--bs); color: var(--tu); border-color: var(--gb); }
.rx-act-btn.skip:hover { background: var(--wb); color: var(--wt); border-color: var(--wbd); }

.disp-col { display: flex; flex-direction: column; }
.disp-empty { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 4rem 2rem; display: flex; flex-direction: column; align-items: center; gap: 0.85rem; color: var(--th); text-align: center; min-height: 400px; justify-content: center; }
.disp-empty svg { width: 56px; height: 56px; fill: none; stroke: var(--gb); stroke-width: 1.5; stroke-linecap: round; }
.disp-empty p { font-size: 13px; }
.disp-panel { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; }
.disp-head { padding: 0.85rem 1.1rem; background: linear-gradient(135deg, var(--gm), var(--gd)); color: #fff; display: flex; align-items: center; justify-content: space-between; gap: 0.85rem; }
.disp-title { font-family: 'Space Grotesk', serif; font-size: 16px; line-height: 1.1; }
.disp-sub { font-size: 11px; color: rgba(255, 255, 255, 0.65); margin-top: 3px; }

.disp-steps { display: flex; align-items: center; padding: 0.85rem 1.1rem; background: var(--bs); border-bottom: 1px solid var(--gb); }
.ds { display: flex; align-items: center; flex: 1; }
.ds:last-child { flex: 0; }
.dsc { width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; border: 2px solid var(--gb); background: var(--bc); color: var(--tu); flex-shrink: 0; }
.ds.a .dsc { border-color: var(--ga); background: var(--ga); color: #fff; }
.ds.done .dsc { border-color: var(--st); background: var(--st); color: #fff; }
.dsc svg { width: 11px; height: 11px; }
.ds-label { font-size: 11.5px; font-weight: 500; color: var(--tu); margin-left: 7px; }
.ds.a .ds-label { color: var(--ga); font-weight: 600; }
.ds.done .ds-label { color: var(--st); }
.ds-line { flex: 1; height: 2px; background: var(--gb); margin: 0 8px; }
.ds-line.done { background: var(--st); }

.sec-title { font-size: 11px; font-weight: 600; color: var(--tm); letter-spacing: 0.06em; text-transform: uppercase; padding: 0.85rem 1.1rem 0.4rem; }

.dd { display: flex; gap: 0.65rem; align-items: flex-start; padding: 9px 1.1rem; border-bottom: 1px solid rgba(0, 0, 0, 0.03); }
.dd input[type='checkbox'] { width: 15px; height: 15px; accent-color: var(--ga); margin-top: 3px; flex-shrink: 0; cursor: pointer; }
.dd.racik { background: var(--wb); }
.dd-info { flex: 1; min-width: 0; }
.dd-name { font-size: 12.5px; font-weight: 500; color: var(--td); display: flex; align-items: center; gap: 6px; }
.dd-dose { font-size: 10.5px; color: var(--tu); margin-top: 2px; }
.dd-stock { font-size: 10px; margin-top: 2px; font-weight: 600; }
.dd-stock.ok { color: var(--st); }
.dd-stock.low { color: var(--wt); }
.dd-stock.out { color: var(--et); }
.racik-tag { font-size: 8.5px; font-weight: 700; padding: 1px 6px; border-radius: 20px; border: 1px solid var(--wbd); background: var(--wb); color: var(--wt); }
.dd.otc { background: #f0fdf4; border-left: 3px solid var(--ga); }
.otc-tag { font-size: 8.5px; font-weight: 700; padding: 1px 6px; border-radius: 20px; border: 1px solid var(--ga); background: var(--gl); color: var(--ga); }
.dd-qty-col { display: flex; flex-direction: column; align-items: center; gap: 2px; }
.dd-qty-label { font-size: 9px; color: var(--tu); }
.dd-qty { width: 60px; height: 30px; border: 1.5px solid var(--gb); border-radius: 6px; padding: 0 8px; text-align: center; font-size: 12px; font-weight: 600; outline: none; font-family: 'Inter', sans-serif; background: var(--bs); }
.dd-qty:focus { border-color: var(--ga); background: #fff; }
.dd-unit { font-size: 9px; color: var(--tu); }

.doc-note { margin: 0.65rem 1.1rem; padding: 7px 11px; background: var(--ib); border: 1px solid var(--ibd); color: var(--it); border-radius: 7px; font-size: 11px; }

/* Obat Bebas (OTC) form */
.otc-section { padding: 0.6rem 1.1rem; border-top: 1px dashed var(--gb); }
.otc-toggle { gap: 5px; }
.otc-form { background: var(--gl); border: 1px solid var(--ga); border-radius: 9px; padding: .7rem .9rem; }
.otc-form-title { font-size: 10.5px; font-weight: 700; color: var(--td); margin-bottom: .5rem; text-transform: uppercase; letter-spacing: .04em; }
.otc-fields { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .55rem; }
.otc-field { display: flex; flex-direction: column; gap: 2px; }
.otc-wide { flex: 2; min-width: 160px; }
.otc-narrow { flex: 0 0 70px; }
.otc-field:not(.otc-wide):not(.otc-narrow) { flex: 1; min-width: 110px; }
.otc-label { font-size: 9px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: .03em; }
.otc-input { height: 28px; font-size: 11px; width: 100%; box-sizing: border-box; }
/* Picker obat OTC (typeahead) */
.otc-picker { position: relative; }
.otc-picker-drop { position: absolute; z-index: 30; left: 0; right: 0; top: calc(100% + 3px); background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; max-height: 220px; overflow-y: auto; box-shadow: 0 8px 22px rgba(0,0,0,.12); }
.otc-picker-item { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 10px; cursor: pointer; border-bottom: 1px solid var(--gb); }
.otc-picker-item:last-child { border-bottom: none; }
.otc-picker-item:hover { background: var(--gl); }
.otc-pi-name { font-size: 11.5px; font-weight: 600; color: var(--td); }
.otc-pi-meta { display: flex; align-items: center; gap: 6px; font-size: 10px; color: var(--tu); white-space: nowrap; }
.otc-pick-empty { padding: 8px 10px; font-size: 11px; color: var(--tu); }
.otc-picked { display: flex; align-items: center; justify-content: space-between; gap: 8px; height: 28px; padding: 0 6px 0 10px; font-size: 11.5px; background: var(--bs); border: 1px solid var(--ga); border-radius: 6px; box-sizing: border-box; }
.otc-picked-x { border: none; background: transparent; cursor: pointer; font-size: 13px; line-height: 1; color: var(--tu); padding: 2px 4px; }
.otc-picked-x:hover { color: var(--et); }
.otc-form-actions { display: flex; gap: .4rem; }
.otc-hint { font-size: 10px; color: var(--et); margin-top: .45rem; }
.otc-harga { margin: .55rem 0; padding: .5rem .6rem; background: var(--ib); border: 1px solid var(--gb); border-radius: 6px; }
.otc-harga-load { font-size: 11px; color: var(--tu); }
.otc-harga-note { font-size: 10.5px; color: var(--it); font-weight: 600; }
.otc-harga-row { display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: var(--tu); }
.otc-harga-row b { color: var(--td); font-size: 12px; }
.otc-harga-row.total { margin-top: .25rem; padding-top: .3rem; border-top: 1px dashed var(--gb); }
.otc-harga-row.total b { color: var(--it); font-size: 13.5px; }

.disp-actions { padding: 0.85rem 1.1rem; border-top: 1px solid var(--gb); display: flex; gap: 0.5rem; flex-wrap: wrap; background: var(--bs); }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 0 14px; height: 36px; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 12.5px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; }
.btn-sm { height: 28px; padding: 0 10px; font-size: 11px; }
.btn-lg { height: 42px; padding: 0 18px; font-size: 13px; font-weight: 600; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover { background: var(--gm); }
.btn-info { background: var(--it); color: #fff; border-color: var(--it); }
.btn-info:hover { background: #1e40af; }
.btn-warning { background: var(--lm); color: var(--td); border-color: var(--lm); }
.btn-warning:hover { background: var(--ld); color: #fff; }
.btn-success { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-success:hover:not(:disabled) { background: var(--gm); }
.btn-success:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover { border-color: var(--ga); color: var(--td); background: var(--gl); }
/* Tombol Etiket di header gelap (disp-head) — kartu putih solid agar kontras. */
.btn-etiket { background: #fff; color: #1d4ed8 !important; border-color: #fff; }
.btn-etiket svg { stroke: #1d4ed8; }
.btn-etiket:hover:not(:disabled) { background: #eff4ff; color: #1d4ed8 !important; }
.btn-etiket:disabled { opacity: 0.55; cursor: not-allowed; }
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

.done-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; font-size: 12px; font-weight: 600; }
.done-pill svg { width: 14px; height: 14px; }

/* STOK */
.stok-head { display: flex; justify-content: flex-end; margin-bottom: 0.75rem; }
.stok-actions { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; justify-content: flex-end; }

.stok-search { position: relative; display: flex; align-items: center; }
.stok-search-ico { position: absolute; left: 10px; width: 14px; height: 14px; fill: none; stroke: var(--tm); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; pointer-events: none; }
.stok-search-input { padding-left: 30px !important; width: 220px; }
.low-alert { display: flex; align-items: center; gap: 8px; padding: 9px 13px; background: var(--eb); border: 1px solid var(--ebd); border-radius: 9px; color: var(--et); font-size: 11.5px; }
.low-alert svg { width: 16px; height: 16px; fill: none; stroke: var(--et); stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }

.loc-note { display: flex; align-items: center; gap: 8px; padding: 9px 13px; margin-bottom: 0.75rem; background: #eaf2fe; border: 1px solid #b9d4f7; border-radius: 9px; color: #000; font-size: 11.5px; line-height: 1.45; }
.loc-note b { color: #000; }
.loc-note svg { width: 16px; height: 16px; fill: none; stroke: #1763d4; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }

.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
/* Tabel stok — selaras dengan tabel Inventori Farmasi (.po-table) */
.po-table-wrap { background: var(--bc); border: 1px solid var(--gb); border-radius: 8px; overflow-x: auto; }
.po-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.po-table th, .po-table td { padding: 9px 12px; text-align: left; border-bottom: 1px solid var(--gb); }
.po-table th { background: var(--bs); font-weight: 600; color: var(--tm); font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; }
.po-table td.r, .po-table th.r { text-align: right; font-variant-numeric: tabular-nums; }
.po-table td.c, .po-table th.c { text-align: center; }
.po-table td.muted, .po-table .muted { color: var(--tu); }
.po-table tbody tr:last-child td { border-bottom: none; }
.po-table tbody tr:hover { background: var(--bs); }
.po-state { text-align: center; padding: 24px; color: var(--tu); font-size: 12.5px; }
.po-icon-btn { background: transparent; border: 1px solid var(--gb); border-radius: 5px; padding: 4px 6px; cursor: pointer; color: var(--tm); }
.po-icon-btn:hover { background: var(--bs); color: var(--td); }
.po-icon-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.stok-cell { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
.stok-cell span { font-weight: 600; }
.stok-cell .out { color: var(--et); }
.stok-cell .low { color: var(--wt); }
.bar { width: 60px; height: 4px; background: var(--gb); border-radius: 2px; overflow: hidden; }
.bar-fill { height: 100%; transition: width 0.3s; }
.bar-fill.ok { background: var(--st); }
.bar-fill.low { background: var(--wt); }
.bar-fill.out { background: var(--et); }
.kategori-pill { font-size: 10px; padding: 2px 7px; background: var(--bs); border: 1px solid var(--gb); border-radius: 4px; color: var(--tm); }

.placeholder-card { padding: 3rem 2rem; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; text-align: center; color: var(--tu); font-size: 13px; }

/* Stok Opname */
/* Riwayat Pemberian — header filter */
.rp-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.85rem; }
.rp-search { position: relative; display: flex; align-items: center; }
.rp-dates { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
.rp-date-lbl { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--tu); }
.rp-date-lbl .fi { width: 150px; }

.opname-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.opname-stats { display: flex; gap: 0.5rem; }
.ostat { background: var(--bc); border: 1px solid var(--gb); border-radius: 9px; padding: 6px 14px; display: flex; flex-direction: column; gap: 1px; min-width: 78px; }
.ostat-lbl { font-size: 10px; color: var(--tu); }
.ostat b { font-size: 16px; font-weight: 700; color: var(--td); line-height: 1; }
.ostat b.warn { color: var(--wt); }
.ostat b.plus { color: var(--st); }
.ostat b.minus { color: var(--et); }
.opname-actions { display: flex; align-items: center; gap: 0.5rem; }
.op-input { width: 92px; height: 30px; font-size: 12.5px; font-weight: 600; text-align: right; border: 1.5px solid var(--gb); border-radius: 6px; padding: 0 8px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.op-input:focus { border-color: var(--ga); background: #fff; }
.op-sel { font-weight: 700; font-variant-numeric: tabular-nums; color: var(--tu); }
.op-sel.plus { color: var(--st); }
.op-sel.minus { color: var(--et); }
.po-table tbody tr.op-diff { background: #fffbeb; }
.po-table tbody tr.op-diff:hover { background: #fef3c7; }

/* Laporan */
.lap-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.6rem; }
.lap-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 11px; padding: 0.85rem 1rem; }
.lap-lbl { font-size: 10.5px; color: var(--tu); }
.lap-val { font-size: 20px; font-weight: 700; color: var(--td); line-height: 1.1; margin-top: 4px; }
.lap-val.warn { color: var(--wt); }
.lap-val.err { color: var(--et); }
.lap-section { font-size: 11.5px; font-weight: 700; color: var(--tm); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; }
.lap-badge { display: inline-block; padding: 2px 9px; border-radius: 4px; font-size: 10px; font-weight: 700; }
.lap-badge.b-low { background: #fef3c7; color: #92400e; }
.lap-badge.b-out { background: var(--eb); color: var(--et); }
.lap-days { font-weight: 700; font-variant-numeric: tabular-nums; color: var(--tu); }
.lap-days.warn { color: var(--wt); }
.lap-days.err { color: var(--et); }

/* POS — Penjualan Obat Bebas */
.pos-grid { display: grid; grid-template-columns: 1fr 320px; gap: 0.75rem; align-items: start; }
.pos-body { padding: 0.9rem 1.1rem; }
/* Hasil pencarian mengalir DI DALAM card (bukan dropdown melayang), agar tidak
   keluar dari batas card saat mengetik. Tinggi dibatasi + scroll internal. */
.pos-search-drop { background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; max-height: 240px; overflow-y: auto; margin-top: 0.4rem; }
.pos-search-item { padding: 7px 11px; cursor: pointer; border-bottom: 1px solid var(--gb); }
.pos-search-item:hover { background: var(--gl); }
.pos-si-name { font-size: 12px; font-weight: 600; color: var(--td); }
.pos-si-meta { display: flex; gap: 8px; align-items: center; font-size: 10px; color: var(--tu); margin-top: 2px; }
.pos-out { color: var(--et); font-weight: 700; }
.pos-cart-table { width: 100%; border-collapse: collapse; margin-top: .6rem; }
.pos-cart-table th { font-size: 10px; font-weight: 700; color: var(--tu); text-transform: uppercase; text-align: left; padding: 4px 6px; border-bottom: 1.5px solid var(--gb); }
.pos-cart-table td { font-size: 12px; padding: 6px; border-bottom: 1px solid var(--gb); vertical-align: middle; }
.pos-cart-table .r { text-align: right; } .pos-cart-table .c { text-align: center; }
.pos-cart-table .sm { font-size: 9.5px; }
.pos-qty { width: 64px; }
.pos-field { display: flex; flex-direction: column; gap: 3px; margin-bottom: .55rem; }
.pos-field label { font-size: 9.5px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: .03em; }
.pos-input { width: 100%; box-sizing: border-box; }
.pos-summary { background: var(--gl); border: 1px solid var(--gb); border-radius: 8px; padding: .6rem .7rem; margin: .5rem 0; }
.pos-row { display: flex; align-items: center; justify-content: space-between; font-size: 12px; padding: 3px 0; color: var(--tm); }
.pos-row b { color: var(--td); font-variant-numeric: tabular-nums; }
.pos-grand { border-top: 1px dashed var(--gb); margin-top: 3px; padding-top: 6px; font-size: 13px; }
.pos-grand b { font-size: 15px; color: var(--ga); }
.pos-change { background: var(--sb); border-radius: 7px; padding: 6px 10px; font-weight: 600; }
.pos-change b { font-size: 14px; color: var(--st); }
.pos-history { margin-top: 1rem; }
.po-table tbody tr.pos-cancelled { opacity: .55; }
@media (max-width: 900px) { .pos-grid { grid-template-columns: 1fr; } }

/* Modal koreksi stok */
.es-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 1rem; }
.es-modal { background: var(--bc); border-radius: 12px; max-width: 460px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,.3); display: flex; flex-direction: column; }
.es-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--gb); }
.es-head h3 { margin: 0; font-size: 16px; color: var(--td); font-family: 'Space Grotesk', serif; }
.es-x { background: none; border: none; cursor: pointer; color: var(--tu); padding: 4px; }
.es-x svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.es-x:hover { color: var(--td); }
.es-body { padding: 16px 20px; }
.es-name { font-size: 13.5px; font-weight: 600; color: var(--td); margin-bottom: 12px; }
.es-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.es-field { display: flex; flex-direction: column; gap: 4px; }
.es-field label { font-size: 10.5px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: .03em; }
.es-input { height: 32px; font-size: 12.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 9px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.es-input:focus { border-color: var(--ga); background: #fff; }
.es-hint { font-size: 10.5px; color: var(--tu); margin: 12px 0 0; }
.es-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 20px; border-top: 1px solid var(--gb); }

.toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 999; display: flex; flex-direction: column; gap: 6px; }
.toast { padding: 9px 13px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); min-width: 230px; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }

/* Notifikasi gudang di tab Manajemen Stok */
.stok-notif-wrap { position: relative; }
.stok-bell { position: relative; width: 36px; height: 36px; border-radius: 9px; background: var(--bc); border: 1px solid var(--gb); cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--tm); transition: all 0.15s; }
.stok-bell:hover { background: var(--bs); color: var(--td); }
.stok-bell.active { color: var(--ga); border-color: var(--ga); }
.stok-bell svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.stok-bell-badge { position: absolute; top: -4px; right: -4px; background: #dc2626; color: white; font-size: 10px; font-weight: 700; min-width: 18px; height: 18px; padding: 0 4px; border-radius: 9px; display: flex; align-items: center; justify-content: center; border: 2px solid var(--bc); }

.stok-notif-panel { position: absolute; top: calc(100% + 8px); right: 0; width: 360px; max-width: 90vw; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; box-shadow: 0 10px 30px rgba(15,23,42,0.12); z-index: 60; display: flex; flex-direction: column; max-height: 70vh; overflow: hidden; }
.stok-notif-head { padding: 11px 14px; border-bottom: 1px solid var(--gb); display: flex; justify-content: space-between; align-items: center; background: var(--bs); }
.stok-notif-head strong { font-size: 13px; color: var(--td); }
.stok-notif-clear { background: none; border: none; color: var(--ga); font-size: 11.5px; cursor: pointer; padding: 0; }
.stok-notif-clear:hover { text-decoration: underline; }
.stok-notif-body { flex: 1; overflow-y: auto; }
.stok-notif-empty { padding: 2rem; text-align: center; font-size: 13px; color: var(--tm); }
.stok-notif-row { padding: 10px 14px; border-bottom: 1px solid var(--gb); display: flex; gap: 10px; align-items: flex-start; }
.stok-notif-row:last-child { border-bottom: none; }
.stok-notif-badge { flex-shrink: 0; padding: 3px 8px; border-radius: 10px; font-size: 10.5px; font-weight: 600; text-transform: capitalize; }
.nb-ok    { background: #d1fae5; color: #065f46; }
.nb-info  { background: #dbeafe; color: #1e40af; }
.nb-err   { background: #fee2e2; color: #991b1b; }
.nb-muted { background: #e5e7eb; color: #374151; }
.stok-notif-main { flex: 1; min-width: 0; }
.stok-notif-title { display: flex; gap: 8px; align-items: center; justify-content: space-between; }
.stok-notif-title code { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; color: var(--td); }
.stok-notif-time { font-size: 11px; color: var(--tu); }
.stok-notif-msg { font-size: 12px; color: var(--td); margin-top: 3px; line-height: 1.4; }
</style>
