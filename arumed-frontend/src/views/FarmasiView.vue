<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { farmasiApi, unitRequestApi, unitReturnApi } from '@/services/api'
import RequestObatModal from '@/components/farmasi/RequestObatModal.vue'
import ReturObatModal from '@/components/farmasi/ReturObatModal.vue'

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

async function pickRx(q) {
  selQ.value  = q
  selRx.value = null

  const stub = pickActiveRx(q.visit?.prescriptions)
  if (!stub) { toast('i', 'Pasien belum punya resep.'); return }

  selRxLoading.value = true
  try {
    const { data } = await farmasiApi.showResep(stub.id)
    selRx.value = data.data
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat resep')
  } finally {
    selRxLoading.value = false
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

function verifikasiRx() {
  if (!selRx.value) return
  verifiedRxIds.value = new Set([...verifiedRxIds.value, selRx.value.id])
  toast('s', 'Resep diverifikasi')
}

async function siapkanRx() {
  if (!selRx.value) return
  try {
    const { data } = await farmasiApi.startDispensing(selRx.value.id)
    selRx.value = data.data
    refreshQueueForRx(data.data)
    toast('s', 'Obat disiapkan, cek kembali sebelum diserahkan')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal mulai dispensing')
  }
}

async function serahkanRx() {
  if (!selRx.value) return
  if (!(selRx.value.items ?? []).every((d) => d.checked)) {
    toast('w', 'Cek semua item terlebih dahulu'); return
  }
  try {
    const { data } = await farmasiApi.selesaiDispensing(selRx.value.id)
    selRx.value = data.data
    refreshQueueForRx(data.data)

    // Selesaikan antrean farmasi → pasien PULANG.
    if (selQ.value?.id) {
      try {
        const { data: qData } = await farmasiApi.selesaiAntrian(selQ.value.id)
        const updated = qData.data?.queue ?? qData.data
        if (updated?.id) Object.assign(selQ.value, updated)
      } catch { /* ignore — resep sudah DISPENSED */ }
    }
    toast('s', 'Obat diserahkan ke pasien')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyelesaikan dispensing')
  }
}

function refreshQueueForRx(rx) {
  if (!rx || !selQ.value) return
  const prescriptions = selQ.value.visit?.prescriptions ?? []
  const idx = prescriptions.findIndex((p) => p.id === rx.id)
  if (idx !== -1) prescriptions[idx] = { ...prescriptions[idx], status: rx.status }
}

// ─── Stok Obat ──────────────────────────────────────────────────────────────
const stokList     = ref([])
const stokSearch   = ref('')
const stokLoading  = ref(false)

async function fetchStok() {
  stokLoading.value = true
  try {
    const { data } = await farmasiApi.stokObat({ per_page: 200 })
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
const lowStockCount = computed(
  () => stokList.value.filter((s) => Number(s.stock) <= Number(s.min_stock ?? 0)).length,
)
const outStockCount = computed(
  () => stokList.value.filter((s) => Number(s.stock) === 0).length,
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

const opnameFiltered = computed(() => {
  const q = opnameSearch.value.toLowerCase()
  return q ? opnameRows.value.filter((r) => (r.name ?? '').toLowerCase().includes(q)) : opnameRows.value
})
const opnameChanged = computed(() => opnameRows.value.filter((r) => Number(r.fisik) !== r.system))
const opnameStats = computed(() => {
  const changed = opnameChanged.value
  return {
    total:   opnameRows.value.length,
    changed: changed.length,
    plus:    changed.filter((r) => Number(r.fisik) > r.system).length,
    minus:   changed.filter((r) => Number(r.fisik) < r.system).length,
  }
})

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
    try {
      await farmasiApi.updateStokObat(r.id, { stock: Number(r.fisik) })
      ok++
    } catch { fail++ }
  }
  opnameSaving.value = false
  toast(fail ? 'w' : 's', `Penyesuaian selesai: ${ok} berhasil${fail ? `, ${fail} gagal` : ''}`)
  await fetchStok()
  loadOpname()
}

// Muat data opname saat tab dibuka (sekali, kalau belum ada)
watch(() => pgTab.value, (t) => { if (t === 'opname' && !opnameRows.value.length) loadOpname() })

// ─── Laporan Farmasi (derivasi dari stok) ────────────────────────────────────
function rp(n) { return 'Rp ' + Number(n || 0).toLocaleString('id-ID') }
function daysToExpiry(d) { return Math.ceil((new Date(d).getTime() - Date.now()) / 86_400_000) }

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

onMounted(() => {
  fetchQueue()
  fetchStok()
  _poll = setInterval(fetchQueue, 8_000)
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
        Dispensing Resep
        <span class="ntbg alert">{{ queue.filter((q) => rxStatusOf(q) !== 'done').length }}</span>
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
        <div :class="['stat-card', outStockCount ? 'alert-card' : '']">
          <div class="stat-icon" :style="{ background: outStockCount ? 'var(--eb)' : 'var(--gl)' }">
            <svg viewBox="0 0 24 24" :stroke="outStockCount ? 'var(--et)' : 'var(--ga)'"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
          </div>
          <div><div class="stat-val" :style="{ color: outStockCount ? 'var(--et)' : '' }">{{ lowStockCount }}</div><div class="stat-lbl">Stok Low/Habis</div></div>
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
            <p>Resep tidak ditemukan untuk pasien ini.</p>
          </div>
          <div v-else class="disp-panel">
            <div class="disp-head">
              <div>
                <div class="disp-title">{{ selQ.visit?.patient?.name ?? '—' }} — {{ selQ.queue_number }}</div>
                <div class="disp-sub">{{ selQ.visit?.patient?.no_rm ?? '—' }} · dr. {{ selRx.prescribed_by?.name ?? '—' }} · {{ selRx.items?.length ?? 0 }} item</div>
              </div>
              <button class="btn btn-secondary btn-sm" @click="toast('i', 'Cetak etiket obat')">
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
            <div v-for="(d, i) in selRx.items" :key="d.id ?? i" class="dd">
              <input type="checkbox" v-model="d.checked" />
              <div class="dd-info">
                <div class="dd-name">{{ d.medication?.name ?? '—' }}</div>
                <div class="dd-dose">{{ d.dosage ?? '-' }} · {{ d.instructions ?? '-' }}</div>
                <div :class="['dd-stock', Number(d.medication?.stock ?? 0) > 10 ? 'ok' : Number(d.medication?.stock ?? 0) > 0 ? 'low' : 'out']">
                  Stok: {{ d.medication?.stock ?? 0 }} {{ d.medication?.unit ?? '' }}{{ Number(d.medication?.stock ?? 0) === 0 ? ' — HABIS' : Number(d.medication?.stock ?? 0) <= 3 ? ' — LOW' : '' }}
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

            <div v-if="selRx.notes" class="doc-note"><b>Catatan Dokter:</b> {{ selRx.notes }}</div>

            <div class="disp-actions">
              <button v-if="dispStep === 0" class="btn btn-info btn-lg" @click="verifikasiRx">
                <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/></svg>
                Verifikasi Resep
              </button>
              <button v-if="dispStep === 1" class="btn btn-warning btn-lg" @click="siapkanRx">
                <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/></svg>
                Siapkan Obat
              </button>
              <button v-if="dispStep === 2" class="btn btn-success btn-lg" :disabled="!(selRx.items ?? []).length || !(selRx.items ?? []).every((d) => d.checked)" @click="serahkanRx">
                <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                Serahkan ke Pasien
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
            <tr v-for="(s, i) in stokFiltered" :key="s.id ?? s.name">
              <td class="c muted">{{ i + 1 }}</td>
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
            <tr v-for="(r, i) in opnameFiltered" :key="r.id"
              :class="{ 'op-diff': Number(r.fisik) !== r.system }">
              <td class="c muted">{{ i + 1 }}</td>
              <td><strong>{{ r.name }}</strong></td>
              <td><span class="kategori-pill">{{ r.formularium || '—' }}</span></td>
              <td class="muted">{{ r.unit || '—' }}</td>
              <td class="r">{{ r.system }}</td>
              <td class="r"><input v-model.number="r.fisik" type="number" min="0" class="op-input" /></td>
              <td class="r">
                <span :class="['op-sel', Number(r.fisik) - r.system > 0 ? 'plus' : Number(r.fisik) - r.system < 0 ? 'minus' : '']">
                  {{ Number(r.fisik) - r.system > 0 ? '+' : '' }}{{ Number(r.fisik) - r.system }}
                </span>
              </td>
            </tr>
            <tr v-if="opnameRows.length && !opnameFiltered.length"><td colspan="7" class="po-state">Tidak ada obat cocok pencarian</td></tr>
          </tbody>
        </table>
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
.otc-form-actions { display: flex; gap: .4rem; }

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
