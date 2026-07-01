<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { farmasiApi, unitRequestApi, unitReturnApi } from '@/services/api'
import UnitStockActions from '@/components/inventori-farmasi/UnitStockActions.vue'
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

// SEMUA resep yang relevan untuk dispensing loket dlm 1 visit (poli + pasca
// bedah/tindakan). Satu visit bisa punya >1 resep yg realitanya diserahkan SEKALI
// → diproses sbg satu bundel (lihat selRxList), bukan hanya resep pertama.
function dispActiveList(prescriptions = []) {
  if (!Array.isArray(prescriptions)) return []
  return prescriptions.filter((r) => ['DRAFT', 'SUBMITTED', 'DISPENSING', 'DISPENSED'].includes(r.status))
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
  const list = dispActiveList(q.visit?.prescriptions)
  if (!list.length) return 'menunggu'
  // 'done' HANYA bila SEMUA resep visit ini sudah diserahkan — cegah baris
  // "hilang/selesai" padahal resep pasca-bedah belum diserahkan.
  if (list.every((r) => r.status === 'DISPENSED')) return 'done'
  if (list.some((r) => r.status === 'DISPENSING')) return 'disiapkan'
  if (list.some((r) => verifiedRxIds.value.has(r.id))) return 'verifikasi'
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

// Baris antrean dibuat hari ini? (resep lintas-hari yang belum diserahkan → "Masih Aktif")
function isTodayRow(c) {
  if (!c) return true
  const d = new Date(c), n = new Date()
  return d.getFullYear() === n.getFullYear() && d.getMonth() === n.getMonth() && d.getDate() === n.getDate()
}
const isTodayQ = (q) => isTodayRow(q.created_at)
const isDoneQ  = (q) => rxStatusOf(q) === 'done'

const belumCount   = computed(() => queue.value.filter((q) => isTodayQ(q) && !isDoneQ(q)).length)
const selesaiCount = computed(() => queue.value.filter((q) => isTodayQ(q) &&  isDoneQ(q)).length)
const cActive      = computed(() => queue.value.filter((q) => !isTodayQ(q)).length)
// "Resep hari ini" / Total = HANYA baris hari ini (lintas-hari "Masih Aktif" tak dihitung).
const cToday       = computed(() => queue.value.filter((q) => isTodayQ(q)).length)

const filtRx = computed(() => {
  let l = queue.value

  // Primary: belum diserahkan vs selesai (hari ini) vs masih aktif (lintas-hari)
  if (rxPrimaryFilter.value === 'active')       l = l.filter((q) => !isTodayQ(q))
  else if (rxPrimaryFilter.value === 'waiting') l = l.filter((q) => isTodayQ(q) && !isDoneQ(q))
  else                                          l = l.filter((q) => isTodayQ(q) &&  isDoneQ(q))

  // Secondary: jenis penjamin
  if (rxSecondaryFilter.value === 'bpjs')      l = l.filter((q) => guarantorType(q) === 'bpjs')
  else if (rxSecondaryFilter.value === 'umum') l = l.filter((q) => guarantorType(q) !== 'bpjs')

  if (rxSearch.value) {
    const s = rxSearch.value.toLowerCase()
    l = l.filter((q) =>
      (q.visit?.patient?.name ?? '').toLowerCase().includes(s) ||
      String(q.queue_number ?? '').toLowerCase().includes(s) ||
      (q.visit?.patient?.no_rm ?? '').toLowerCase().includes(s) ||
      (q.visit?.bpjs_antrean_number ?? '').toLowerCase().includes(s),
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
const selRx         = ref(null)   // resep PRIMER (= selRxList[0]) — utk OTC add-obat & header
const selRxList     = ref([])     // SEMUA resep visit (poli + pasca) → bundel 1 serah-terima
const selRxLoading  = ref(false)

// Gabungan seluruh item lintas-resep (utk validasi checklist serah & tombol).
const selAllItems = computed(() => selRxList.value.flatMap((r) => r.items ?? []))
// Label sumber resep di panel (hanya tampil saat >1 resep agar tak ramai).
function dispRxLabel(rx) { return rx?.is_post_op ? 'Pasca Bedah/Tindakan' : 'Poliklinik' }
// Ganti satu resep di selRxList (+selRx primer) dengan versi terbaru dari backend.
function replaceSelRx(rx) {
  if (!rx) return
  const idx = selRxList.value.findIndex((r) => r.id === rx.id)
  if (idx !== -1) selRxList.value.splice(idx, 1, rx)
  if (selRx.value?.id === rx.id) selRx.value = rx
}

const dispSteps = ['Verifikasi', 'Siapkan', 'Serah Terima']
const dispStep = computed(() => {
  const list = selRxList.value
  if (!list.length) return 0
  if (list.every((r) => r.status === 'DISPENSED')) return 3
  if (list.some((r) => r.status === 'DISPENSING')) return 2
  // Resep yang masuk antrean serah SUDAH diverifikasi & dikunci Farmasi sebelum
  // bayar (alur D→K→F) → lewati langkah verifikasi UI lama. verifiedRxIds hanya
  // fallback untuk resep tanpa verified_at (mis. OTC dibuat di apotek).
  if (list.every((r) => r.verified_at || verifiedRxIds.value.has(r.id))) return 1
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
  selRxList.value = []
  resetAddObat()

  const stubs = dispActiveList(q.visit?.prescriptions)
  // Tanpa resep dokter → panel akan menawarkan "Buat Penjualan OTC" (obat tambahan).
  if (!stubs.length) return

  selRxLoading.value = true
  try {
    // Muat SEMUA resep (poli + pasca) → satu bundel serah-terima. Poli dulu (bukan
    // post-op) sbg primer agar OTC add-obat menempel di resep poliklinik.
    const loaded = []
    for (const stub of stubs) {
      const { data } = await farmasiApi.showResep(stub.id)
      loaded.push(hydrateRx(data.data))
    }
    loaded.sort((a, b) => (a.is_post_op === b.is_post_op ? 0 : a.is_post_op ? 1 : -1))
    selRxList.value = loaded
    selRx.value = loaded[0] ?? null
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
      replaceSelRx(hydrateRx(data.data, selRx.value))
    } else {
      // Belum ada resep → buat penjualan OTC baru untuk visit ini.
      const { data } = await farmasiApi.storeOtc(selQ.value?.visit?.id, [item])
      selRx.value = hydrateRx(data.data)
      selRxList.value = [selRx.value]
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
    if (selQ.value?.id === q.id) { selQ.value = null; selRx.value = null; selRxList.value = [] }
    await fetchQueue()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal melewati pasien')
  }
}

function verifikasiRx() {
  if (!selRxList.value.length) return
  verifiedRxIds.value = new Set([...verifiedRxIds.value, ...selRxList.value.map((r) => r.id)])
  toast('s', selRxList.value.length > 1 ? `${selRxList.value.length} resep diverifikasi` : 'Resep diverifikasi')
}

async function siapkanRx() {
  if (!selRxList.value.length) return
  try {
    // Siapkan SEMUA resep visit sekaligus (poli + pasca) — satu bundel serah-terima.
    for (const rx of selRxList.value) {
      if (['DISPENSING', 'DISPENSED'].includes(rx.status)) continue
      const { data } = await farmasiApi.startDispensing(rx.id)
      replaceSelRx(hydrateRx(data.data, rx))
      refreshQueueForRx(data.data)
    }
    toast('s', 'Obat disiapkan, cek kembali sebelum diserahkan')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal mulai dispensing')
  }
}

const serahkanLoading = ref(false)
async function serahkanRx() {
  if (!selRxList.value.length || serahkanLoading.value) return
  const items = selAllItems.value
  if (!items.length) { toast('w', 'Resep tidak punya item obat'); return }
  if (!items.every((d) => d.checked)) {
    toast('w', 'Cek semua item terlebih dahulu'); return
  }
  // Resep TERVERIFIKASI: qty terkunci sejak verifikasi (alur D→K→F), field qty
  // disabled → tak ada perubahan. Resep BELUM-terkunci (OTC/tambahan apotek):
  // persist qty teredit dulu agar stok terpotong & tagihan sesuai jumlah final
  // yang benar-benar diserahkan (cegah edit qty diam-diam hilang). Mirror serahRanap.
  for (const rx of selRxList.value) {
    if (rx.verified_at) continue
    const invalid = (rx.items ?? []).find((d) => !Number.isFinite(Number(d.quantity)) || Number(d.quantity) < 1)
    if (invalid) { toast('w', `Jumlah obat ${invalid.medication?.name ?? ''} tidak valid (min. 1)`); return }
  }
  serahkanLoading.value = true
  try {
    // Serahkan tiap resep (poli + pasca) berurutan; antrean baru ditutup setelah
    // SEMUA resep DISPENSED → pasien tak perlu diklik-ulang & resep pasca tak lupa.
    for (const rx of selRxList.value) {
      if (rx.status === 'DISPENSED') continue
      let cur = rx
      // Persist qty teredit DULU — startDispensing→hydrateRx (di bawah) menimpa
      // quantity & _origQty dgn nilai server, sehingga diff jadi kosong & edit
      // hilang diam-diam (stok/tagihan pakai qty awal). Mirror serahRanap.
      if (!cur.verified_at) {
        const changed = (cur.items ?? []).filter((d) => d.id && Number(d.quantity) !== Number(d._origQty))
        for (const d of changed) await farmasiApi.updateItem(d.id, { quantity: Number(d.quantity) })
      }
      // selesaiDispensing butuh status DISPENSING — siapkan dulu bila terlewat.
      if (!['DISPENSING', 'DISPENSED'].includes(cur.status)) {
        const { data } = await farmasiApi.startDispensing(cur.id)
        cur = hydrateRx(data.data, cur); replaceSelRx(cur)
      }
      // DISPENSING → DISPENSED: backend consume() kurangi stok inventory_stocks
      // lokasi FARMASI (FEFO per-batch) sesuai quantity tiap item.
      const { data } = await farmasiApi.selesaiDispensing(cur.id)
      replaceSelRx(hydrateRx(data.data, cur))
      refreshQueueForRx(data.data)
    }

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
    // BHP visit ikut terpotong stoknya di selesaiAntrian → bersihkan daftar (tak basi)
    // & segarkan stok BHP bila ada.
    if (pendingBhp.value.length) {
      if (selQ.value?.visit) selQ.value.visit.bhp_usages = []
      fetchStokBhp()
    }
    // Refresh stok unit Farmasi supaya tampilan stok ikut turun.
    fetchStok()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyelesaikan dispensing')
  } finally {
    serahkanLoading.value = false
  }
}

// Semua resep visit tanpa item obat (mis. obat dibatalkan pasien di Kasir / dokter
// tak meresepkan) → tawarkan "Selesaikan tanpa obat" untuk menutup antrean.
const selRxEmpty = computed(() => selRxList.value.length > 0 && selAllItems.value.length === 0)

// Jalan keluar untuk resep 0-item: tanpa ini pasien BUNTU di antrean Farmasi (tombol
// "Serahkan" disabled karena tak ada item). Tutup antrean tanpa penyerahan obat —
// selesaiDispensing aman dgn 0 item (tak ada stok dipotong), lalu selesaiAntrian.
async function tutupTanpaObat() {
  if (!selRxList.value.length || serahkanLoading.value) return
  if (!window.confirm('Resep ini tidak memiliki item obat (obat dibatalkan / tidak diresepkan).\nTutup antrean pasien tanpa penyerahan obat?')) return
  serahkanLoading.value = true
  try {
    for (const rx of selRxList.value) {
      let cur = rx
      // selesaiDispensing butuh status DISPENSING — siapkan dulu bila masih SUBMITTED/DRAFT.
      if (!['DISPENSING', 'DISPENSED'].includes(cur.status)) {
        const { data } = await farmasiApi.startDispensing(cur.id)
        cur = hydrateRx(data.data, cur); replaceSelRx(cur)
      }
      if (cur.status !== 'DISPENSED') {
        const { data } = await farmasiApi.selesaiDispensing(cur.id)
        cur = hydrateRx(data.data, cur); replaceSelRx(cur)
        refreshQueueForRx(data.data)
      }
    }
    // Tutup antrean pasien → pulang. Gagal di sini tak fatal (resep sudah DISPENSED).
    if (selQ.value?.id) {
      try {
        const { data: qData } = await farmasiApi.selesaiAntrian(selQ.value.id)
        const updated = qData.data?.queue ?? qData.data
        if (updated?.id) Object.assign(selQ.value, updated)
      } catch (e) {
        toast('w', e.response?.data?.message ?? 'Antrean gagal ditutup. Tutup manual bila perlu.')
      }
    }
    toast('s', 'Antrean pasien ditutup (tidak ada obat untuk diserahkan)')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menutup antrean')
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

// ─── BHP dipakai dokter (tampil di kartu dispensing) ─────────────────────────
// Eager-loaded di antrean sbg `q.visit.bhpUsages` — HANYA yang belum diserahkan
// (consumed_batches NULL). Stok BHP DITUNDA & dipotong backend saat antrean ditutup
// (selesaiAntrian): untuk pasien dengan resep ikut serahkanRx; untuk pasien BHP-only
// (tanpa resep) lewat tombol "Serahkan BHP" di bawah.
// Relasi bhpUsages → diserialisasi snake_case oleh Laravel jadi `bhp_usages`.
const pendingBhp = computed(() => selQ.value?.visit?.bhp_usages ?? [])
const pendingBhpTotal = computed(() =>
  pendingBhp.value.reduce((s, b) => s + (Number(b.unit_price) || 0) * (Number(b.quantity) || 0), 0)
)

const bhpOnlyLoading = ref(false)
async function serahBhpOnly() {
  if (!selQ.value?.id || bhpOnlyLoading.value) return
  bhpOnlyLoading.value = true
  try {
    // selesaiAntrian: backend memotong stok BHP kunjungan ini lalu menutup antrean.
    // Bila stok BHP kurang → 422 (antrean tak ditutup), tampilkan pesannya.
    const { data: qData } = await farmasiApi.selesaiAntrian(selQ.value.id)
    const updated = qData.data?.queue ?? qData.data
    if (updated?.id) Object.assign(selQ.value, updated)
    toast('s', 'BHP diserahkan, stok Farmasi diperbarui')
    fetchStokBhp()
    fetchQueue()
    selQ.value = null
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal menyerahkan BHP')
  } finally {
    bhpOnlyLoading.value = false
  }
}

// ─── VERIFIKASI FARMASI (gate sebelum tagihan Kasir) — alur D→K→F ────────────
// Resep dokter (SUBMITTED) muncul di sini. Farmasi substitusi/ubah qty/hapus/tambah
// (wajib alasan) lalu "Verifikasi & Kunci" → Kasir baru bisa membuat tagihan.
const verQueue   = ref([])
const verLoading = ref(false)
const verError   = ref('')
const verSearch  = ref('')
const verFrom    = ref('')   // filter rentang tgl (client-side) — kosong = semua
const verTo      = ref('')
const verSel     = ref(null)
const verBusy    = ref(false)
// Filter utama tab Verifikasi: 'belum' = grup masih ada resep perlu verifikasi,
// 'selesai' = grup yang seluruh resepnya sudah terkunci. Default sembunyikan yang
// selesai (kurangi keramaian) — yang terkunci tetap bisa dilihat via tab "Selesai".
const verPrimaryFilter = ref('belum')
// Pisahkan pasien Rawat Jalan vs Rawat Inap (obat pulang) di antrean Verifikasi.
// 'rajal' = semua non-RANAP (poli/IGD/bedah/tindakan); 'ranap' = obat pulang (jenis_kode RANAP).
const verServiceFilter = ref('rajal')

const VER_REASONS = [
  { v: 'STOK_HABIS',        t: 'Stok habis' },
  { v: 'OVER_BUDGET_BPJS',  t: 'Over-budget BPJS / Fornas' },
  { v: 'PERMINTAAN_PASIEN', t: 'Permintaan pasien' },
  { v: 'KOREKSI_KLINIS',    t: 'Koreksi klinis' },
  { v: 'LAINNYA',           t: 'Lainnya' },
]
const verReason = ref('STOK_HABIS')

const verPendingCount = computed(() => verQueue.value.filter((rx) => !rx.verified_at).length)
// Tanggal efektif kartu (YYYY-MM-DD) untuk filter rentang — selaras verTgl():
// RANAP → tgl order (created_at), selain itu → tgl kunjungan (visit_date).
function verRawDate(rx) {
  const ranap = rx?.jenis_kode === 'RANAP'
  const raw = ranap ? rx?.created_at : rx?.visit?.visit_date
  return raw ? String(raw).slice(0, 10) : ''
}
const filtVerQueue = computed(() => {
  const s = verSearch.value.toLowerCase().trim()
  const from = verFrom.value, to = verTo.value
  return verQueue.value.filter((rx) => {
    if (s && !(
      (rx.visit?.patient?.name ?? '').toLowerCase().includes(s) ||
      (rx.visit?.patient?.no_rm ?? '').toLowerCase().includes(s))) return false
    if (from || to) {
      const d = verRawDate(rx)
      if (!d) return false
      if (from && d < from) return false
      if (to && d > to) return false
    }
    return true
  })
})
function verClearDates() { verFrom.value = ''; verTo.value = '' }
const verSelTotal = computed(() =>
  (verSel.value?.items ?? []).reduce((sum, it) => sum + Number(it.est_total_price ?? 0), 0))

// Pasien BHP-only (BHP dokter belum-verif TANPA resep di worklist) — daftar terpisah
// dari backend (data.bhp_only). Difilter pencarian sama spt resep (klien-saja).
const bhpOnlyVisits = ref([])
const filtBhpOnly = computed(() => {
  const s = verSearch.value.toLowerCase().trim()
  if (!s) return bhpOnlyVisits.value
  return bhpOnlyVisits.value.filter((v) =>
    (v.patient?.name ?? '').toLowerCase().includes(s) ||
    (v.patient?.no_rm ?? '').toLowerCase().includes(s))
})

// Kelompokkan antrean per PASIEN/visit: 1 visit bisa punya >1 resep (poli + pasca
// bedah/tindakan) yang realitanya diambil SEKALI. Kartu = 1 pasien; tiap resep
// jadi chip terpisah (pill POLI/Pasca tetap terlihat). Resep tetap record terpisah
// di backend — verifikasi tetap per-resep, hanya tampilan yang digabung. BHP dokter
// (visit.bhp_usages) ikut per-kartu; pasien BHP-only jadi kartu tanpa chip resep.
const verAllGroups = computed(() => {
  const m = new Map()
  for (const rx of filtVerQueue.value) {
    const vid = rx.visit?.id ?? rx.id
    if (!m.has(vid)) m.set(vid, { vid, visit: rx.visit, items: [], bhp: rx.visit?.bhp_usages ?? [] })
    m.get(vid).items.push(rx)
  }
  for (const v of filtBhpOnly.value) {
    if (!m.has(v.id)) m.set(v.id, { vid: v.id, visit: v, items: [], bhp: v.bhp_usages ?? [] })
  }
  return [...m.values()].map((g) => {
    const bhpPending = g.bhp.filter((b) => !b.verified_at).length
    return {
      ...g,
      isBhpOnly: g.items.length === 0,
      bhpPending,
      pendingCount: g.items.filter((rx) => !rx.verified_at).length + bhpPending,
    }
  })
})
// Grup Rawat Inap = ada resep obat pulang (jenis_kode RANAP). BHP-only ranap tak masuk
// tab Verifikasi (dipindah ke Dispensing Ranap), jadi cukup cek jenis_kode item resep.
function groupIsRanap(g) { return (g.items ?? []).some((rx) => rx.jenis_kode === 'RANAP') }
function verMatchService(g) { return verServiceFilter.value === 'ranap' ? groupIsRanap(g) : !groupIsRanap(g) }
// Badge jenis layanan (jumlah grup yg PERLU verifikasi per jenis).
const verRajalCount = computed(() => verAllGroups.value.filter((g) => !groupIsRanap(g) && g.pendingCount > 0).length)
const verRanapCount = computed(() => verAllGroups.value.filter((g) => groupIsRanap(g) && g.pendingCount > 0).length)
// Jumlah grup per status (badge filter) — basis = setelah cari/tanggal + jenis layanan aktif.
const verBelumGroupCount   = computed(() => verAllGroups.value.filter((g) => verMatchService(g) && g.pendingCount > 0).length)
const verSelesaiGroupCount = computed(() => verAllGroups.value.filter((g) => verMatchService(g) && g.pendingCount === 0).length)
// Grup yang ditampilkan sesuai filter jenis layanan + status.
const verGroups = computed(() => verAllGroups.value.filter((g) =>
  verMatchService(g) &&
  (verPrimaryFilter.value === 'selesai' ? g.pendingCount === 0 : g.pendingCount > 0)))

async function fetchVerQueue({ fromPoll = false } = {}) {
  verLoading.value = true; verError.value = ''
  try {
    const { data } = await farmasiApi.verifikasiQueue()
    // Respons kini gabungan: { prescriptions, bhp_only }.
    verQueue.value = data.data?.prescriptions ?? []
    bhpOnlyVisits.value = data.data?.bhp_only ?? []
    // Polling (8 dtk) JANGAN ganggu panel yang sedang dikerjakan: reassign verSel
    // mengembalikan input qty/kemasan (:value uncontrolled) yang belum di-blur ke
    // nilai server, dan menutup paksa picker substitusi (verSubItem). Saat ada
    // seleksi aktif, biarkan panel apa adanya — daftar tetap ter-refresh. Refresh
    // penuh terjadi lewat pemanggilan manual (pasca-aksi/pickVer/tombol ↻).
    if (fromPoll && verSel.value) return
    if (verSel.value) verSel.value = verQueue.value.find((r) => r.id === verSel.value.id) ?? null
    verSubItem.value = null   // buang referensi picker substitusi yang bisa basi pasca-refetch
  } catch (err) {
    verError.value = err.response?.data?.message ?? 'Gagal memuat antrean verifikasi'
  } finally { verLoading.value = false }
}
function pickVer(rx) { verSel.value = rx; verSubItem.value = null; verAddOpen.value = false }

// Badge asal resep di antrean Verifikasi (warna per jenis_kode dari backend).
function verPillClass(kode) { return 'jp-pill jp-' + (kode || 'RAJAL').toLowerCase() }
// Tanggal di kartu verifikasi: RANAP → tgl order (resep dibuat), selain itu (RAJAL/
// Pasca Bedah/IGD) → tgl kunjungan. Label menyesuaikan agar tak ambigu.
function verTgl(rx) {
  const ranap = rx?.jenis_kode === 'RANAP'
  return {
    label: ranap ? 'Tgl order' : 'Tgl kunjungan',
    value: fmtDateId(ranap ? rx?.created_at : rx?.visit?.visit_date),
  }
}

// Substitusi obat (pilih obat pengganti dari stok) — pakai picker stok obat penuh.
const verSubItem   = ref(null)   // item resep yang sedang disubstitusi
const verSubSearch = ref('')
const verSubResults = computed(() => {
  const s = verSubSearch.value.toLowerCase().trim()
  const list = stokList.value
  if (!s) return list.slice(0, 30)
  return list.filter((m) =>
    (m.name ?? '').toLowerCase().includes(s) ||
    (m.generic_name ?? '').toLowerCase().includes(s) ||
    (m.code ?? '').toLowerCase().includes(s)).slice(0, 30)
})
function openSubstitute(item) {
  verSubItem.value = item; verSubSearch.value = ''
  if (!stokList.value.length) fetchStok()
}

async function verItemUpdate(item, payload) {
  if (verBusy.value) return
  verBusy.value = true
  try {
    // Alasan (mis. STOK_HABIS) hanya untuk SUBSTITUSI obat — itu deviasi nyata dari
    // resep dokter. Sekadar ubah JUMLAH bukan deviasi, jadi jangan cap alasan supaya
    // tak muncul "Alasan: STOK_HABIS" menyesatkan pada obat yang stoknya ada.
    const body = payload.medication_id
      ? { change_reason: verReason.value, ...payload }
      : { ...payload }
    await farmasiApi.updateItem(item.id, body)
    await fetchVerQueue()
    toast('s', 'Item diperbarui')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memperbarui item')
  } finally { verBusy.value = false }
}
function verSetQty(item, qty) {
  const q = Number(qty)
  if (!Number.isFinite(q) || q < 1) { toast('w', 'Jumlah minimal 1'); return }
  if (q === Number(item.quantity)) return
  verItemUpdate(item, { quantity: q })
}

// ─── Varian kemasan jual (Strip/Box, harga independen) — dipilih saat verifikasi ──
// Backend menjaga invarian quantity = sale_unit_qty × isi (stok tetap satuan kecil).
async function verApplyKemasan(item, payload) {
  if (verBusy.value) return
  verBusy.value = true
  try {
    await farmasiApi.setKemasan(item.id, payload)
    await fetchVerQueue()
    toast('s', 'Kemasan item diperbarui')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal mengubah kemasan')
  } finally { verBusy.value = false }
}
function verPickKemasan(item, saleUnitId) {
  if (!saleUnitId) {
    // Kembali satuan kecil — quantity dipertahankan (tagih per satuan lagi).
    if (item.sale_unit_id) verApplyKemasan(item, { sale_unit_id: null })
    return
  }
  const u = (item.available_sale_units ?? []).find((x) => x.id === saleUnitId)
  if (!u) return
  const qty = Number(item.quantity) || 1
  const kemasanQty = Math.max(1, Math.floor(qty / u.isi))
  const totalBaru = kemasanQty * u.isi
  const sisa = qty - totalBaru   // bisa negatif bila qty < isi (dibulatkan NAIK ke 1 kemasan)
  const unit = item.medication?.unit ?? ''
  let split = false
  if (sisa > 0) {
    split = window.confirm(
      `${qty} ${unit} = ${kemasanQty} ${u.label} (isi ${u.isi}) + sisa ${sisa}.\n` +
      `OK = pecah sisa jadi item satuan terpisah.\nCancel = jadikan ${kemasanQty} ${u.label} saja (total menjadi ${totalBaru} ${unit}).`
    )
  } else if (totalBaru !== qty) {
    // qty < isi → Math.floor=0 lalu dipaksa min 1 kemasan → jumlah obat NAIK dari
    // resep tanpa peringatan (over-dispense). Wajib konfirmasi sebelum menaikkan.
    if (!window.confirm(
      `${qty} ${unit} kurang dari 1 ${u.label} (isi ${u.isi}).\n` +
      `Mengubah ke kemasan ini akan MENAIKKAN jumlah menjadi ${totalBaru} ${unit}. Lanjutkan?`
    )) return   // batal — biarkan satuan kecil seperti semula
  }
  verApplyKemasan(item, { sale_unit_id: u.id, sale_unit_qty: kemasanQty, split_remainder: split })
}
function verSetKemasanQty(item, qty) {
  const q = Number(qty)
  if (!Number.isFinite(q) || q < 1) { toast('w', 'Jumlah kemasan minimal 1'); return }
  if (q === Number(item.sale_unit_qty)) return
  verApplyKemasan(item, { sale_unit_id: item.sale_unit_id, sale_unit_qty: q })
}
function applySubstitute(m) {
  if (!verSubItem.value || !m?.id) return
  if (m.id === verSubItem.value.medication_id) { verSubItem.value = null; return }
  verItemUpdate(verSubItem.value, { medication_id: m.id })
  verSubItem.value = null
}
async function verRemove(item) {
  if (verBusy.value) return
  if (!window.confirm(`Hapus ${item.medication?.name ?? 'item'} dari resep? Alasan: ${verReason.value}`)) return
  verBusy.value = true
  try {
    await farmasiApi.deleteItem(item.id, verReason.value)
    await fetchVerQueue()
    toast('s', 'Item dihapus')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menghapus item')
  } finally { verBusy.value = false }
}

// Tambah obat (bebas/OTC) saat verifikasi — golongan keras tetap ranah dokter.
const verAddOpen   = ref(false)
const verAddForm   = ref({ medication_id: '', quantity: 1, dosage: '', instructions: '' })
const verAddSearch = ref('')
const verAddResults = computed(() => {
  const s = verAddSearch.value.toLowerCase().trim()
  const list = otcMedications.value
  if (!s) return list.slice(0, 30)
  return list.filter((m) =>
    (m.name ?? '').toLowerCase().includes(s) ||
    (m.generic_name ?? '').toLowerCase().includes(s) ||
    (m.code ?? '').toLowerCase().includes(s)).slice(0, 30)
})
const verAddSelected = computed(() => otcMedications.value.find((m) => m.id === verAddForm.value.medication_id) ?? null)
function toggleVerAdd() {
  verAddOpen.value = !verAddOpen.value
  verAddSearch.value = ''
  if (verAddOpen.value && !stokList.value.length) fetchStok()
}
async function verAddSubmit() {
  if (verBusy.value) return
  const f = verAddForm.value
  if (!f.medication_id) { toast('w', 'Pilih obat dulu'); return }
  if (!Number.isFinite(Number(f.quantity)) || Number(f.quantity) < 1) { toast('w', 'Jumlah minimal 1'); return }
  verBusy.value = true
  try {
    await farmasiApi.storeItem(verSel.value.id, [{
      medication_id: f.medication_id,
      quantity:      Number(f.quantity),
      dosage:        f.dosage || null,
      instructions:  f.instructions || null,
      source:        'TAMBAHAN',
      change_reason: verReason.value,
    }])
    await fetchVerQueue()
    verAddForm.value = { medication_id: '', quantity: 1, dosage: '', instructions: '' }
    verAddOpen.value = false
    toast('s', 'Obat ditambahkan')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menambah obat')
  } finally { verBusy.value = false }
}

async function verLock(rx) {
  if (verBusy.value) return
  if (!(rx.items ?? []).length && !window.confirm('Resep tidak punya item obat. Tetap verifikasi & kunci?')) return
  verBusy.value = true
  try {
    await farmasiApi.verifikasiResep(rx.id)
    await fetchVerQueue()
    toast('s', 'Resep diverifikasi & dikunci. Kasir dapat membuat tagihan.')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memverifikasi resep')
  } finally { verBusy.value = false }
}
// Verifikasi SEMUA resep belum-terverifikasi dalam satu grup pasien (poli + pasca)
// sekaligus → 1 kali aksi untuk 1 kali pengambilan obat. Loop FE (reuse endpoint
// per-resep), lalu satu refetch di akhir.
async function verLockAll(group) {
  if (verBusy.value) return
  const pend = (group?.items ?? []).filter((rx) => !rx.verified_at)
  if (!pend.length) return
  const hasEmpty = pend.some((rx) => !(rx.items ?? []).length)
  if (hasEmpty && !window.confirm('Ada resep tanpa item obat. Tetap verifikasi & kunci semua?')) return
  verBusy.value = true
  try {
    for (const rx of pend) await farmasiApi.verifikasiResep(rx.id)
    await fetchVerQueue()
    toast('s', `${pend.length} resep diverifikasi & dikunci. Kasir dapat membuat tagihan.`)
  } catch (err) {
    await fetchVerQueue()
    toast('w', err.response?.data?.message ?? 'Gagal memverifikasi sebagian resep')
  } finally { verBusy.value = false }
}
async function verUnlock(rx) {
  if (verBusy.value) return
  verBusy.value = true
  try {
    await farmasiApi.bukaVerifikasi(rx.id)
    await fetchVerQueue()
    toast('s', 'Kunci verifikasi dibuka — silakan koreksi')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal membuka kunci verifikasi')
  } finally { verBusy.value = false }
}

// ─── Verifikasi BHP dokter (per KUNJUNGAN, terpisah dari kunci resep) ─────────
async function verLockBhp(group) {
  if (verBusy.value || !group?.vid) return
  verBusy.value = true
  try {
    await farmasiApi.verifikasiBhp(group.vid)
    await fetchVerQueue()
    toast('s', 'BHP diverifikasi & dikunci. Kasir dapat membuat tagihan.')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memverifikasi BHP')
  } finally { verBusy.value = false }
}
async function verUnlockBhp(group) {
  if (verBusy.value || !group?.vid) return
  verBusy.value = true
  try {
    await farmasiApi.bukaVerifikasiBhp(group.vid)
    await fetchVerQueue()
    toast('s', 'Kunci verifikasi BHP dibuka — silakan koreksi')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal membuka kunci BHP')
  } finally { verBusy.value = false }
}
async function verUpdateBhp(b, qty) {
  const n = Math.max(1, Number(qty) || 1)
  if (verBusy.value || n === Number(b.quantity)) return
  verBusy.value = true
  try {
    await farmasiApi.updateBhpUsage(b.id, n)
    await fetchVerQueue()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal mengubah jumlah BHP')
    await fetchVerQueue()   // pulihkan nilai input ke server
  } finally { verBusy.value = false }
}
async function verRemoveBhp(b) {
  if (verBusy.value) return
  const reason = window.prompt(`Alasan menghapus BHP "${b.bhp_item?.name ?? 'BHP'}" (wajib):`, '')
  if (reason === null) return                 // batal
  if (!reason.trim()) { toast('w', 'Alasan wajib diisi'); return }
  verBusy.value = true
  try {
    await farmasiApi.deleteBhpUsage(b.id, reason.trim())
    await fetchVerQueue()
    toast('s', 'BHP dihapus')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menghapus BHP')
  } finally { verBusy.value = false }
}

// ─── Dispensing Rawat Inap (permintaan obat pasien dirawat → serah ke ruangan) ─
const ranapQueue   = ref([])
const ranapLoading = ref(false)
const selRanap     = ref(null)   // permintaan terpilih (hydrate _origQty utk diff qty)
const ranapBusy    = ref(false)
// BHP pasien rawat inap (belum-verif) — dipindah ke sini dari tab Verifikasi agar
// Farmasi mengurus 1 pasien ranap (obat + BHP) di satu tempat. visit.bhp_usages snake_case.
const ranapBhpVisits = ref([])
const ranapBhpBusy   = ref(false)

const ranapSteps = computed(() => ['Disiapkan', selRanap.value ? ranapSerahLabel(selRanap.value) : 'Serah ke Ruangan'])
const ranapStep = computed(() => {
  if (!selRanap.value) return 0
  if (selRanap.value.status === 'DISPENSED') return 2
  if (selRanap.value.status === 'DISPENSING') return 1
  return 0
})
const ranapWaitingCount = computed(
  () => ranapQueue.value.filter((p) => ['SUBMITTED', 'DISPENSING'].includes(p.status)).length,
)

// Filter status + pencarian pasien untuk antrean Dispensing Ranap.
// 'belum' = belum diserahkan (SUBMITTED/DISPENSING); 'selesai' = sudah diserahkan (DISPENSED).
const ranapPrimaryFilter = ref('belum')
const ranapSearch = ref('')
const ranapBelumCount   = computed(() => ranapQueue.value.filter((p) => ['SUBMITTED', 'DISPENSING'].includes(p.status)).length)
const ranapSelesaiCount = computed(() => ranapQueue.value.filter((p) => p.status === 'DISPENSED').length)

// ── Antrean BERBASIS PASIEN ──────────────────────────────────────────────────
// Pasien inap bisa order obat berkali-kali → 1 kartu = 1 PASIEN (visit), tiap
// permintaan jadi sub-item di panel kanan. Kurangi keramaian & beri konteks penuh.
const RANAP_STATUS_ORDER = { DISPENSING: 0, SUBMITTED: 1, DISPENSED: 2 }
function sortRanapRequests(reqs) {
  return [...reqs].sort((a, b) =>
    (RANAP_STATUS_ORDER[a.status] - RANAP_STATUS_ORDER[b.status]) ||
    String(a.created_at).localeCompare(String(b.created_at)))
}
// Daftar kartu kiri: grup per pasien, difilter status (belum/selesai) + pencarian.
const ranapGroups = computed(() => {
  const s = ranapSearch.value.toLowerCase().trim()
  const m = new Map()
  for (const p of ranapQueue.value) {
    const vid = p.visit?.id ?? p.id
    if (!m.has(vid)) m.set(vid, { vid, visit: p.visit, requests: [] })
    m.get(vid).requests.push(p)
  }
  return [...m.values()].map((g) => {
    g.requests = sortRanapRequests(g.requests)
    g.pendingCount = g.requests.filter((r) => ['SUBMITTED', 'DISPENSING'].includes(r.status)).length
    g.doneCount    = g.requests.filter((r) => r.status === 'DISPENSED').length
    g.readyCount   = g.requests.filter((r) => r.status === 'DISPENSING').length
    return g
  }).filter((g) => {
    const okStatus = ranapPrimaryFilter.value === 'selesai' ? g.pendingCount === 0 : g.pendingCount > 0
    if (!okStatus) return false
    if (s && !(
      (g.visit?.patient?.name ?? '').toLowerCase().includes(s) ||
      (g.visit?.patient?.no_rm ?? '').toLowerCase().includes(s))) return false
    return true
  }).sort((a, b) => b.pendingCount - a.pendingCount)
})

// Pasien terpilih (vid). Panel kanan dibangun dari ranapQueue MENTAH agar tetap
// tampil walau grup keluar dari filter kiri (mis. semua permintaan sudah diserahkan).
const selRanapVisit = ref(null)
const selRanapGroup = computed(() => {
  if (!selRanapVisit.value) return null
  const reqs = ranapQueue.value.filter((p) => (p.visit?.id ?? p.id) === selRanapVisit.value)
  if (!reqs.length) return null
  const sorted = sortRanapRequests(reqs)
  return {
    vid: selRanapVisit.value,
    visit: sorted[0].visit,
    requests: sorted,
    pendingCount: sorted.filter((r) => ['SUBMITTED', 'DISPENSING'].includes(r.status)).length,
    readyCount:   sorted.filter((r) => r.status === 'DISPENSING').length,
  }
})

// Pilih pasien → otomatis buka permintaan pertama yang belum diserahkan.
function pickRanapGroup(g) {
  selRanapVisit.value = g.vid
  const first = g.requests.find((r) => r.status !== 'DISPENSED') ?? g.requests[0]
  if (first) pickRanap(first)
  else selRanap.value = null
}

function ranapStatusLabel(s) {
  return s === 'DISPENSED' ? 'diserahkan' : s === 'DISPENSING' ? 'disiapkan' : 'diminta'
}
function ranapRoomLabel(p) {
  const room = p.visit?.room?.name ?? p.visit?.room?.code ?? ''
  const bed  = p.visit?.bed?.label ?? p.visit?.bed?.code ?? ''
  return [room, bed].filter(Boolean).join(' · ') || 'Rawat Inap'
}
// Label penjamin: BPJS/UMUM/ASURANSI + nama insurer bila ada (selaras verifikasi).
function ranapPenjamin(p) {
  const g = (p.visit?.guarantor_type ?? 'UMUM').toUpperCase()
  const ins = p.visit?.insurer?.name
  return ins && g !== 'UMUM' ? `${g} · ${ins}` : g
}
// Cara serah: PICKUP = keluarga ambil di loket; selain itu (DELIVER/legacy) = antar ke kamar.
function ranapIsPickup(p) { return p?.fulfillment_mode === 'PICKUP' }
function ranapModeLabel(p) { return ranapIsPickup(p) ? 'Ambil di Farmasi' : 'Antar ke Kamar' }
// Obat pulang (dibuat saat discharge) vs permintaan obat harian — bedakan dari notes.
function ranapIsDischarge(p) { return /obat pulang/i.test(p?.notes ?? '') }
// Tombol/langkah "serah": antar ke ruangan vs serahkan ke pengambil.
function ranapSerahLabel(p) { return ranapIsPickup(p) ? 'Serah ke Pengambil' : 'Serah ke Ruangan' }

async function fetchRanapQueue() {
  ranapLoading.value = true
  try {
    const { data } = await farmasiApi.ranapList()
    // Respons kini gabungan { prescriptions, bhp_only }; fallback array lama utk aman.
    const payload = data.data ?? {}
    ranapQueue.value     = payload.prescriptions ?? (Array.isArray(payload) ? payload : [])
    ranapBhpVisits.value = payload.bhp_only ?? []
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

// Serah SEMUA permintaan pasien yang sudah DISIAPKAN (status DISPENSING) sekaligus.
// Efisiensi utk pasien dgn banyak order siap-serah. Penyiapan/pengkajian TETAP
// per-permintaan (gate klinis Permenkes), jadi hanya yang sudah 'Disiapkan' yang di-serah.
async function serahSemuaSiap() {
  const g = selRanapGroup.value
  if (!g || ranapBusy.value) return
  const ready = g.requests.filter((r) => r.status === 'DISPENSING')
  if (!ready.length) { toast('i', 'Tidak ada permintaan yang sudah disiapkan.'); return }
  // Permintaan yg SEDANG dibuka dipegang oleh salinan teredit `selRanap` (punya edit
  // qty + _origQty); item di `ranapQueue` mentah TAK memuat edit. Pakai salinan itu
  // agar koreksi qty ikut tersimpan; permintaan lain pakai qty server (tak bisa diedit
  // tanpa dibuka). Tanpa ini, edit qty pada permintaan terbuka hilang diam-diam.
  const sourceFor = (r) => (selRanap.value && selRanap.value.id === r.id) ? selRanap.value : r
  // Validasi qty (samakan dgn serah tunggal) — cegah serah qty < 1 / tak valid.
  for (const r of ready) {
    const bad = (sourceFor(r).items ?? []).find((d) => !Number.isFinite(Number(d.quantity)) || Number(d.quantity) < 1)
    if (bad) { toast('w', `Jumlah obat ${bad.medication?.name ?? ''} tidak valid (min. 1) pada permintaan ${formatTime(r.created_at)}`); return }
  }
  if (!confirm(`Serahkan ${ready.length} permintaan yang sudah disiapkan ke ruangan?`)) return
  ranapBusy.value = true
  try {
    for (const r of ready) {
      const items = sourceFor(r).items ?? []
      // Persist qty teredit (permintaan terbuka punya _origQty; lainnya tak ada → skip).
      const changed = items.filter((d) => d.id && d._origQty !== undefined && Number(d.quantity) !== Number(d._origQty))
      for (const d of changed) await farmasiApi.updateItem(d.id, { quantity: Number(d.quantity) })
      await farmasiApi.ranapSerah(r.id)
    }
    toast('s', `${ready.length} permintaan diserahkan ke ruangan`)
    await fetchRanapQueue()
    fetchStok()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyerahkan sebagian permintaan')
    fetchRanapQueue()
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

// Verifikasi & kunci BHP pasien rawat inap (set verified_at) — masuk kwitansi saat
// pasien pulang. Endpoint sama dengan BHP dokter; perilaku identik, hanya pindah konteks.
async function verifRanapBhp(v) {
  if (ranapBhpBusy.value || !v?.id) return
  ranapBhpBusy.value = true
  try {
    await farmasiApi.verifikasiBhp(v.id)
    toast('s', 'BHP rawat inap diverifikasi & dikunci — masuk tagihan saat pasien pulang.')
    fetchRanapQueue()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memverifikasi BHP')
  } finally {
    ranapBhpBusy.value = false
  }
}

// ─── Pengkajian resep ranap (Permenkes 72/2016 · PKPO 5.1) ───────────────────
// Obat ranap tak lewat tab Verifikasi pra-Kasir, jadi telaah resep apoteker
// (administratif/farmasetik/klinis) dilakukan SEBELUM Siapkan via modal di bawah.
const showPengkajian   = ref(false)
const pengkajianChecks = ref({ adm: false, farmasetik: false, klinis: false })

// Sumber alergi pasien terpilih: allergy_notes persisten + asesmen perawat (bila triase).
const selRanapAllergy = computed(() => {
  const p  = selRanap.value?.visit?.patient
  const na = selRanap.value?.visit?.nurse_assessment
  const parts = []
  if (p?.allergy_notes) parts.push(p.allergy_notes)
  if (na?.has_allergy && na?.allergy_detail) parts.push(na.allergy_detail)
  return parts.join(' · ').trim()
})
const selRanapDupIds = computed(() => selRanap.value?.duplicate_medication_ids ?? [])
// Soft-match (BUKAN deteksi pasti): token pertama nama obat muncul di teks alergi.
function itemAllergyHit(d) {
  const a = selRanapAllergy.value.toLowerCase()
  const name = (d.medication?.name ?? '').toLowerCase()
  if (!a || !name) return false
  const token = name.split(/[\s\-(/]/)[0]
  return token.length >= 4 && a.includes(token)
}
function itemIsDup(d) {
  return selRanapDupIds.value.includes(d.medication_id ?? d.medication?.id)
}
const ranapHasAllergyHit = computed(() => (selRanap.value?.items ?? []).some(itemAllergyHit))
const ranapHasDup        = computed(() => (selRanap.value?.items ?? []).some(itemIsDup))

function openPengkajian() {
  if (!selRanap.value) return
  pengkajianChecks.value = { adm: false, farmasetik: false, klinis: false }
  showPengkajian.value = true
}
async function confirmPengkajianSiapkan() {
  const c = pengkajianChecks.value
  if (!c.adm || !c.farmasetik || !c.klinis) {
    toast('w', 'Lengkapi telaah administratif, farmasetik, dan klinis dulu')
    return
  }
  showPengkajian.value = false
  await siapkanRanap()
}

// ─── Stok Obat ──────────────────────────────────────────────────────────────
const stokList     = ref([])
const stokSearch   = ref('')
const stokLoading  = ref(false)
// Manajemen Stok melayani OBAT & BHP (BHP dikonsumsi/ditagih mis. rawat inap).
const stokKind     = ref('obat')   // 'obat' | 'bhp'

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

// ── Stok BHP (bahan habis pakai) ──
const bhpList    = ref([])
const bhpLoading = ref(false)
const bhpSearch  = ref('')
async function fetchStokBhp() {
  bhpLoading.value = true
  try {
    const { data } = await farmasiApi.stokBhp({ per_page: 'all' })
    const payload = data.data
    bhpList.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat stok BHP')
  } finally {
    bhpLoading.value = false
  }
}
const bhpFiltered = computed(() => {
  const s = bhpSearch.value.toLowerCase()
  return s ? bhpList.value.filter((x) =>
    (x.name ?? '').toLowerCase().includes(s) || (x.code ?? '').toLowerCase().includes(s)) : bhpList.value
})
const bhpPage = ref(1)
const bhpLastPage = computed(() => Math.max(1, Math.ceil(bhpFiltered.value.length / STOK_PER_PAGE)))
const bhpPaged = computed(() => {
  const start = (bhpPage.value - 1) * STOK_PER_PAGE
  return bhpFiltered.value.slice(start, start + STOK_PER_PAGE)
})
const bhpLowCount = computed(() => bhpList.value.filter((b) => Number(b.stock) <= Number(b.min_stock ?? 0)).length)
watch(bhpSearch, () => { bhpPage.value = 1 })
watch(bhpLastPage, (lp) => { if (bhpPage.value > lp) bhpPage.value = lp })
// Muat BHP saat pertama kali beralih ke mode BHP.
watch(stokKind, (k) => { if (k === 'bhp' && !bhpList.value.length) fetchStokBhp() })

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

// Aturan pakai 1 baris untuk item resep. Resep DOKTER simpan di
// dose(jumlah)/frequency(signa)/route(posisi mata)/duration_days(durasi);
// item TAMBAHAN apotek (OTC) pakai dosage/instructions legacy. Tampilkan
// keduanya dgn fallback agar tak ada lagi "- · -".
function rxAturan(d) {
  const jumlah = d.dose || d.dosage || ''
  const signa  = d.frequency || d.instructions || ''
  const mata   = d.route || ''
  const durasi = d.duration_days ? `${d.duration_days} hari` : ''
  const parts  = [jumlah, signa, mata, durasi].filter(Boolean)
  return parts.length ? parts.join(' · ') : '-'
}
const lowStockCount = computed(
  () => stokList.value.filter((s) => Number(s.stock || 0) <= Number(s.min_stock ?? 0)).length,
)

// ─── Request / Retur ke gudang Inventori Farmasi ─────────────────────────────
// Dipanggil oleh UnitStockActions saat ada perubahan: toast + refresh stok bila perlu.
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
const editStok = ref(null)   // { id, name, kind, stock, min_stock, batch_number, expiry_date }
const savingStok = ref(false)

// kind: 'obat' | 'bhp' — BHP tak punya formularium/batch/expiry (cukup stok/min).
function openEditStok(s, kind = 'obat') {
  editStok.value = {
    id:           s.id,
    name:         s.name,
    kind,
    stock:        Number(s.stock ?? 0),
    min_stock:    Number(s.min_stock ?? 0),
    batch_number: s.batch_number ?? '',
    expiry_date:  s.expiry_date ? String(s.expiry_date).slice(0, 10) : '',
  }
}

async function saveEditStok() {
  if (!editStok.value) return
  savingStok.value = true
  const isBhp = editStok.value.kind === 'bhp'
  try {
    if (isBhp) {
      await farmasiApi.updateStokBhp(editStok.value.id, {
        stock:     Number(editStok.value.stock),
        min_stock: Number(editStok.value.min_stock),
      })
    } else {
      await farmasiApi.updateStokObat(editStok.value.id, {
        stock:        Number(editStok.value.stock),
        min_stock:    Number(editStok.value.min_stock),
        batch_number: editStok.value.batch_number || null,
        expiry_date:  editStok.value.expiry_date || null,
      })
    }
    toast('s', `Stok ${editStok.value.name} diperbarui`)
    editStok.value = null
    isBhp ? fetchStokBhp() : fetchStok()
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
// Opname dipisah per jenis: stok unit Farmasi OBAT vs BHP (bahan habis pakai).
const opnameKind   = ref('obat')   // 'obat' | 'bhp'
const opnameSource = computed(() => (opnameKind.value === 'bhp' ? bhpList.value : stokList.value))

function loadOpname() {
  opnameRows.value = opnameSource.value.map((s) => ({
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

// Muat ulang stok sistem sesuai jenis opname aktif (Obat / BHP).
async function fetchOpnameSource() {
  if (opnameKind.value === 'bhp') await fetchStokBhp()
  else await fetchStok()
}
async function reloadOpname() {
  await fetchOpnameSource()
  loadOpname()
  toast('i', 'Data opname dimuat ulang dari sistem')
}

async function saveOpname() {
  const changed = opnameChanged.value
  if (!changed.length) { toast('i', 'Tidak ada selisih untuk disimpan'); return }
  if (!confirm(`Terapkan penyesuaian ${changed.length} item? Stok sistem akan disamakan dengan stok fisik.`)) return
  opnameSaving.value = true
  const isBhp = opnameKind.value === 'bhp'
  let ok = 0, fail = 0
  for (const r of changed) {
    const f = opnameFisik(r)
    if (f === null) continue
    try {
      await (isBhp ? farmasiApi.updateStokBhp(r.id, { stock: f }) : farmasiApi.updateStokObat(r.id, { stock: f }))
      ok++
    } catch { fail++ }
  }
  opnameSaving.value = false
  toast(fail ? 'w' : 's', `Penyesuaian selesai: ${ok} berhasil${fail ? `, ${fail} gagal` : ''}`)
  await fetchOpnameSource()
  loadOpname()
}

// Saat tab opname dibuka: muat bila kosong; bila sudah ada baris TANPA selisih
// tertunda, segarkan baseline dari stok terbaru (cegah "Stok Sistem" basi setelah
// dispensing). Bila ada hitungan fisik yang belum disimpan, biarkan agar tak hilang.
async function openOpname() {
  if (opnameRows.value.length && opnameChanged.value.length) return
  // Pastikan sumber stok (Obat/BHP) sudah dimuat sebelum membangun lembar opname.
  if (!opnameSource.value.length) await fetchOpnameSource()
  loadOpname()
}

// Muat / segarkan data opname saat tab dibuka.
watch(() => pgTab.value, (t) => { if (t === 'opname') openOpname() })
// Ganti jenis opname (Obat ⇄ BHP): muat ulang lembar dari sumber yang sesuai.
watch(opnameKind, async () => {
  opnameSearch.value = ''
  opnamePage.value = 1
  if (!opnameSource.value.length) await fetchOpnameSource()
  loadOpname()
})

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
    const res = await farmasiApi.opnameExport({ format: 'xlsx', kind: opnameKind.value })
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '')
    triggerDownload(res.data, `stok-opname-${opnameKind.value}-${today}.xlsx`)
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
    .filter((s) => Number(s.stock || 0) <= Number(s.min_stock ?? 0))
    .sort((a, b) => Number(a.stock || 0) - Number(b.stock || 0)),
)
const lapExpiring = computed(
  () => stokList.value
    // Hanya batch yang MASIH BERSTOK — item stok 0 tak ada barang utk dimusnahkan,
    // memasukkannya menggelembungkan KPI "mendekati kadaluarsa".
    .filter((s) => s.expiry_date && Number(s.stock || 0) > 0)
    .map((s) => ({ ...s, _days: daysToExpiry(s.expiry_date) }))
    .filter((s) => Number.isFinite(s._days) && s._days <= 90)
    .sort((a, b) => a._days - b._days),
)

// ─── Tab Riwayat Pemberian (global, server-side) ─────────────────────────────
// Daftar SEMUA obat yang diberikan ke pasien (resep ter-dispense + penjualan POS),
// dengan pencarian (obat/pasien/no.RM), rentang tanggal, dan paginasi 50/halaman.
const rpRows    = ref([])
const rpSearch  = ref('')
const rpFrom    = ref('')
const rpTo      = ref('')
const rpJenis   = ref('')   // '' | RAJAL | RANAP | IGD | POS
const rpLoading = ref(false)
// Sub-tab jenis pelayanan. Pemberian obat pasca-bedah dilebur ke Rawat Jalan/Inap
// (lihat klasifikasi backend), jadi tak ada sub-tab "Bedah" tersendiri.
const RP_JENIS_OPTS = [
  { val: '',      label: 'Semua' },
  { val: 'RAJAL', label: 'Rawat Jalan' },
  { val: 'RANAP', label: 'Rawat Inap' },
  { val: 'IGD',   label: 'IGD' },
  { val: 'POS',   label: 'Obat Bebas' },
]
// Kelas warna pill per jenis (selaras dengan jenis_kode dari backend).
function rpPillClass(kode) {
  return 'jp-pill jp-' + (kode || 'RAJAL').toLowerCase()
}
const rpMeta    = ref({ current_page: 1, last_page: 1, total: 0, per_page: 50 })
let _rpDebounce = null

async function fetchRiwayatPemberian(page = 1) {
  rpLoading.value = true
  try {
    const { data } = await farmasiApi.riwayatPemberian({
      search:    rpSearch.value.trim() || undefined,
      date_from: rpFrom.value || undefined,
      date_to:   rpTo.value || undefined,
      jenis:     rpJenis.value || undefined,
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
watch([rpFrom, rpTo, rpJenis], () => fetchRiwayatPemberian(1))
function rpResetFilter() {
  rpSearch.value = ''
  rpFrom.value = ''
  rpTo.value = ''
  rpJenis.value = ''
  fetchRiwayatPemberian(1)
}
// Export seluruh riwayat (mengikuti filter aktif) ke Excel.
// Excel/PhpSpreadsheet menahan seluruh sheet di RAM → untuk data sangat besar
// (> RP_XLSX_MAX baris) otomatis ekspor CSV streaming (tetap bisa dibuka di Excel).
const RP_XLSX_MAX = 20000
const rpExporting = ref(false)
async function exportRiwayatPemberian() {
  rpExporting.value = true
  try {
    const asCsv = (rpMeta.value.total || 0) > RP_XLSX_MAX
    const res = await farmasiApi.riwayatPemberianExport({
      search: rpSearch.value.trim() || undefined,
      date_from: rpFrom.value || undefined,
      date_to:   rpTo.value || undefined,
      jenis:     rpJenis.value || undefined,
      format:    asCsv ? 'csv' : 'xlsx',
    })
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '')
    triggerDownload(res.data, `riwayat-pemberian-obat-${today}.${asCsv ? 'csv' : 'xlsx'}`)
    if (asCsv) toast('i', `Data >${RP_XLSX_MAX.toLocaleString('id-ID')} baris diekspor sebagai CSV (dapat dibuka di Excel). Persempit filter tanggal untuk file Excel.`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal mengekspor riwayat pemberian')
  } finally {
    rpExporting.value = false
  }
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
// Tanggal kunjungan kartu antrean → "16 Jun 2026" (sumber Y-m-d; '' bila kosong/invalid).
function fmtVisitDate(d) {
  if (!d) return ''
  const dt = new Date(String(d).length <= 10 ? `${d}T00:00:00` : d)
  return Number.isNaN(dt.getTime()) ? '' : dt.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
}

// Etiket dari tab Verifikasi — setiap resep yang sedang diverifikasi bisa langsung dicetak
// etiketnya (item & aturan pakai sudah final di tahap ini). Dipindah dari tab Dispensing.
function printVerEtiket() {
  printEtiketFor(verSel.value, verSel.value?.visit?.patient ?? {})
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
          <div class="aturan">${escHtml(rxAturan(d))}</div>
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

// Tagih ke Kasir (handoff): kirim keranjang sbg penjualan PENDING (belum bayar).
// Stok tetap dipotong (reserve) di server; Kasir yang menutup pembayaran + kwitansi.
async function posTagihKasir() {
  if (!posCart.value.length) { toast('w', 'Keranjang masih kosong'); return }
  posSaving.value = true
  try {
    const payload = {
      buyer_name:  posBuyer.value || null,
      buyer_phone: posPhone.value || null,
      discount:    Number(posDisc.value || 0),
      items: posCart.value.map((it) => ({ medication_id: it.medication_id, quantity: it.quantity })),
    }
    const { data } = await farmasiApi.penjualanTagihKasir(payload)
    const sale = data.data
    toast('s', `Tagihan ${sale.sale_number} dikirim ke Kasir`)
    resetPos()
    fetchStok()
    if (pgTab.value === 'penjualan') loadPosHistory()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal mengirim ke kasir')
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
  if (t === 'verifikasi') fetchVerQueue()
  if (t === 'riwayat' && !rpRows.value.length) fetchRiwayatPemberian(1)
})

// Antrean rawat jalan + permintaan rawat inap di-poll bersama (8s).
function pollFarmasi() {
  fetchQueue()
  if (pgTab.value === 'ranap') fetchRanapQueue()
  if (pgTab.value === 'verifikasi') fetchVerQueue({ fromPoll: true })
}

onMounted(() => {
  fetchQueue()
  fetchStok()
  fetchRanapQueue()
  fetchVerQueue()
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
      <button :class="['nt', pgTab === 'verifikasi' ? 'a' : '']" @click="pgTab = 'verifikasi'">
        <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
        Verifikasi Resep
        <span v-if="verPendingCount" class="ntbg alert">{{ verPendingCount }}</span>
      </button>
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
          <div><div class="stat-val">{{ cToday }}</div><div class="stat-lbl">Total Resep</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background: var(--wb)"><svg viewBox="0 0 24 24" stroke="var(--wt)"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
          <div><div class="stat-val" style="color: var(--wt)">{{ queue.filter((q) => isTodayQ(q) && rxStatusOf(q) === 'menunggu').length }}</div><div class="stat-lbl">Menunggu</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background: var(--ib)"><svg viewBox="0 0 24 24" stroke="var(--it)"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/></svg></div>
          <div><div class="stat-val" style="color: var(--it)">{{ queue.filter((q) => isTodayQ(q) && rxStatusOf(q) === 'disiapkan').length }}</div><div class="stat-lbl">Disiapkan</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background: var(--sb)"><svg viewBox="0 0 24 24" stroke="var(--st)"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg></div>
          <div><div class="stat-val" style="color: var(--st)">{{ queue.filter((q) => isTodayQ(q) && rxStatusOf(q) === 'done').length }}</div><div class="stat-lbl">Selesai</div></div>
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
                <div class="card-head-sub">{{ cToday }} resep hari ini</div>
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
                <button :class="['pf-btn', rxPrimaryFilter === 'active' ? 'a' : '']" @click="rxPrimaryFilter = 'active'" title="Resep belum diserahkan dari hari sebelumnya (lintas-hari)">
                  Masih Aktif
                  <span v-if="cActive" class="pf-ct">{{ cActive }}</span>
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
                <div class="rx-meta">{{ q.visit?.patient?.no_rm ?? '—' }}
                  <span v-if="(q.visit?.visit_date ?? q.created_at)" class="q-visit-date" :title="`Tanggal kunjungan: ${fmtVisitDate(q.visit?.visit_date ?? q.created_at)}`">
                    <svg viewBox="0 0 24 24" class="pill-icon"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    {{ fmtVisitDate(q.visit?.visit_date ?? q.created_at) }}
                  </span>
                </div>
                <div class="rx-tags">
                  <span :class="['rxt', guarantorType(q) === 'bpjs' ? 'rxt-b' : 'rxt-u']">{{ guarantorType(q) === 'bpjs' ? 'BPJS' : 'Umum' }}</span>
                  <span v-if="q.visit?.bpjs_antrean_number" class="rxt" style="background:#e0f2fe;color:#075985" :title="`No. Antrean JKN (Mobile JKN): ${q.visit.bpjs_antrean_number}`">JKN {{ q.visit.bpjs_antrean_number }}</span>
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
            <!-- BHP-only: pasien tanpa resep obat tapi ada BHP dokter → serahkan di sini
                 (backend memotong stok BHP lalu menutup antrean). -->
            <div v-if="pendingBhp.length" class="bhp-only-card">
              <div class="bhp-only-head">
                <div class="disp-title">{{ selQ.visit?.patient?.name ?? '—' }} — {{ selQ.queue_number }}</div>
                <div class="disp-sub">{{ selQ.visit?.patient?.no_rm ?? '—' }} · BHP dipakai dokter</div>
              </div>
              <div class="sec-title">BHP Dipakai Dokter</div>
              <div v-for="b in pendingBhp" :key="b.id" class="bhp-disp-row">
                <div class="bhp-disp-info">
                  <div class="bhp-disp-name">{{ b.bhp_item?.name ?? 'BHP' }}</div>
                  <div class="bhp-disp-meta">{{ b.bhp_item?.code ?? '' }}<span v-if="b.bhp_item?.category"> · {{ b.bhp_item.category }}</span></div>
                </div>
                <div class="bhp-disp-qty">×{{ b.quantity }}</div>
              </div>
              <button class="btn btn-success btn-lg bhp-only-serah" :disabled="bhpOnlyLoading" @click="serahBhpOnly">
                <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                {{ bhpOnlyLoading ? 'Memproses…' : 'Serahkan BHP & Selesai' }}
              </button>
              <div class="bhp-disp-note">Stok BHP dipotong saat diserahkan.</div>
              <div class="bhp-only-sep"><span>atau buat penjualan obat bebas</span></div>
            </div>
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
                <div class="disp-sub">{{ selQ.visit?.patient?.no_rm ?? '—' }} · dr. {{ selRx.prescribed_by?.name ?? '—' }} · {{ selRxList.length > 1 ? `${selRxList.length} resep · ` : '' }}{{ selAllItems.length }} item</div>
                <div class="ident-row">
                  <span v-if="selQ.visit?.dpjp_name" class="ident-dpjp" title="Dokter Penanggung Jawab Pelayanan">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    DPJP: {{ selQ.visit.dpjp_name }}
                  </span>
                  <span class="ident-chip" title="Tanggal lahir">
                    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    {{ fmtDateId(selQ.visit?.patient?.date_of_birth) }}
                  </span>
                  <span v-if="selQ.visit?.patient?.address" class="ident-chip ident-addr" :title="selQ.visit.patient.address">
                    <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <span>{{ selQ.visit.patient.address }}</span>
                  </span>
                </div>
              </div>
              <!-- Tombol Etiket DIPINDAH ke tab Verifikasi (etiket dicetak saat verifikasi,
                   sebelum Kasir & Dispensing). -->
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
            <!-- Item dikelompokkan per resep (poli + pasca) → satu kali serah-terima. -->
            <template v-for="rx in selRxList" :key="rx.id">
              <div v-if="selRxList.length > 1" class="disp-rx-sub">
                <span :class="['jp-pill', rx.is_post_op ? 'jp-bedah' : 'jp-poli']">{{ dispRxLabel(rx) }}</span>
                <span class="muted">{{ rx.items?.length ?? 0 }} item</span>
              </div>
              <div v-for="(d, i) in rx.items" :key="d.id ?? i" :class="['dd', { otc: d.source === 'TAMBAHAN' }]">
                <input type="checkbox" v-model="d.checked" />
                <div class="dd-info">
                  <div class="dd-name">
                    {{ d.medication?.name ?? '—' }}
                    <span v-if="d.source === 'TAMBAHAN'" class="otc-tag">TAMBAHAN APOTEK</span>
                  </div>
                  <div class="dd-dose">{{ rxAturan(d) }}</div>
                  <div :class="['dd-stock', itemStok(d) > 10 ? 'ok' : itemStok(d) > 0 ? 'low' : 'out']">
                    Stok: {{ itemStok(d) }} {{ d.medication?.unit ?? '' }}{{ itemStok(d) === 0 ? ' — HABIS' : itemStok(d) <= 3 ? ' — LOW' : '' }}
                  </div>
                  <div v-if="d.notes" class="dd-dose">Catatan: {{ d.notes }}</div>
                </div>
                <div class="dd-qty-col">
                  <span class="dd-qty-label">Jumlah</span>
                  <input v-model.number="d.quantity" type="number" min="1" class="dd-qty" :disabled="rx.status === 'DISPENSED' || !!rx.verified_at" />
                  <span class="dd-unit">{{ d.medication?.unit ?? '' }}</span>
                </div>
              </div>
            </template>
            <div v-if="!selAllItems.length" class="empty-rx">Resep tidak memiliki item obat (obat dibatalkan / tidak diresepkan). Klik <b>“Selesaikan tanpa obat”</b> di bawah untuk menutup antrean pasien.</div>

            <!-- BHP dipakai dokter pada kunjungan ini. Read-only di sini (dikelola dokter);
                 stok BHP dipotong saat tombol Serahkan ditekan (ikut selesaiAntrian). -->
            <div v-if="pendingBhp.length" class="bhp-disp-sec">
              <div class="sec-title">BHP Dipakai Dokter</div>
              <div v-for="b in pendingBhp" :key="b.id" class="bhp-disp-row">
                <div class="bhp-disp-info">
                  <div class="bhp-disp-name">{{ b.bhp_item?.name ?? 'BHP' }}</div>
                  <div class="bhp-disp-meta">{{ b.bhp_item?.code ?? '' }}<span v-if="b.bhp_item?.category"> · {{ b.bhp_item.category }}</span></div>
                </div>
                <div class="bhp-disp-qty">×{{ b.quantity }}</div>
              </div>
              <div class="bhp-disp-note">Stok BHP dipotong saat <b>Serahkan</b> (bukan saat dokter input).</div>
            </div>

            <!-- Tambah obat di luar resep: hanya untuk resep BELUM dikunci (mis. OTC
                 tanpa resep dokter). Resep terverifikasi sudah ditagih → perubahan
                 obat dilakukan di tab Verifikasi (sebelum bayar) atau via Penjualan POS. -->
            <div v-if="selRx.status !== 'DISPENSED' && !selRx.verified_at" class="otc-section">
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
              <!-- Resep 0-item (obat dibatalkan/tak diresepkan): tanpa jalan keluar ini pasien
                   buntu di antrean (tombol Serahkan disabled). Tutup antrean tanpa penyerahan. -->
              <button v-if="selRxEmpty && (dispStep === 1 || dispStep === 2)" class="btn btn-secondary btn-lg" :disabled="serahkanLoading" @click="tutupTanpaObat">
                <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                {{ serahkanLoading ? 'Memproses…' : 'Selesaikan tanpa obat' }}
              </button>
              <button v-else-if="dispStep === 1" class="btn btn-warning btn-lg" @click="siapkanRx">
                <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/></svg>
                Siapkan Obat
              </button>
              <button v-else-if="dispStep === 2" class="btn btn-success btn-lg" :disabled="serahkanLoading || !selAllItems.length || !selAllItems.every((d) => d.checked)" @click="serahkanRx">
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

    <!-- VERIFIKASI RESEP (gate sebelum tagihan Kasir) -->
    <div v-if="pgTab === 'verifikasi'" class="tab-pane">
      <div class="loc-note">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Verifikasi resep dokter <b>sebelum</b> pasien membayar. Substitusi / ubah jumlah / hapus / tambah obat (mis. <b>over-budget BPJS</b> atau <b>stok habis</b>), lalu <b>Verifikasi &amp; Kunci</b> — Kasir baru bisa membuat tagihan sesuai obat final.</span>
      </div>

      <div class="disp-grid">
        <!-- Daftar resep perlu verifikasi -->
        <div class="rx-col">
          <div class="card">
            <div class="card-head">
              <div>
                <div class="card-head-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                  Verifikasi
                </div>
                <div class="card-head-sub">{{ filtVerQueue.length }} resep · {{ verPendingCount }} menunggu</div>
              </div>
              <span class="pill-live">LIVE</span>
            </div>

            <div class="card-body queue-scroll" role="region" aria-label="Daftar antrean verifikasi">
              <!-- Pisah jenis layanan: Rawat Jalan vs Rawat Inap (obat pulang) -->
              <div class="primary-filter" role="group" aria-label="Filter jenis layanan verifikasi">
                <button :class="['pf-btn', verServiceFilter === 'rajal' ? 'a' : '']" @click="verServiceFilter = 'rajal'">
                  Rawat Jalan
                  <span v-if="verRajalCount" class="pf-ct">{{ verRajalCount }}</span>
                </button>
                <button :class="['pf-btn', verServiceFilter === 'ranap' ? 'a' : '']" @click="verServiceFilter = 'ranap'" title="Obat pulang pasien rawat inap (diambil di loket)">
                  Rawat Inap
                  <span v-if="verRanapCount" class="pf-ct">{{ verRanapCount }}</span>
                </button>
              </div>
              <!-- Filter utama: belum vs sudah terkunci -->
              <div class="primary-filter" role="group" aria-label="Filter status verifikasi">
                <button :class="['pf-btn', verPrimaryFilter === 'belum' ? 'a' : '']" @click="verPrimaryFilter = 'belum'">
                  Perlu Verifikasi
                  <span v-if="verBelumGroupCount" class="pf-ct">{{ verBelumGroupCount }}</span>
                </button>
                <button :class="['pf-btn', verPrimaryFilter === 'selesai' ? 'a' : '']" @click="verPrimaryFilter = 'selesai'" title="Resep yang sudah diverifikasi & dikunci (belum dibayar)">
                  Terkunci
                  <span v-if="verSelesaiGroupCount" class="pf-ct">{{ verSelesaiGroupCount }}</span>
                </button>
              </div>
              <!-- Baris 1: cari + muat ulang -->
              <div class="ver-searchbar">
                <input v-model="verSearch" class="fi ver-search" placeholder="Cari nama / no. RM…" />
                <button class="btn btn-secondary btn-sm" :disabled="verLoading" title="Muat ulang" @click="fetchVerQueue">↻</button>
              </div>
              <!-- Baris 2: rentang tanggal (satu baris) -->
              <div class="ver-datebar">
                <label class="rp-date-lbl">Dari
                  <input v-model="verFrom" type="date" class="fi" />
                </label>
                <label class="rp-date-lbl">s/d
                  <input v-model="verTo" type="date" class="fi" />
                </label>
                <button v-if="verFrom || verTo" class="btn btn-secondary btn-sm" title="Hapus filter tanggal" @click="verClearDates">✕</button>
              </div>

              <div class="rx-list">
                <div v-if="verError" class="empty-rx">{{ verError }}</div>
                <div v-else-if="!verGroups.length" class="empty-rx">{{ (verFrom || verTo || verSearch) ? 'Tidak ada resep cocok dengan filter.' : (verPrimaryFilter === 'selesai' ? 'Belum ada resep terkunci.' : 'Tidak ada resep menunggu verifikasi.') }}</div>
                <!-- Satu kartu = satu PASIEN/visit; tiap resep (poli + pasca) jadi chip terpisah. -->
                <div v-for="g in verGroups" :key="g.vid"
                     :class="['rx-card', 'ver-group', g.items.some((rx) => verSel?.id === rx.id) ? 'active' : '', g.pendingCount === 0 ? 'done' : '']">
              <div class="rx-body">
                <div class="rx-top">
                  <div class="rx-name">{{ g.visit?.patient?.name ?? '—' }}</div>
                  <span v-if="g.items.some((rx) => rx.is_revision)" class="ver-badge revisi" title="Ada resep yang direvisi dokter setelah tagihan dibuat — verifikasi ulang">↻ Revisi</span>
                  <span :class="['ver-badge', g.pendingCount === 0 ? 'ok' : 'wait']">{{ g.pendingCount === 0 ? '🔒 Terkunci' : (g.items.length > 1 ? `${g.pendingCount}/${g.items.length} perlu verifikasi` : 'Perlu verifikasi') }}</span>
                </div>
                <div class="rx-meta">RM {{ g.visit?.patient?.no_rm ?? '—' }} · Lahir {{ fmtDateId(g.visit?.patient?.date_of_birth) }} · {{ (g.visit?.guarantor_type ?? 'UMUM').toUpperCase() }}</div>
                <div class="rx-meta" v-if="g.items.length">{{ verTgl(g.items[0]).label }}: {{ verTgl(g.items[0]).value }}</div>
                <div class="rx-meta" v-else>Tgl kunjungan: {{ fmtDateId(g.visit?.visit_date) }} · <b>BHP saja</b> (tanpa resep obat)</div>
                <div v-if="g.visit?.dpjp_name" class="rx-meta">DPJP: {{ g.visit.dpjp_name }}</div>
                <!-- Chip per resep: klik untuk pilih & lihat detail; pill sumber tetap tampil. -->
                <div class="ver-rx-chips">
                  <button v-for="rx in g.items" :key="rx.id" type="button"
                          :class="['ver-rx-chip', verSel?.id === rx.id ? 'active' : '', rx.verified_at ? 'locked' : '']"
                          @click="pickVer(rx)">
                    <span :class="verPillClass(rx.jenis_kode)">{{ rx.sumber ?? 'Rawat Jalan' }}</span>
                    <span class="ver-rx-chip-meta">{{ (rx.items?.length ?? 0) }} item · est {{ rp(rx.est_total) }}</span>
                    <span class="ver-rx-chip-stat">{{ rx.verified_at ? '🔒' : '•' }}</span>
                  </button>
                </div>
                <div v-if="g.pendingCount > 0 && g.items.length > 1" class="ver-group-actions">
                  <button class="btn btn-primary btn-sm" :disabled="verBusy" @click="verLockAll(g)">Verifikasi semua ({{ g.pendingCount }})</button>
                </div>

                <!-- BHP dipakai dokter (verifikasi per KUNJUNGAN; bisa koreksi qty/hapus
                     selama belum dikunci). Stok BHP dipotong nanti saat serah. -->
                <div v-if="g.bhp.length" class="ver-bhp">
                  <div class="ver-bhp-head">
                    <span>BHP dipakai dokter</span>
                    <span :class="['ver-badge', g.bhpPending ? 'wait' : 'ok']">{{ g.bhpPending ? `${g.bhpPending} perlu verifikasi` : '🔒 Terkunci' }}</span>
                  </div>
                  <div v-for="b in g.bhp" :key="b.id" class="ver-bhp-row">
                    <span class="ver-bhp-name">{{ b.bhp_item?.name ?? 'BHP' }}</span>
                    <template v-if="!b.verified_at">
                      <input :value="b.quantity" type="number" min="1" class="ver-bhp-qty" :disabled="verBusy"
                             title="Ubah jumlah BHP" @change="verUpdateBhp(b, $event.target.value)" />
                      <button class="ver-lnk danger" :disabled="verBusy" @click="verRemoveBhp(b)">Hapus</button>
                    </template>
                    <span v-else class="ver-bhp-locked">×{{ b.quantity }} 🔒</span>
                  </div>
                  <div class="ver-bhp-actions">
                    <button v-if="g.bhpPending" class="btn btn-success btn-sm" :disabled="verBusy" @click="verLockBhp(g)">
                      Verifikasi &amp; Kunci BHP ({{ g.bhpPending }})
                    </button>
                    <button v-else class="btn btn-secondary btn-sm" :disabled="verBusy" @click="verUnlockBhp(g)">Buka Kunci BHP</button>
                  </div>
                </div>
                </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Detail verifikasi -->
        <div class="disp-col">
          <div v-if="!verSel" class="disp-empty">
            <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
            <p>Pilih resep dari daftar untuk diverifikasi.</p>
          </div>
          <div v-else>
            <div class="dd-patient-row">
              <div>
                <div class="ddp-name">{{ verSel.visit?.patient?.name ?? '—' }}</div>
                <div class="rx-meta">RM {{ verSel.visit?.patient?.no_rm ?? '—' }} · Lahir {{ fmtDateId(verSel.visit?.patient?.date_of_birth) }} · {{ (verSel.visit?.guarantor_type ?? 'UMUM').toUpperCase() }}</div>
                <div class="rx-meta">{{ verTgl(verSel).label }}: {{ verTgl(verSel).value }}</div>
                <div class="rx-asal">
                  <span :class="verPillClass(verSel.jenis_kode)">{{ verSel.sumber ?? 'Rawat Jalan' }}</span>
                  <span v-if="verSel.visit?.dpjp_name" class="rx-dpjp" title="Dokter Penanggung Jawab Pelayanan">DPJP: {{ verSel.visit.dpjp_name }}</span>
                </div>
              </div>
              <span v-if="verSel.is_revision" class="ver-badge revisi" title="Resep direvisi dokter setelah tagihan dibuat — verifikasi ulang">↻ Revisi pasca-tagih</span>
              <span :class="['ver-badge', verSel.verified_at ? 'ok' : 'wait']">{{ verSel.verified_at ? '🔒 Terkunci' : 'Belum diverifikasi' }}</span>
            </div>

            <!-- Pemilih alasan perubahan (dipakai untuk substitusi/ubah/hapus/tambah) -->
            <div v-if="!verSel.verified_at" class="ver-reason">
              <label class="otc-label">Alasan perubahan (untuk substitusi / hapus / tambah)</label>
              <select v-model="verReason" class="fi">
                <option v-for="r in VER_REASONS" :key="r.v" :value="r.v">{{ r.t }}</option>
              </select>
            </div>

            <div class="sec-title">Item Obat</div>
            <div v-for="d in verSel.items" :key="d.id" class="dd">
              <div class="dd-info">
                <div class="dd-name">
                  {{ d.medication?.name ?? '—' }}
                  <span v-if="d.original_medication_id" class="otc-tag">substitusi</span>
                  <span v-if="d.source === 'TAMBAHAN'" class="otc-tag">tambahan</span>
                </div>
                <div class="dd-dose">{{ rxAturan(d) }}</div>
                <div :class="['dd-stock', itemStok(d) > 10 ? 'ok' : itemStok(d) > 0 ? 'low' : 'out']">
                  Stok: {{ itemStok(d) }} {{ d.medication?.unit ?? '' }} · est {{ rp(d.est_total_price) }}
                </div>
                <!-- Alasan hanya relevan utk obat yang benar disubstitusi/ditambah (deviasi
                     dari resep dokter). Untuk item biasa, sembunyikan agar cap alasan lama
                     (mis. STOK_HABIS dari ubah jumlah) tak menyesatkan. -->
                <div v-if="d.change_reason && (d.original_medication_id || d.source === 'TAMBAHAN')" class="dd-dose">Alasan: {{ d.change_reason }}</div>

                <!-- Picker substitusi -->
                <div v-if="!verSel.verified_at && verSubItem?.id === d.id" class="otc-picker" style="margin-top:.4rem">
                  <input v-model="verSubSearch" class="fi otc-input" placeholder="Cari obat pengganti (nama/generik/kode)…" autofocus />
                  <div class="otc-picker-drop" style="position:static;max-height:180px">
                    <div v-if="!verSubResults.length" class="otc-pick-empty">Tidak ada obat cocok</div>
                    <div v-for="m in verSubResults" :key="m.id" class="otc-picker-item" @mousedown.prevent="applySubstitute(m)">
                      <span class="otc-pi-name">{{ m.name }}</span>
                      <span class="otc-pi-meta"><span class="kategori-pill">{{ m.golongan }}</span><span>stok {{ m.stock }}</span></span>
                    </div>
                  </div>
                  <button class="btn btn-secondary btn-sm" style="margin-top:.3rem" @click="verSubItem = null">Batal substitusi</button>
                </div>
              </div>
              <div class="dd-qty-col">
                <!-- Varian kemasan jual (Strip/Box) — tampil bila obat punya kemasan -->
                <select
                  v-if="(d.available_sale_units?.length || d.sale_unit_id) && !d.is_bedah"
                  class="fi ver-kemasan-sel"
                  :value="d.sale_unit_id ?? ''"
                  :disabled="!!verSel.verified_at || verBusy"
                  @change="verPickKemasan(d, $event.target.value)"
                >
                  <option value="">Satuan ({{ d.medication?.unit ?? 'kecil' }})</option>
                  <option v-for="u in d.available_sale_units ?? []" :key="u.id" :value="u.id">
                    {{ u.label }} (isi {{ u.isi }}) — {{ rp(u.price) }}
                  </option>
                </select>

                <span class="dd-qty-label">{{ d.sale_unit_id ? 'Jml kemasan' : 'Jumlah' }}</span>
                <template v-if="d.sale_unit_id">
                  <input :value="d.sale_unit_qty" type="number" min="1" class="dd-qty" :disabled="!!verSel.verified_at || verBusy"
                         @change="verSetKemasanQty(d, $event.target.value)" />
                  <span class="dd-unit">{{ d.sale_unit?.label ?? 'kemasan' }}</span>
                  <span class="ver-kemasan-eq">= {{ d.quantity }} {{ d.medication?.unit ?? '' }}</span>
                </template>
                <template v-else>
                  <input :value="d.quantity" type="number" min="1" class="dd-qty" :disabled="!!verSel.verified_at || verBusy"
                         @change="verSetQty(d, $event.target.value)" />
                  <span class="dd-unit">{{ d.medication?.unit ?? '' }}</span>
                </template>
                <div v-if="!verSel.verified_at" class="ver-item-actions">
                  <button class="ver-lnk" :disabled="verBusy" @click="openSubstitute(d)">Substitusi</button>
                  <button class="ver-lnk danger" :disabled="verBusy" @click="verRemove(d)">Hapus</button>
                </div>
              </div>
            </div>
            <div v-if="!verSel.items?.length" class="empty-rx">Resep tidak punya item obat.</div>

            <!-- Tambah obat bebas saat verifikasi -->
            <div v-if="!verSel.verified_at" class="otc-section">
              <button class="btn btn-secondary btn-sm otc-toggle" @click="toggleVerAdd">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                {{ verAddOpen ? 'Tutup' : 'Tambah Obat (bebas)' }}
              </button>
              <div v-if="verAddOpen" class="otc-form" style="margin-top:.6rem">
                <div class="otc-fields">
                  <div class="otc-field otc-wide">
                    <label class="otc-label">Obat (bebas/bebas terbatas)</label>
                    <div v-if="verAddSelected" class="otc-picked">
                      <span><b>{{ verAddSelected.name }}</b> <span class="muted">({{ verAddSelected.golongan }})</span></span>
                      <button type="button" class="otc-picked-x" @click="verAddForm.medication_id = ''">✕</button>
                    </div>
                    <div v-else class="otc-picker">
                      <input v-model="verAddSearch" class="fi otc-input" placeholder="Ketik nama / generik / kode obat…" />
                      <div v-if="verAddSearch" class="otc-picker-drop" style="position:static;max-height:160px">
                        <div v-if="!verAddResults.length" class="otc-pick-empty">Tidak ada obat bebas cocok</div>
                        <div v-for="m in verAddResults" :key="m.id" class="otc-picker-item" @mousedown.prevent="verAddForm.medication_id = m.id; verAddSearch = ''">
                          <span class="otc-pi-name">{{ m.name }}</span>
                          <span class="otc-pi-meta"><span class="kategori-pill">{{ m.golongan }}</span><span>stok {{ m.stock }}</span></span>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="otc-field otc-narrow">
                    <label class="otc-label">Jumlah</label>
                    <input v-model.number="verAddForm.quantity" type="number" min="1" class="fi otc-input" />
                  </div>
                  <div class="otc-field">
                    <label class="otc-label">Aturan pakai</label>
                    <input v-model="verAddForm.instructions" class="fi otc-input" placeholder="mis. 3x/hari" />
                  </div>
                </div>
                <div class="otc-form-actions">
                  <button class="btn btn-success btn-sm" :disabled="verBusy" @click="verAddSubmit">Tambahkan</button>
                </div>
              </div>
            </div>

            <div v-if="verSel.pharmacy_note" class="doc-note"><b>Catatan untuk Farmasi:</b> {{ verSel.pharmacy_note }}</div>

            <div class="ver-summary">
              <span>Estimasi total obat ({{ (verSel.visit?.guarantor_type ?? 'UMUM').toUpperCase() }})</span>
              <b>{{ rp(verSelTotal) }}</b>
            </div>

            <div class="disp-actions">
              <button v-if="!verSel.verified_at" class="btn btn-success btn-lg" :disabled="verBusy" @click="verLock(verSel)">
                <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                Verifikasi &amp; Kunci
              </button>
              <template v-else>
                <span class="done-pill">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                  Terverifikasi — menunggu pembayaran di Kasir
                </span>
                <button class="btn btn-secondary btn-lg" :disabled="verBusy" @click="verUnlock(verSel)">Buka Kunci (koreksi)</button>
              </template>
              <!-- Cetak etiket langsung dari Verifikasi (dipindah dari Dispensing) -->
              <button class="btn btn-etiket btn-lg" :disabled="!(verSel.items ?? []).length" title="Cetak etiket obat (8×5 cm)" @click="printVerEtiket">
                <svg viewBox="0 0 24 24"><path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6z"/></svg>
                Cetak Etiket
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- DISPENSING RAWAT INAP -->
    <div v-if="pgTab === 'ranap'" class="tab-pane">
      <div class="loc-note">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Permintaan obat pasien <b>rawat inap</b> &amp; <b>obat pulang</b>. Siapkan lalu serah sesuai badge: <b>🛏️ Antar</b> = diantar ke kamar, <b>🏪 Ambil</b> = keluarga ambil di loket. Stok unit Farmasi dipotong saat serah; biaya obat harian masuk tagihan saat serah, obat pulang sudah ditagih di kasir.</span>
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
              <!-- Filter status: belum diserahkan vs selesai -->
              <div class="primary-filter" role="group" aria-label="Filter status dispensing rawat inap">
                <button :class="['pf-btn', ranapPrimaryFilter === 'belum' ? 'a' : '']" @click="ranapPrimaryFilter = 'belum'">
                  Belum Diserahkan
                  <span v-if="ranapBelumCount" class="pf-ct">{{ ranapBelumCount }}</span>
                </button>
                <button :class="['pf-btn', ranapPrimaryFilter === 'selesai' ? 'a' : '']" @click="ranapPrimaryFilter = 'selesai'" title="Obat yang sudah diserahkan hari ini">
                  Selesai
                  <span v-if="ranapSelesaiCount" class="pf-ct">{{ ranapSelesaiCount }}</span>
                </button>
              </div>
              <!-- Pencarian pasien -->
              <div class="ver-searchbar">
                <input v-model="ranapSearch" class="fi ver-search" placeholder="Cari nama / no. RM pasien…" />
                <button class="btn btn-secondary btn-sm" :disabled="ranapLoading" title="Muat ulang" @click="fetchRanapQueue">↻</button>
              </div>
              <div class="rx-list">
                <div v-if="ranapLoading && !ranapQueue.length" class="empty-rx">Memuat permintaan…</div>
                <!-- Kartu per PASIEN: 1 pasien bisa punya banyak permintaan obat -->
                <div v-for="g in ranapGroups" :key="g.vid"
                  :class="['rx-card', selRanapVisit === g.vid ? 'active' : '', g.pendingCount === 0 ? 'done' : '']"
                  @click="pickRanapGroup(g)">
                  <div :class="['rx-bar', g.pendingCount === 0 ? 'bar-done' : g.readyCount ? 'bar-disiapkan' : 'bar-menunggu']"></div>
                  <div class="rx-body">
                    <div class="rx-top">
                      <div class="rx-num">{{ ranapRoomLabel(g.requests[0]) }}</div>
                      <div class="rx-time">{{ g.requests.length }} order</div>
                    </div>
                    <div class="rx-name">{{ g.visit?.patient?.name ?? '—' }}</div>
                    <div class="rx-meta">RM {{ g.visit?.patient?.no_rm ?? '—' }} · Lahir {{ fmtDateId(g.visit?.patient?.date_of_birth) }} · {{ ranapPenjamin(g.requests[0]) }}</div>
                    <div v-if="g.visit?.dpjp_name" class="rx-meta">DPJP: {{ g.visit.dpjp_name }}</div>
                    <div class="rx-tags">
                      <span v-if="g.pendingCount" class="rxt rxt-menunggu">⏳ {{ g.pendingCount }} menunggu</span>
                      <span v-if="g.readyCount" class="rxt rxt-disiapkan">🔧 {{ g.readyCount }} siap serah</span>
                      <span v-if="g.doneCount" class="rxt rxt-done">✔ {{ g.doneCount }} diserahkan</span>
                    </div>
                  </div>
                </div>
                <div v-if="!ranapLoading && !ranapGroups.length" class="empty-rx">{{ ranapSearch ? 'Tidak ada pasien cocok.' : (ranapPrimaryFilter === 'selesai' ? 'Belum ada obat yang diserahkan.' : 'Tidak ada permintaan obat rawat inap.') }}</div>
              </div>
            </div>
          </div>

          <!-- BHP rawat inap (dipindah dari tab Verifikasi) — verifikasi dalam konteks ranap -->
          <div v-if="ranapBhpVisits.length" class="card" style="margin-top:12px">
            <div class="card-head">
              <div>
                <div class="card-head-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M12 11v4M10 13h4"/></svg>
                  BHP Rawat Inap
                </div>
                <div class="card-head-sub">{{ ranapBhpVisits.length }} pasien menunggu verifikasi</div>
              </div>
            </div>
            <div class="card-body">
              <div v-for="v in ranapBhpVisits" :key="v.id" class="bhp-ranap-card">
                <div class="bhp-ranap-head">
                  <div class="bhp-ranap-name">{{ v.patient?.name ?? '—' }}</div>
                  <div class="bhp-ranap-meta">{{ v.patient?.no_rm ?? '—' }} · BHP belum diverifikasi</div>
                </div>
                <div v-for="b in (v.bhp_usages ?? [])" :key="b.id" class="bhp-disp-row">
                  <div class="bhp-disp-info">
                    <div class="bhp-disp-name">{{ b.bhp_item?.name ?? 'BHP' }}</div>
                    <div class="bhp-disp-meta">{{ b.bhp_item?.code ?? '' }}<span v-if="b.bhp_item?.category"> · {{ b.bhp_item.category }}</span></div>
                  </div>
                  <div class="bhp-disp-qty">×{{ b.quantity }}</div>
                </div>
                <button class="btn btn-success btn-sm" style="width:100%;margin-top:8px" :disabled="ranapBhpBusy" @click="verifRanapBhp(v)">
                  <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                  {{ ranapBhpBusy ? 'Memproses…' : `Verifikasi & Kunci BHP (${(v.bhp_usages ?? []).length})` }}
                </button>
                <div class="bhp-disp-note">Stok dipotong &amp; biaya masuk tagihan saat pasien pulang.</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Detail / serah -->
        <div class="disp-col">
          <div v-if="!selRanapGroup" class="disp-empty">
            <svg viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/></svg>
            <p>Pilih pasien dari daftar untuk melihat &amp; melayani permintaan obatnya</p>
          </div>
          <div v-else class="disp-panel">
            <!-- Identitas pasien (sekali) + aksi massal serah -->
            <div class="disp-head">
              <div>
                <div class="disp-title">{{ selRanapGroup.visit?.patient?.name ?? '—' }}</div>
                <div class="disp-sub">RM {{ selRanapGroup.visit?.patient?.no_rm ?? '—' }} · Lahir {{ fmtDateId(selRanapGroup.visit?.patient?.date_of_birth) }} · {{ ranapPenjamin(selRanapGroup.requests[0]) }}</div>
                <div class="disp-sub">{{ ranapRoomLabel(selRanapGroup.requests[0]) }}<span v-if="selRanapGroup.visit?.dpjp_name"> · DPJP: {{ selRanapGroup.visit.dpjp_name }}</span></div>
              </div>
              <button v-if="selRanapGroup.readyCount > 1" class="btn btn-success btn-sm" :disabled="ranapBusy" @click="serahSemuaSiap" title="Serahkan semua permintaan yang sudah disiapkan ke ruangan">
                <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                Serah semua siap ({{ selRanapGroup.readyCount }})
              </button>
            </div>

            <!-- Switcher: semua permintaan obat pasien ini (menunggu dulu, lalu diserahkan) -->
            <div class="ranap-req-switch">
              <button v-for="r in selRanapGroup.requests" :key="r.id"
                :class="['rq-chip', selRanap && selRanap.id === r.id ? 'a' : '', 'rq-' + r.status.toLowerCase()]"
                @click="pickRanap(r)">
                <span class="rq-time">{{ formatTime(r.created_at) }}</span>
                <span class="rq-n">{{ r.items?.length ?? 0 }} obat</span>
                <span class="rq-st">{{ ranapStatusLabel(r.status) }}</span>
                <span v-if="ranapIsDischarge(r)" class="rq-pulang">pulang</span>
              </button>
            </div>

            <div v-if="!selRanap" class="empty-rx" style="margin-top:10px">Pilih permintaan di atas untuk menyiapkan / menyerahkan.</div>
            <!-- Detail permintaan terpilih (dipakai ulang: pengkajian, item, serah) -->
            <div v-else class="ranap-req-detail">
              <div class="req-subhead">
                <span class="req-subhead-t"><b>Permintaan {{ formatTime(selRanap.created_at) }}</b> · {{ selRanap.items?.length ?? 0 }} item · <b>{{ ranapModeLabel(selRanap) }}</b>{{ ranapIsDischarge(selRanap) ? ' · Obat Pulang' : '' }}</span>
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

            <!-- Pengkajian klinis: panel alergi pasien (allergy_notes + asesmen perawat) -->
            <div v-if="selRanapAllergy" class="allergy-banner">
              <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              <span><b>Alergi pasien:</b> {{ selRanapAllergy }}</span>
            </div>
            <div v-else class="allergy-banner ok">
              <span>Tidak ada catatan alergi pada profil pasien. Tetap konfirmasi lisan saat pengkajian.</span>
            </div>

            <div class="sec-title">Item Obat Diminta</div>
            <div v-for="(d, i) in selRanap.items" :key="d.id ?? i" :class="['dd', itemAllergyHit(d) ? 'dd-alert' : '']">
              <div class="dd-info">
                <div class="dd-name">
                  {{ d.medication?.name ?? '—' }}
                  <span v-if="itemAllergyHit(d)" class="dd-flag flag-allergy" title="Nama obat cocok dengan catatan alergi — telaah ulang">⚠ cocok alergi</span>
                  <span v-if="itemIsDup(d)" class="dd-flag flag-dup" title="Obat sama ada di permintaan ranap aktif lain">⧉ duplikasi</span>
                </div>
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
              <button v-if="ranapStep === 0" class="btn btn-warning btn-lg" @click="openPengkajian">
                <svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                Kaji &amp; Siapkan Obat
              </button>
              <button v-if="ranapStep === 1" class="btn btn-success btn-lg" :disabled="ranapBusy || !(selRanap.items ?? []).length" @click="serahRanap">
                <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                {{ ranapBusy ? 'Memproses…' : ranapSerahLabel(selRanap) }}
              </button>
              <span v-if="ranapStep === 2" class="done-pill">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                {{ ranapIsPickup(selRanap) ? 'Obat sudah diserahkan ke pengambil' : 'Obat sudah diserahkan ke ruangan' }}
              </span>
              <button v-if="ranapStep < 2" class="btn btn-secondary btn-sm" @click="tolakRanap">Batalkan</button>
            </div>
            </div><!-- /ranap-req-detail -->
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
      <div class="stok-kind-toggle">
        <button :class="['skt-btn', stokKind === 'obat' ? 'a' : '']" @click="stokKind = 'obat'">Obat</button>
        <button :class="['skt-btn', stokKind === 'bhp' ? 'a' : '']" @click="stokKind = 'bhp'">BHP (Bahan Habis Pakai)</button>
      </div>
      <div class="stok-head">
        <div class="stok-actions">
          <div class="stok-search">
            <svg viewBox="0 0 24 24" class="stok-search-ico"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input v-if="stokKind === 'obat'" v-model="stokSearch" class="fi stok-search-input" placeholder="Cari obat..." />
            <input v-else v-model="bhpSearch" class="fi stok-search-input" placeholder="Cari BHP (nama / kode)..." />
          </div>
          <UnitStockActions station="FARMASI" @changed="onUnitChanged" />

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
      <div v-if="stokKind === 'obat' && lowStockCount" class="low-alert">
        <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
        {{ lowStockCount }} item stok low/habis: {{ stokList.filter((s) => Number(s.stock) <= Number(s.min_stock ?? 0)).map((s) => s.name).join(', ') }}
      </div>
      <div v-if="stokKind === 'bhp' && bhpLowCount" class="low-alert">
        <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
        {{ bhpLowCount }} BHP stok low/habis: {{ bhpList.filter((b) => Number(b.stock) <= Number(b.min_stock ?? 0)).map((b) => b.name).join(', ') }}
      </div>

      <!-- Tabel stok OBAT -->
      <div v-if="stokKind === 'obat'" class="po-table-wrap">
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

      <!-- Tabel stok BHP -->
      <div v-else class="po-table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th style="width:48px" class="c">No.</th>
              <th>Nama BHP</th>
              <th>Kode</th>
              <th class="r">Stok</th>
              <th class="r">Min</th>
              <th>Unit</th>
              <th class="c">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="bhpLoading && !bhpList.length"><td colspan="7" class="po-state">Memuat stok BHP…</td></tr>
            <tr v-for="(b, i) in bhpPaged" :key="b.id ?? b.name">
              <td class="c muted">{{ (bhpPage - 1) * STOK_PER_PAGE + i + 1 }}</td>
              <td><strong>{{ b.name }}</strong></td>
              <td class="muted">{{ b.code ?? '—' }}</td>
              <td class="r">
                <div class="stok-cell">
                  <span :class="{ out: Number(b.stock) === 0, low: Number(b.stock) > 0 && Number(b.stock) <= Number(b.min_stock ?? 0) }">{{ b.stock }}</span>
                  <div class="bar"><div :class="['bar-fill', Number(b.stock) === 0 ? 'out' : Number(b.stock) <= Number(b.min_stock ?? 0) ? 'low' : 'ok']" :style="{ width: Math.min((Number(b.stock) / Math.max(Number(b.min_stock ?? 0) * 5, 1)) * 100, 100) + '%' }"></div></div>
                </div>
              </td>
              <td class="r muted">{{ b.min_stock ?? '—' }}</td>
              <td class="muted">{{ b.unit ?? '—' }}</td>
              <td class="c">
                <button class="po-icon-btn" title="Koreksi stok BHP" @click="openEditStok(b, 'bhp')">
                  <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                </button>
              </td>
            </tr>
            <tr v-if="!bhpLoading && !bhpFiltered.length"><td colspan="7" class="po-state">Tidak ada data stok BHP</td></tr>
          </tbody>
        </table>
        <Pager v-model:page="bhpPage" :last-page="bhpLastPage" :total="bhpFiltered.length" />
      </div>
    </div>

    <!-- STOK OPNAME -->
    <div v-if="pgTab === 'opname'" class="tab-pane">
      <div class="loc-note">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Opname terhadap <b>stok unit Farmasi</b>. Penyesuaian akan menyamakan stok sistem unit Farmasi dengan stok fisik di rak Farmasi (bukan gudang).</span>
      </div>
      <div class="stok-kind-toggle">
        <button :class="['skt-btn', opnameKind === 'obat' ? 'a' : '']" @click="opnameKind = 'obat'">Obat</button>
        <button :class="['skt-btn', opnameKind === 'bhp' ? 'a' : '']" @click="opnameKind = 'bhp'">BHP (Bahan Habis Pakai)</button>
      </div>
      <div class="opname-head">
        <div class="opname-stats">
          <div class="ostat"><span class="ostat-lbl">Item</span><b>{{ opnameStats.total }}</b></div>
          <div class="ostat"><span class="ostat-lbl">Ada Selisih</span><b :class="{ warn: opnameStats.changed }">{{ opnameStats.changed }}</b></div>
          <div class="ostat"><span class="ostat-lbl">Lebih</span><b class="plus">{{ opnameStats.plus }}</b></div>
          <div class="ostat"><span class="ostat-lbl">Kurang</span><b class="minus">{{ opnameStats.minus }}</b></div>
        </div>
        <div class="opname-actions">
          <input v-model="opnameSearch" class="fi" :placeholder="opnameKind === 'bhp' ? 'Cari BHP...' : 'Cari obat...'" style="width: 200px" />
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
        <span>Penjualan <b>obat bebas</b> untuk pembeli walk-in (tanpa resep dokter). Bayar langsung di apotek (<b>Bayar &amp; Cetak Struk</b>) atau kirim ke kasir (<b>Tagih ke Kasir</b> → pembayaran &amp; kwitansi di Kasir). Stok dipotong dari unit Farmasi saat transaksi dibuat.</span>
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
            <button class="btn btn-primary btn-lg" style="width:100%; justify-content:center; margin-top:.5rem"
              :disabled="posSaving || !posCart.length" @click="posTagihKasir" title="Kirim tagihan ke Kasir — pembayaran & kwitansi diterbitkan Kasir">
              <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
              {{ posSaving ? 'Memproses…' : 'Tagih ke Kasir' }}
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
                <th>Bayar</th><th>Waktu</th><th>Petugas</th><th class="c">Status</th><th class="c">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="posHistoryLoading && !posHistory.length"><td colspan="8" class="po-state">Memuat…</td></tr>
              <tr v-for="s in posHistory" :key="s.id" :class="{ 'pos-cancelled': s.status === 'CANCELLED' }">
                <td><strong>{{ s.sale_number }}</strong></td>
                <td>{{ s.buyer_name || '—' }}</td>
                <td class="r"><strong>{{ rp(s.total) }}</strong></td>
                <td class="muted">{{ s.status === 'PENDING' ? '— (di kasir)' : s.payment_method }}</td>
                <td class="muted">{{ fmtDateTime(s.created_at) }}</td>
                <td class="muted">{{ s.sold_by?.name || '—' }}</td>
                <td class="c">
                  <span v-if="s.status === 'CANCELLED'" class="lap-badge b-out">BATAL</span>
                  <span v-else-if="s.status === 'PENDING'" class="lap-badge b-mid" style="background:#fef3c7;color:#92400e">MENUNGGU KASIR</span>
                  <span v-else class="lap-badge b-low" style="background:var(--sb);color:var(--st)">PAID</span>
                </td>
                <td class="c">
                  <button v-if="s.status === 'PAID'" class="po-icon-btn" title="Cetak ulang struk" @click="posReprint(s.id)">
                    <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                  </button>
                  <button v-if="s.status !== 'CANCELLED'" class="po-icon-btn" title="Batalkan" @click="posCancel(s)">
                    <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </td>
              </tr>
              <tr v-if="!posHistoryLoading && !posHistory.length"><td colspan="8" class="po-state">Belum ada penjualan</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- RIWAYAT PEMBERIAN OBAT (global: resep + POS) -->
    <div v-if="pgTab === 'riwayat'" class="tab-pane">
      <div class="loc-note">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Riwayat <b>obat yang diberikan ke pasien</b> — dari resep yang sudah diserahkan (<b>rawat jalan, rawat inap, bedah, IGD</b>) dan penjualan obat bebas (POS). Saring per jenis pelayanan, pencarian &amp; rentang tanggal.</span>
      </div>

      <!-- Sub-tab jenis pelayanan -->
      <div class="rp-subtabs">
        <button v-for="o in RP_JENIS_OPTS" :key="o.val"
                :class="['rp-subtab', rpJenis === o.val ? 'a' : '']"
                @click="rpJenis = o.val">{{ o.label }}</button>
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
          <button class="btn btn-primary btn-sm" :disabled="rpExporting || rpLoading || !rpRows.length" @click="exportRiwayatPemberian">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            {{ rpExporting ? 'Mengekspor…' : 'Export Excel' }}
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
              <td><span :class="rpPillClass(r.jenis_kode)">{{ r.sumber }}</span></td>
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

      <!-- Catatan: riwayat "obat ini diberikan ke siapa" kini dilayani penuh oleh
           tab "Riwayat Pemberian" (cari per nama obat, tanggal & petugas akurat). -->
    </div>

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
            <div v-if="editStok.kind !== 'bhp'" class="es-field">
              <label>Batch</label>
              <input v-model="editStok.batch_number" class="es-input" placeholder="—" />
            </div>
            <div v-if="editStok.kind !== 'bhp'" class="es-field">
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

    <!-- Modal Pengkajian Resep Ranap (Permenkes 72/2016 · PKPO 5.1) -->
    <div v-if="showPengkajian" class="pk-overlay" @click.self="showPengkajian = false">
      <div class="pk-modal">
        <div class="pk-head">
          <div class="pk-title">Pengkajian Resep — {{ selRanap?.visit?.patient?.name ?? '—' }}</div>
          <button class="pk-x" @click="showPengkajian = false">×</button>
        </div>
        <div class="pk-body">
          <div v-if="selRanapAllergy" class="allergy-banner">
            <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span><b>Alergi pasien:</b> {{ selRanapAllergy }}</span>
          </div>
          <div v-if="ranapHasAllergyHit" class="pk-warn">⚠ Ada obat yang namanya cocok dengan catatan alergi — telaah ulang sebelum lanjut.</div>
          <div v-if="ranapHasDup" class="pk-warn">⧉ Ada obat duplikat di permintaan ranap aktif lain — pastikan bukan peresepan ganda.</div>

          <div class="pk-list">
            <div v-for="(d, i) in (selRanap?.items ?? [])" :key="d.id ?? i" class="pk-item">
              <span class="pk-item-name">{{ d.medication?.name ?? '—' }}</span>
              <span class="pk-item-dose">{{ d.dose ?? '-' }} · {{ d.frequency ?? '-' }}<span v-if="d.route"> · {{ d.route }}</span> · ×{{ d.quantity }}</span>
              <span v-if="itemAllergyHit(d)" class="dd-flag flag-allergy">⚠ alergi</span>
              <span v-if="itemIsDup(d)" class="dd-flag flag-dup">⧉ duplikasi</span>
            </div>
          </div>

          <div class="pk-checks">
            <div class="pk-checks-title">Konfirmasi telaah apoteker:</div>
            <label class="pk-check"><input type="checkbox" v-model="pengkajianChecks.adm" /> <b>Administratif</b> — identitas pasien, ruang rawat, penjamin, dokter</label>
            <label class="pk-check"><input type="checkbox" v-model="pengkajianChecks.farmasetik" /> <b>Farmasetik</b> — nama/bentuk/kekuatan/jumlah, stabilitas, inkompatibilitas</label>
            <label class="pk-check"><input type="checkbox" v-model="pengkajianChecks.klinis" /> <b>Klinis</b> — ketepatan dosis, duplikasi, alergi, interaksi, kontraindikasi</label>
          </div>
        </div>
        <div class="pk-foot">
          <button class="btn btn-secondary btn-sm" @click="showPengkajian = false">Batal</button>
          <button class="btn btn-warning btn-lg" :disabled="ranapBusy" @click="confirmPengkajianSiapkan">Sudah Dikaji — Siapkan Obat</button>
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

/* ── Verifikasi Farmasi ── */
.rx-filterbar { display: flex; gap: 6px; padding: 0 0 8px; }
.rx-filterbar .fi { flex: 1; }
/* Filter Verifikasi: baris-1 cari (penuh), baris-2 rentang tanggal */
.ver-searchbar { display: flex; align-items: center; gap: 6px; padding: 2px 0 6px; }
.ver-searchbar .ver-search { flex: 1 1 auto; }
.ver-datebar { display: flex; align-items: center; gap: 8px; flex-wrap: nowrap; padding: 0 0 10px; }
.ver-datebar .rp-date-lbl { flex: 1 1 0; min-width: 0; }
.ver-datebar .rp-date-lbl .fi { flex: 1 1 auto; width: auto; min-width: 0; }
.ver-datebar .btn-sm { flex: 0 0 auto; }
.ver-badge { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 20px; white-space: nowrap; }
.ver-badge.wait { background: #fef3c7; color: #92400e; }
.ver-badge.ok { background: #dcfce7; color: #166534; }
.ver-badge.revisi { background: #ffedd5; color: #9a3412; border: 1px solid #fdba74; margin-right: 4px; }
.dd-patient-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; padding-bottom: 10px; margin-bottom: 6px; border-bottom: 1px solid var(--gb); }
.ddp-name { font-size: 15px; font-weight: 700; color: var(--td); }
.ver-reason { margin: 8px 0 12px; }
.ver-reason .fi { width: 100%; }
.ver-item-actions { display: flex; gap: 8px; margin-top: 6px; justify-content: flex-end; }
.ver-lnk { background: none; border: none; padding: 2px 4px; font-size: 11.5px; font-weight: 600; color: var(--ga); cursor: pointer; }
.ver-lnk:hover { text-decoration: underline; }
.ver-lnk.danger { color: #dc2626; }
.ver-lnk:disabled { opacity: 0.5; cursor: default; }
.ver-summary { display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; margin: 12px 0; background: var(--bc); border: 1px solid var(--gb); border-radius: 9px; font-size: 13px; }
.ver-summary b { font-size: 15px; color: var(--td); }
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
/* Kartu grup-pasien di Verifikasi: bukan tombol tunggal — chip resep di dalamnya yang diklik. */
.rx-card.ver-group { cursor: default; }
.ver-rx-chips { display: flex; flex-direction: column; gap: 4px; margin-top: 6px; }
.ver-rx-chip { display: flex; align-items: center; gap: 6px; width: 100%; text-align: left; padding: 4px 6px; border: 1px solid var(--gb); border-radius: 7px; background: var(--bc); cursor: pointer; transition: all 0.12s; }
.ver-rx-chip:hover { border-color: var(--lm); }
.ver-rx-chip.active { border-color: var(--ga); background: var(--gl); }
.ver-rx-chip.locked { opacity: 0.6; }
.ver-rx-chip-meta { font-size: 10px; color: var(--tu); flex: 1; min-width: 0; }
.ver-rx-chip-stat { font-size: 11px; }
.ver-group-actions { margin-top: 7px; }
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
.q-visit-date { display: inline-flex; align-items: center; gap: 4px; margin-left: 6px; font-size: 10px; font-weight: 600; color: #0f766e; background: #ccfbf1; padding: 1px 6px; border-radius: 6px; white-space: nowrap; vertical-align: middle; }
.q-visit-date .pill-icon { width: 11px; height: 11px; flex: 0 0 auto; fill: none; stroke: currentColor; stroke-width: 2; }
.rx-tags { display: flex; gap: 3px; margin-top: 4px; flex-wrap: wrap; }
.rxt { font-size: 8.5px; font-weight: 700; padding: 1px 5px; border-radius: 4px; }
.rxt-b { background: #dbeafe; color: #1e40af; }
.rxt-u { background: var(--gl); color: var(--ga); }
.rxt-ranap { background: #e0e7ff; color: #3730a3; }
.rxt-deliver { background: #dcfce7; color: #166534; }
.rxt-pickup { background: #fef9c3; color: #854d0e; }
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

/* Switcher permintaan obat pasien — 1 pasien inap bisa order berkali-kali */
.ranap-req-switch { display: flex; flex-wrap: wrap; gap: 6px; padding: 10px 1.1rem 2px; background: var(--bc); }
.rq-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border: 1.5px solid var(--gb); border-radius: 8px; background: var(--bs); cursor: pointer; font-size: 11px; color: var(--td); font-family: 'Inter', sans-serif; transition: all .12s; }
.rq-chip:hover { border-color: var(--ga); }
.rq-chip.a { border-color: var(--gd); background: var(--gl); font-weight: 600; }
.rq-chip .rq-time { font-weight: 700; }
.rq-chip .rq-n { color: var(--tu); }
.rq-chip .rq-st { font-size: 9.5px; padding: 1px 6px; border-radius: 10px; text-transform: capitalize; }
.rq-chip.rq-submitted .rq-st { background: #fef3c7; color: #92400e; }
.rq-chip.rq-dispensing .rq-st { background: #ede9fe; color: #5b21b6; }
.rq-chip.rq-dispensed .rq-st { background: var(--sb); color: var(--st); }
.rq-chip.rq-dispensed { opacity: .7; }
.rq-pulang { font-size: 9px; background: #dcfce7; color: #166534; padding: 1px 5px; border-radius: 8px; }
/* Sub-header per-permintaan di dalam panel pasien */
.req-subhead { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 1.1rem; border-bottom: 1px solid var(--gb); }
.req-subhead-t { font-size: 12px; color: var(--td); }

/* Kartu identitas pasien: badge DPJP + tanggal lahir + alamat */
.ident-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 7px; }
.ident-row svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; flex-shrink: 0; }
.ident-dpjp, .ident-chip {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 11px; line-height: 1; padding: 3px 8px; border-radius: 999px;
  background: rgba(255, 255, 255, 0.16); color: #fff;
}
.ident-dpjp { background: rgba(255, 255, 255, 0.92); color: var(--gd); font-weight: 700; }
.ident-addr { max-width: 320px; }
.ident-addr :last-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Sub-judul per resep di panel dispensing (poli vs pasca) saat 1 visit >1 resep. */
.disp-rx-sub { display: flex; align-items: center; gap: 8px; margin: 10px 0 4px; padding: 0 2px; font-size: 11px; }
.disp-rx-sub .muted { color: var(--tu); }

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
/* Varian kemasan jual (verifikasi) */
.ver-kemasan-sel { width: 168px; height: 28px; font-size: 11px; padding: 0 6px; margin-bottom: 2px; }
.ver-kemasan-eq { font-size: 9.5px; color: var(--tm); font-weight: 600; }

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
.stok-kind-toggle { display: inline-flex; gap: 2px; padding: 3px; background: var(--bc); border: 1px solid var(--gb); border-radius: 9px; margin-bottom: 0.75rem; }
.skt-btn { border: none; background: none; padding: 6px 14px; font-size: 12.5px; font-weight: 600; color: var(--tu); border-radius: 7px; cursor: pointer; }
.skt-btn.a { background: var(--ga); color: #fff; }
/* Sub-tab jenis pelayanan pada Riwayat Pemberian. */
.rp-subtabs { display: inline-flex; gap: 2px; padding: 3px; background: var(--bc); border: 1px solid var(--gb); border-radius: 9px; margin-bottom: 0.75rem; flex-wrap: wrap; }
.rp-subtab { border: none; background: none; padding: 6px 14px; font-size: 12.5px; font-weight: 600; color: var(--tu); border-radius: 7px; cursor: pointer; }
.rp-subtab.a { background: var(--ga); color: #fff; }
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

/* Pill jenis pelayanan pada Riwayat Pemberian (warna per jenis_kode). */
.jp-pill { font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 999px; border: 1px solid transparent; white-space: nowrap; }
.jp-rajal { background: rgba(37, 99, 235, 0.12);  color: #1d4ed8; border-color: rgba(37, 99, 235, 0.28); }
.jp-ranap { background: rgba(124, 58, 237, 0.12); color: #6d28d9; border-color: rgba(124, 58, 237, 0.28); }
.jp-bedah { background: rgba(220, 38, 38, 0.12);  color: #b91c1c; border-color: rgba(220, 38, 38, 0.28); }
.jp-igd   { background: rgba(234, 88, 12, 0.14);  color: #c2410c; border-color: rgba(234, 88, 12, 0.30); }
.jp-pos   { background: rgba(5, 150, 105, 0.12);  color: #047857; border-color: rgba(5, 150, 105, 0.28); }
/* Asal resep gabungan: datang rawat jalan + dibedah hari yang sama. */
.jp-rajal_bedah { background: rgba(190, 24, 93, 0.12); color: #9d174d; border-color: rgba(190, 24, 93, 0.28); }
/* Instruksi obat pre-operasi dokter jaga (stat-dose Triase) — harus diberikan SEBELUM naik OT. */
.jp-pre_op { background: rgba(13, 148, 136, 0.12); color: #0f766e; border-color: rgba(13, 148, 136, 0.28); }
/* Obat diresepkan dokter POLIKLINIK (pada visit yang juga ada bedah/tindakan). */
.jp-poli { background: rgba(79, 70, 229, 0.12); color: #4338ca; border-color: rgba(79, 70, 229, 0.28); }
/* Obat PASCA TINDAKAN (laser/ruang tindakan) — bedakan dari Pasca Bedah (jp-bedah, merah). */
.jp-tindakan { background: rgba(202, 138, 4, 0.14); color: #a16207; border-color: rgba(202, 138, 4, 0.30); }

/* Badge asal + DPJP pada kartu/header antrean Verifikasi Farmasi. */
.rx-asal { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; margin-top: 4px; }
.rx-dpjp { font-size: 10px; font-weight: 600; color: var(--td); padding: 2px 8px; border-radius: 999px;
  background: var(--bs); border: 1px solid var(--gb); white-space: nowrap;
  max-width: 220px; overflow: hidden; text-overflow: ellipsis; }

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

/* ── BHP dipakai dokter (kartu dispensing) ── */
.bhp-disp-sec { margin-top: 1rem; padding-top: .85rem; border-top: 1px dashed var(--gb); }
.bhp-disp-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid var(--gb); border-radius: 10px; background: var(--bs); margin-bottom: 6px; }
.bhp-disp-info { flex: 1; min-width: 0; }
.bhp-disp-name { font-size: 13px; font-weight: 600; color: var(--td); }
.bhp-disp-meta { font-size: 11.5px; color: var(--tu); margin-top: 1px; }
.bhp-disp-qty { flex-shrink: 0; font-size: 13px; font-weight: 700; color: var(--td); }
.bhp-disp-note { font-size: 11.5px; color: var(--tm); margin-top: 4px; }
.bhp-only-card { width: 100%; max-width: 440px; text-align: left; margin-bottom: 1.1rem; }
.bhp-only-head { margin-bottom: .6rem; }
.bhp-only-serah { width: 100%; margin-top: .8rem; justify-content: center; }
.bhp-only-sep { display: flex; align-items: center; gap: 10px; margin: 1.1rem 0 .2rem; color: var(--tu); font-size: 11.5px; }
.bhp-only-sep::before, .bhp-only-sep::after { content: ''; flex: 1; height: 1px; background: var(--gb); }

/* ── BHP di tab Verifikasi (inline kartu worklist) ── */
.ver-bhp { margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--gb); }
.ver-bhp-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11.5px; font-weight: 700; color: var(--td); margin-bottom: 5px; }
.ver-bhp-row { display: flex; align-items: center; gap: 8px; padding: 3px 0; }
.ver-bhp-name { flex: 1; min-width: 0; font-size: 12px; color: var(--td); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ver-bhp-qty { width: 56px; padding: 2px 6px; border: 1px solid var(--gb); border-radius: 6px; font-size: 12px; text-align: center; }
.ver-bhp-locked { font-size: 12px; font-weight: 700; color: var(--tm); white-space: nowrap; }
.ver-bhp-actions { margin-top: 6px; }

/* ── Pengkajian resep ranap: banner alergi, flag item, BHP ranap, modal ── */
.allergy-banner { display: flex; align-items: flex-start; gap: 8px; margin: 8px 0; padding: 8px 10px; border-radius: 8px; font-size: 12.5px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
.allergy-banner svg { width: 16px; height: 16px; flex: none; margin-top: 1px; fill: none; stroke: currentColor; stroke-width: 2; }
.allergy-banner.ok { background: #f1f5f9; border-color: #e2e8f0; color: var(--tm); }
.dd-alert { border-color: #fecaca !important; background: #fef2f2; }
.dd-flag { display: inline-block; margin-left: 6px; padding: 1px 6px; border-radius: 6px; font-size: 10.5px; font-weight: 700; vertical-align: middle; }
.flag-allergy { background: #fee2e2; color: #b91c1c; }
.flag-dup { background: #fef3c7; color: #92400e; }
.bhp-ranap-card { padding: 10px; border: 1px solid var(--gb); border-radius: 10px; margin-bottom: 8px; background: #fff; }
.bhp-ranap-head { margin-bottom: 6px; }
.bhp-ranap-name { font-size: 13px; font-weight: 700; color: var(--td); }
.bhp-ranap-meta { font-size: 11.5px; color: var(--tm); }

.pk-overlay { position: fixed; inset: 0; z-index: 9000; background: rgba(15,23,42,.5); display: flex; align-items: center; justify-content: center; padding: 16px; }
.pk-modal { width: 100%; max-width: 560px; max-height: 88vh; display: flex; flex-direction: column; background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,.3); }
.pk-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--gb); }
.pk-title { font-size: 15px; font-weight: 800; color: var(--td); }
.pk-x { border: none; background: none; font-size: 24px; line-height: 1; color: var(--tm); cursor: pointer; }
.pk-body { padding: 14px 16px; overflow-y: auto; }
.pk-warn { margin: 6px 0; padding: 7px 10px; border-radius: 8px; font-size: 12px; font-weight: 600; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
.pk-list { margin: 10px 0; border: 1px solid var(--gb); border-radius: 8px; overflow: hidden; }
.pk-item { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 7px 10px; border-bottom: 1px solid var(--gl, #f1f5f9); }
.pk-item:last-child { border-bottom: none; }
.pk-item-name { font-size: 12.5px; font-weight: 700; color: var(--td); }
.pk-item-dose { font-size: 11.5px; color: var(--tm); }
.pk-checks { margin-top: 12px; }
.pk-checks-title { font-size: 12.5px; font-weight: 700; color: var(--td); margin-bottom: 6px; }
.pk-check { display: block; font-size: 12.5px; color: var(--td); padding: 6px 0; cursor: pointer; }
.pk-check input { margin-right: 6px; }
.pk-foot { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 12px 16px; border-top: 1px solid var(--gb); }
</style>
