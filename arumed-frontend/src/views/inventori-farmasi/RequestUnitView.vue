<script setup>
/**
 * RequestUnitView — Inbox admin inventori untuk Request & Retur dari unit klinik.
 *
 * Admin TIDAK membuat request/retur — itu dilakukan oleh user di sisi unit (Admisi/
 * Triase/Bedah/dll) via view terpisah. Di sini admin hanya VERIFIKASI & PROSES.
 *
 * 2 tab:
 *  - PERMINTAAN: Setujui/Tolak (SUBMITTED → APPROVED/REJECTED) lalu Kirim
 *                (APPROVED → DELIVERED, stok berkurang).
 *  - RETUR    : Terima (SUBMITTED → RECEIVED, stok bertambah) atau Tolak.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { unitRequestApi, unitReturnApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'

const auth = useAuthStore()

const STATIONS = ['ADMISI','TRIASE','REFRAKSIONIS','DOKTER','PENUNJANG','BEDAH','KASIR','FARMASI']

const REQ_STATUS_BADGE = {
  DRAFT:     { label: 'Draft (Unit)', cls: 'b-draft' },
  SUBMITTED: { label: 'Menunggu',     cls: 'b-sent' },
  APPROVED:  { label: 'Disetujui',    cls: 'b-info' },
  DELIVERED: { label: 'Dikirim',      cls: 'b-ok' },
  CLOSED:    { label: 'Ditutup',      cls: 'b-muted' },
  REJECTED:  { label: 'Ditolak',      cls: 'b-err' },
}
const RET_STATUS_BADGE = {
  DRAFT:     { label: 'Draft (Unit)', cls: 'b-draft' },
  SUBMITTED: { label: 'Menunggu',     cls: 'b-sent' },
  RECEIVED:  { label: 'Diterima',     cls: 'b-ok' },
  REJECTED:  { label: 'Ditolak',      cls: 'b-err' },
}

const TABS = [
  { key: 'PERMINTAAN', label: 'Permintaan dari Unit' },
  { key: 'PENDING',    label: 'Pending Pengiriman' },
  { key: 'RETUR',      label: 'Retur dari Unit' },
  { key: 'HISTORY',    label: 'History' },
]
const HISTORY_SUBS = [
  { key: 'REQUEST', label: 'History Request' },
  { key: 'RETURN',  label: 'History Retur' },
]
const activeTab = ref('PERMINTAAN')
const historySub = ref('REQUEST')

function switchTab(k) {
  if (activeTab.value === k) return
  activeTab.value = k
  if (k === 'PERMINTAAN' && !reqList.value.length) refreshReq()
  if (k === 'PENDING'    && !pendingList.value.length) refreshPending()
  if (k === 'RETUR'      && !retList.value.length) refreshRet()
  if (k === 'HISTORY') {
    if (historySub.value === 'REQUEST' && !histReqList.value.length) refreshHistReq()
    if (historySub.value === 'RETURN'  && !histRetList.value.length) refreshHistRet()
  }
}

function switchHistorySub(k) {
  if (historySub.value === k) return
  historySub.value = k
  if (k === 'REQUEST' && !histReqList.value.length) refreshHistReq()
  if (k === 'RETURN'  && !histRetList.value.length) refreshHistRet()
}

const formatDate = (v) => v ? new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

// ─── Toast ───────────────────────────────────────────────────────────────
const toast = ref(null)
function showToast(type, msg) {
  toast.value = { type, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

// =========================================================================
// PERMINTAAN — list + detail + setujui/tolak/kirim
// =========================================================================
const reqList = ref([])
const reqMeta = ref({ current_page: 1, last_page: 1, total: 0 })
const reqLoading = ref(false)
const reqFilters = ref({ search: '', station: '', status: 'SUBMITTED' })

async function refreshReq(page = 1) {
  reqLoading.value = true
  try {
    const params = { page, per_page: 25 }
    if (reqFilters.value.search)  params.search  = reqFilters.value.search
    if (reqFilters.value.station) params.station = reqFilters.value.station
    if (reqFilters.value.status)  params.status  = reqFilters.value.status
    const res = await unitRequestApi.list(params)
    const p = res.data?.data
    if (p && Array.isArray(p.data)) {
      reqList.value = p.data
      reqMeta.value = { current_page: p.current_page ?? 1, last_page: p.last_page ?? 1, total: p.total ?? p.data.length }
    } else reqList.value = []
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal memuat permintaan')
  } finally {
    reqLoading.value = false
  }
}

// Modal Detail (read-only)
const detailReq = ref({ open: false, loading: false, data: null })

async function openReqDetail(row) {
  detailReq.value = { open: true, loading: true, data: null }
  try {
    const res = await unitRequestApi.show(row.id)
    detailReq.value.data = res.data?.data
  } catch (e) {
    showToast('e', 'Gagal memuat detail')
    detailReq.value.open = false
  } finally {
    detailReq.value.loading = false
  }
}

async function doReqAction(row, action) {
  const map = {
    approve: { fn: () => unitRequestApi.approve(row.id), msg: 'Permintaan disetujui', confirm: 'Setujui permintaan ini?' },
    reject:  { fn: () => unitRequestApi.reject(row.id, prompt('Alasan tolak:') || ''), msg: 'Permintaan ditolak', confirm: null },
    close:   { fn: () => unitRequestApi.close(row.id),   msg: 'Permintaan ditutup',   confirm: 'Tutup permintaan?' },
  }
  const op = map[action]
  if (op.confirm && !confirm(op.confirm)) return
  try {
    await op.fn()
    showToast('s', op.msg)
    if (activeTab.value === 'PENDING') refreshPending(pendingMeta.value.current_page)
    else refreshReq(reqMeta.value.current_page)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal')
  }
}

// Modal Kirim (deliver) — admin set qty & batch, stok berkurang
const deliverModal = ref({ open: false, submitting: false, request: null })

async function openDeliver(row) {
  try {
    const res = await unitRequestApi.show(row.id)
    const r = res.data?.data
    deliverModal.value = {
      open: true,
      submitting: false,
      request: {
        ...r,
        items: r.items.map(it => ({
          ...it,
          _qty_deliver: it.qty_requested,
        })),
      },
    }
  } catch (e) { showToast('e', 'Gagal load detail') }
}

async function submitDeliver() {
  const r = deliverModal.value.request
  const payload = {
    items: r.items.map(it => ({
      id: it.id,
      qty_delivered: Number(it._qty_deliver),
    })),
  }
  deliverModal.value.submitting = true
  try {
    await unitRequestApi.deliver(r.id, payload)
    showToast('s', 'Pengiriman tercatat, stok dikurangi')
    deliverModal.value.open = false
    if (activeTab.value === 'PENDING') refreshPending(pendingMeta.value.current_page)
    else refreshReq(reqMeta.value.current_page)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal kirim')
  } finally {
    deliverModal.value.submitting = false
  }
}

// =========================================================================
// PENDING — request yang sudah APPROVED tapi belum DELIVERED
// =========================================================================
const pendingList = ref([])
const pendingMeta = ref({ current_page: 1, last_page: 1, total: 0 })
const pendingLoading = ref(false)
const pendingFilters = ref({ search: '', station: '' })

async function refreshPending(page = 1) {
  pendingLoading.value = true
  try {
    const params = { page, per_page: 25, status: 'APPROVED' }
    if (pendingFilters.value.search)  params.search  = pendingFilters.value.search
    if (pendingFilters.value.station) params.station = pendingFilters.value.station
    const res = await unitRequestApi.list(params)
    const p = res.data?.data
    if (p && Array.isArray(p.data)) {
      pendingList.value = p.data
      pendingMeta.value = { current_page: p.current_page ?? 1, last_page: p.last_page ?? 1, total: p.total ?? p.data.length }
    } else pendingList.value = []
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal memuat pending')
  } finally {
    pendingLoading.value = false
  }
}

// =========================================================================
// RETUR — list + detail + terima/tolak
// =========================================================================
const retList = ref([])
const retMeta = ref({ current_page: 1, last_page: 1, total: 0 })
const retLoading = ref(false)
const retFilters = ref({ search: '', station: '', status: 'SUBMITTED' })

async function refreshRet(page = 1) {
  retLoading.value = true
  try {
    const params = { page, per_page: 25 }
    if (retFilters.value.search)  params.search  = retFilters.value.search
    if (retFilters.value.station) params.station = retFilters.value.station
    if (retFilters.value.status)  params.status  = retFilters.value.status
    const res = await unitReturnApi.list(params)
    const p = res.data?.data
    if (p && Array.isArray(p.data)) {
      retList.value = p.data
      retMeta.value = { current_page: p.current_page ?? 1, last_page: p.last_page ?? 1, total: p.total ?? p.data.length }
    } else retList.value = []
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal memuat retur')
  } finally {
    retLoading.value = false
  }
}

const detailRet = ref({ open: false, loading: false, data: null })

async function openRetDetail(row) {
  detailRet.value = { open: true, loading: true, data: null }
  try {
    const res = await unitReturnApi.show(row.id)
    detailRet.value.data = res.data?.data
  } catch (e) {
    showToast('e', 'Gagal memuat detail')
    detailRet.value.open = false
  } finally {
    detailRet.value.loading = false
  }
}

async function doRetAction(row, action) {
  const map = {
    receive: { fn: () => unitReturnApi.receive(row.id), msg: 'Retur diterima & stok kembali ke inventori', confirm: 'Terima retur? Stok akan otomatis bertambah di inventori.' },
    reject:  { fn: () => unitReturnApi.reject(row.id, prompt('Alasan tolak:') || ''), msg: 'Retur ditolak', confirm: null },
  }
  const op = map[action]
  if (op.confirm && !confirm(op.confirm)) return
  try {
    await op.fn()
    showToast('s', op.msg)
    refreshRet(retMeta.value.current_page)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal')
  }
}

// =========================================================================
// HISTORY — aksi yang sudah diproses (status != SUBMITTED)
// =========================================================================
const HIST_REQ_STATUSES = ['DELIVERED','CLOSED','REJECTED']
const HIST_RET_STATUSES = ['RECEIVED','REJECTED']

const histReqList = ref([])
const histReqMeta = ref({ current_page: 1, last_page: 1, total: 0 })
const histReqLoading = ref(false)
const histReqFilters = ref({ search: '', station: '', status: '' })

async function refreshHistReq(page = 1) {
  histReqLoading.value = true
  try {
    const params = { page, per_page: 25 }
    if (histReqFilters.value.search)  params.search  = histReqFilters.value.search
    if (histReqFilters.value.station) params.station = histReqFilters.value.station
    if (histReqFilters.value.status) {
      params.status = histReqFilters.value.status
    }
    const res = await unitRequestApi.list(params)
    const p = res.data?.data
    let rows = Array.isArray(p?.data) ? p.data : []
    if (!histReqFilters.value.status) {
      rows = rows.filter(r => HIST_REQ_STATUSES.includes(r.status))
    }
    histReqList.value = rows
    histReqMeta.value = { current_page: p?.current_page ?? 1, last_page: p?.last_page ?? 1, total: p?.total ?? rows.length }
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal memuat history')
  } finally {
    histReqLoading.value = false
  }
}

const histRetList = ref([])
const histRetMeta = ref({ current_page: 1, last_page: 1, total: 0 })
const histRetLoading = ref(false)
const histRetFilters = ref({ search: '', station: '', status: '' })

async function refreshHistRet(page = 1) {
  histRetLoading.value = true
  try {
    const params = { page, per_page: 25 }
    if (histRetFilters.value.search)  params.search  = histRetFilters.value.search
    if (histRetFilters.value.station) params.station = histRetFilters.value.station
    if (histRetFilters.value.status) {
      params.status = histRetFilters.value.status
    }
    const res = await unitReturnApi.list(params)
    const p = res.data?.data
    let rows = Array.isArray(p?.data) ? p.data : []
    if (!histRetFilters.value.status) {
      rows = rows.filter(r => HIST_RET_STATUSES.includes(r.status))
    }
    histRetList.value = rows
    histRetMeta.value = { current_page: p?.current_page ?? 1, last_page: p?.last_page ?? 1, total: p?.total ?? rows.length }
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal memuat history')
  } finally {
    histRetLoading.value = false
  }
}

// =========================================================================
// PERMISSIONS
// =========================================================================
const canVerify = computed(() => auth.can('request_unit.write'))

const itemTypeLabel = (t) => ({ MEDICATION: 'Obat', BHP: 'BHP', IOL: 'IOL' }[t] ?? t)
const conditionLabel = (c) => ({ GOOD: 'Baik', DAMAGED: 'Rusak', EXPIRED: 'Expired', NEAR_EXPIRY: 'Hampir Expired' }[c] ?? '—')

// Listener event dari InventoriFarmasiLayout bell — switch tab sesuai jenis notif
function onInboxOpen(e) {
  const item = e.detail
  if (!item) return
  if (item.kind === 'RETURN') {
    activeTab.value = 'RETUR'
    refreshRet()
  } else {
    activeTab.value = 'PERMINTAAN'
    refreshReq()
  }
}

onMounted(() => {
  refreshReq()
  window.addEventListener('arumed:inventori-inbox-open', onInboxOpen)
})

onBeforeUnmount(() => {
  window.removeEventListener('arumed:inventori-inbox-open', onInboxOpen)
})
</script>

<template>
  <div class="ru-wrap">
    <header class="ru-head">
      <div>
        <h2>Request &amp; Retur dari Unit</h2>
        <p class="ru-sub">Inbox admin inventori — verifikasi &amp; proses permintaan / pengembalian stok dari unit klinik.</p>
      </div>
    </header>

    <nav class="ru-tabs">
      <button
        v-for="t in TABS" :key="t.key"
        class="ru-tab" :class="{ active: activeTab === t.key }"
        @click="switchTab(t.key)"
      >{{ t.label }}</button>
    </nav>

    <!-- =================================================================
         TAB PERMINTAAN
         ================================================================= -->
    <section v-show="activeTab === 'PERMINTAAN'" class="ru-panel">
      <div class="ru-toolbar">
        <input v-model="reqFilters.search" placeholder="Cari nomor request / catatan…" class="ru-inp" @input="refreshReq(1)" />
        <select v-model="reqFilters.station" class="ru-inp" @change="refreshReq(1)">
          <option value="">Semua Stasiun</option>
          <option v-for="s in STATIONS" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="reqFilters.status" class="ru-inp" @change="refreshReq(1)">
          <option value="">Semua Status</option>
          <option v-for="(b, k) in REQ_STATUS_BADGE" :key="k" :value="k">{{ b.label }}</option>
        </select>
      </div>

      <div class="ru-table-wrap">
        <table class="ru-table">
          <thead>
            <tr>
              <th class="c" style="width: 48px;">No</th>
              <th>No. Request</th>
              <th>Tanggal</th>
              <th>Stasiun Pemohon</th>
              <th class="c">Item</th>
              <th>Status</th>
              <th class="r">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="reqLoading"><td colspan="7" class="ru-loading">Memuat…</td></tr>
            <tr v-else-if="!reqList.length"><td colspan="7" class="ru-empty">Tidak ada permintaan</td></tr>
            <tr v-else v-for="(row, idx) in reqList" :key="row.id">
              <td class="c">{{ (reqMeta.current_page - 1) * 25 + idx + 1 }}</td>
              <td><code>{{ row.request_number }}</code></td>
              <td>{{ formatDate(row.request_date) }}</td>
              <td>{{ row.requesting_station }}</td>
              <td class="c">{{ row.items_count ?? row.items?.length ?? '—' }}</td>
              <td><span class="ru-badge" :class="REQ_STATUS_BADGE[row.status]?.cls">{{ REQ_STATUS_BADGE[row.status]?.label ?? row.status }}</span></td>
              <td class="r">
                <button class="ru-link" @click="openReqDetail(row)">Lihat</button>
                <template v-if="canVerify">
                  <template v-if="row.status === 'SUBMITTED'">
                    <button class="ru-link" @click="doReqAction(row, 'approve')">Setujui</button>
                    <button class="ru-link ru-link-danger" @click="doReqAction(row, 'reject')">Tolak</button>
                  </template>
                  <template v-else-if="row.status === 'APPROVED'">
                    <button class="ru-link" @click="openDeliver(row)">Kirim</button>
                    <button class="ru-link ru-link-danger" @click="doReqAction(row, 'reject')">Tolak</button>
                  </template>
                  <template v-else-if="row.status === 'DELIVERED'">
                    <button class="ru-link" @click="doReqAction(row, 'close')">Tutup</button>
                  </template>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="ru-pager" v-if="reqMeta.last_page > 1">
        <button class="ru-btn" :disabled="reqMeta.current_page <= 1" @click="refreshReq(reqMeta.current_page - 1)">‹</button>
        <span>Hal {{ reqMeta.current_page }} / {{ reqMeta.last_page }} · {{ reqMeta.total }} permintaan</span>
        <button class="ru-btn" :disabled="reqMeta.current_page >= reqMeta.last_page" @click="refreshReq(reqMeta.current_page + 1)">›</button>
      </div>
    </section>

    <!-- =================================================================
         TAB PENDING (APPROVED, belum dikirim)
         ================================================================= -->
    <section v-show="activeTab === 'PENDING'" class="ru-panel">
      <div class="ru-toolbar">
        <input v-model="pendingFilters.search" placeholder="Cari nomor request / catatan…" class="ru-inp" @input="refreshPending(1)" />
        <select v-model="pendingFilters.station" class="ru-inp" @change="refreshPending(1)">
          <option value="">Semua Stasiun</option>
          <option v-for="s in STATIONS" :key="s" :value="s">{{ s }}</option>
        </select>
      </div>

      <div class="ru-table-wrap">
        <table class="ru-table">
          <thead>
            <tr>
              <th class="c" style="width: 48px;">No</th>
              <th>No. Request</th>
              <th>Tanggal</th>
              <th>Stasiun Pemohon</th>
              <th class="c">Item</th>
              <th>Status</th>
              <th class="r">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="pendingLoading"><td colspan="7" class="ru-loading">Memuat…</td></tr>
            <tr v-else-if="!pendingList.length"><td colspan="7" class="ru-empty">Tidak ada permintaan pending</td></tr>
            <tr v-else v-for="(row, idx) in pendingList" :key="row.id">
              <td class="c">{{ (pendingMeta.current_page - 1) * 25 + idx + 1 }}</td>
              <td><code>{{ row.request_number }}</code></td>
              <td>{{ formatDate(row.request_date) }}</td>
              <td>{{ row.requesting_station }}</td>
              <td class="c">{{ row.items_count ?? row.items?.length ?? '—' }}</td>
              <td><span class="ru-badge" :class="REQ_STATUS_BADGE[row.status]?.cls">{{ REQ_STATUS_BADGE[row.status]?.label ?? row.status }}</span></td>
              <td class="r">
                <button class="ru-link" @click="openReqDetail(row)">Lihat</button>
                <template v-if="canVerify">
                  <button class="ru-link" @click="openDeliver(row)">Kirim</button>
                  <button class="ru-link ru-link-danger" @click="doReqAction(row, 'reject')">Tolak</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="ru-pager" v-if="pendingMeta.last_page > 1">
        <button class="ru-btn" :disabled="pendingMeta.current_page <= 1" @click="refreshPending(pendingMeta.current_page - 1)">‹</button>
        <span>Hal {{ pendingMeta.current_page }} / {{ pendingMeta.last_page }} · {{ pendingMeta.total }} permintaan</span>
        <button class="ru-btn" :disabled="pendingMeta.current_page >= pendingMeta.last_page" @click="refreshPending(pendingMeta.current_page + 1)">›</button>
      </div>
    </section>

    <!-- =================================================================
         TAB RETUR
         ================================================================= -->
    <section v-show="activeTab === 'RETUR'" class="ru-panel">
      <div class="ru-toolbar">
        <input v-model="retFilters.search" placeholder="Cari nomor retur / alasan…" class="ru-inp" @input="refreshRet(1)" />
        <select v-model="retFilters.station" class="ru-inp" @change="refreshRet(1)">
          <option value="">Semua Stasiun</option>
          <option v-for="s in STATIONS" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="retFilters.status" class="ru-inp" @change="refreshRet(1)">
          <option value="">Semua Status</option>
          <option v-for="(b, k) in RET_STATUS_BADGE" :key="k" :value="k">{{ b.label }}</option>
        </select>
      </div>

      <div class="ru-table-wrap">
        <table class="ru-table">
          <thead>
            <tr>
              <th class="c" style="width: 48px;">No</th>
              <th>No. Retur</th>
              <th>Tanggal</th>
              <th>Stasiun Pengembali</th>
              <th>Alasan</th>
              <th class="c">Item</th>
              <th>Status</th>
              <th class="r">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="retLoading"><td colspan="8" class="ru-loading">Memuat…</td></tr>
            <tr v-else-if="!retList.length"><td colspan="8" class="ru-empty">Tidak ada retur</td></tr>
            <tr v-else v-for="(row, idx) in retList" :key="row.id">
              <td class="c">{{ (retMeta.current_page - 1) * 25 + idx + 1 }}</td>
              <td><code>{{ row.return_number }}</code></td>
              <td>{{ formatDate(row.return_date) }}</td>
              <td>{{ row.returning_station }}</td>
              <td>{{ row.reason ?? '—' }}</td>
              <td class="c">{{ row.items_count ?? row.items?.length ?? '—' }}</td>
              <td><span class="ru-badge" :class="RET_STATUS_BADGE[row.status]?.cls">{{ RET_STATUS_BADGE[row.status]?.label ?? row.status }}</span></td>
              <td class="r">
                <button class="ru-link" @click="openRetDetail(row)">Lihat</button>
                <template v-if="canVerify && row.status === 'SUBMITTED'">
                  <button class="ru-link" @click="doRetAction(row, 'receive')">Terima</button>
                  <button class="ru-link ru-link-danger" @click="doRetAction(row, 'reject')">Tolak</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="ru-pager" v-if="retMeta.last_page > 1">
        <button class="ru-btn" :disabled="retMeta.current_page <= 1" @click="refreshRet(retMeta.current_page - 1)">‹</button>
        <span>Hal {{ retMeta.current_page }} / {{ retMeta.last_page }} · {{ retMeta.total }} retur</span>
        <button class="ru-btn" :disabled="retMeta.current_page >= retMeta.last_page" @click="refreshRet(retMeta.current_page + 1)">›</button>
      </div>
    </section>

    <!-- =================================================================
         TAB HISTORY (2 sub-tab: Request + Retur)
         ================================================================= -->
    <section v-show="activeTab === 'HISTORY'" class="ru-panel">
      <nav class="ru-subtabs">
        <button
          v-for="t in HISTORY_SUBS" :key="t.key"
          class="ru-subtab" :class="{ active: historySub === t.key }"
          @click="switchHistorySub(t.key)"
        >{{ t.label }}</button>
      </nav>

      <!-- History Request -->
      <div v-show="historySub === 'REQUEST'">
        <div class="ru-toolbar">
          <input v-model="histReqFilters.search" placeholder="Cari nomor request / catatan…" class="ru-inp" @input="refreshHistReq(1)" />
          <select v-model="histReqFilters.station" class="ru-inp" @change="refreshHistReq(1)">
            <option value="">Semua Stasiun</option>
            <option v-for="s in STATIONS" :key="s" :value="s">{{ s }}</option>
          </select>
          <select v-model="histReqFilters.status" class="ru-inp" @change="refreshHistReq(1)">
            <option value="">Semua Status History</option>
            <option v-for="s in HIST_REQ_STATUSES" :key="s" :value="s">{{ REQ_STATUS_BADGE[s]?.label ?? s }}</option>
          </select>
        </div>

        <div class="ru-table-wrap">
          <table class="ru-table">
            <thead>
              <tr>
                <th class="c" style="width: 48px;">No</th>
                <th>No. Request</th>
                <th>Tanggal</th>
                <th>Stasiun Pemohon</th>
                <th class="c">Item</th>
                <th>Status</th>
                <th class="r">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="histReqLoading"><td colspan="7" class="ru-loading">Memuat…</td></tr>
              <tr v-else-if="!histReqList.length"><td colspan="7" class="ru-empty">Belum ada history request</td></tr>
              <tr v-else v-for="(row, idx) in histReqList" :key="row.id">
                <td class="c">{{ (histReqMeta.current_page - 1) * 25 + idx + 1 }}</td>
                <td><code>{{ row.request_number }}</code></td>
                <td>{{ formatDate(row.request_date) }}</td>
                <td>{{ row.requesting_station }}</td>
                <td class="c">{{ row.items_count ?? row.items?.length ?? '—' }}</td>
                <td><span class="ru-badge" :class="REQ_STATUS_BADGE[row.status]?.cls">{{ REQ_STATUS_BADGE[row.status]?.label ?? row.status }}</span></td>
                <td class="r">
                  <button class="ru-link" @click="openReqDetail(row)">Lihat</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="ru-pager" v-if="histReqMeta.last_page > 1">
          <button class="ru-btn" :disabled="histReqMeta.current_page <= 1" @click="refreshHistReq(histReqMeta.current_page - 1)">‹</button>
          <span>Hal {{ histReqMeta.current_page }} / {{ histReqMeta.last_page }} · {{ histReqMeta.total }} request</span>
          <button class="ru-btn" :disabled="histReqMeta.current_page >= histReqMeta.last_page" @click="refreshHistReq(histReqMeta.current_page + 1)">›</button>
        </div>
      </div>

      <!-- History Retur -->
      <div v-show="historySub === 'RETURN'">
        <div class="ru-toolbar">
          <input v-model="histRetFilters.search" placeholder="Cari nomor retur / alasan…" class="ru-inp" @input="refreshHistRet(1)" />
          <select v-model="histRetFilters.station" class="ru-inp" @change="refreshHistRet(1)">
            <option value="">Semua Stasiun</option>
            <option v-for="s in STATIONS" :key="s" :value="s">{{ s }}</option>
          </select>
          <select v-model="histRetFilters.status" class="ru-inp" @change="refreshHistRet(1)">
            <option value="">Semua Status History</option>
            <option v-for="s in HIST_RET_STATUSES" :key="s" :value="s">{{ RET_STATUS_BADGE[s]?.label ?? s }}</option>
          </select>
        </div>

        <div class="ru-table-wrap">
          <table class="ru-table">
            <thead>
              <tr>
                <th class="c" style="width: 48px;">No</th>
                <th>No. Retur</th>
                <th>Tanggal</th>
                <th>Stasiun Pengembali</th>
                <th>Alasan</th>
                <th class="c">Item</th>
                <th>Status</th>
                <th class="r">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="histRetLoading"><td colspan="8" class="ru-loading">Memuat…</td></tr>
              <tr v-else-if="!histRetList.length"><td colspan="8" class="ru-empty">Belum ada history retur</td></tr>
              <tr v-else v-for="(row, idx) in histRetList" :key="row.id">
                <td class="c">{{ (histRetMeta.current_page - 1) * 25 + idx + 1 }}</td>
                <td><code>{{ row.return_number }}</code></td>
                <td>{{ formatDate(row.return_date) }}</td>
                <td>{{ row.returning_station }}</td>
                <td>{{ row.reason ?? '—' }}</td>
                <td class="c">{{ row.items_count ?? row.items?.length ?? '—' }}</td>
                <td><span class="ru-badge" :class="RET_STATUS_BADGE[row.status]?.cls">{{ RET_STATUS_BADGE[row.status]?.label ?? row.status }}</span></td>
                <td class="r">
                  <button class="ru-link" @click="openRetDetail(row)">Lihat</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="ru-pager" v-if="histRetMeta.last_page > 1">
          <button class="ru-btn" :disabled="histRetMeta.current_page <= 1" @click="refreshHistRet(histRetMeta.current_page - 1)">‹</button>
          <span>Hal {{ histRetMeta.current_page }} / {{ histRetMeta.last_page }} · {{ histRetMeta.total }} retur</span>
          <button class="ru-btn" :disabled="histRetMeta.current_page >= histRetMeta.last_page" @click="refreshHistRet(histRetMeta.current_page + 1)">›</button>
        </div>
      </div>
    </section>
    <!-- =================================================================
         MODAL DETAIL PERMINTAAN (read-only)
         ================================================================= -->
    <div v-if="detailReq.open" class="ru-modal-backdrop" @click.self="detailReq.open = false">
      <div class="ru-modal">
        <header class="ru-modal-head">
          <h3>Detail Permintaan {{ detailReq.data?.request_number ?? '' }}</h3>
          <button class="ru-x" @click="detailReq.open = false">×</button>
        </header>
        <div class="ru-modal-body">
          <div v-if="detailReq.loading" class="ru-loading">Memuat…</div>
          <template v-else-if="detailReq.data">
            <div class="ru-meta-grid">
              <div><label>Stasiun</label><div>{{ detailReq.data.requesting_station }}</div></div>
              <div><label>Tanggal</label><div>{{ formatDate(detailReq.data.request_date) }}</div></div>
              <div><label>Status</label><div><span class="ru-badge" :class="REQ_STATUS_BADGE[detailReq.data.status]?.cls">{{ REQ_STATUS_BADGE[detailReq.data.status]?.label }}</span></div></div>
              <div v-if="detailReq.data.notes" class="ru-meta-wide"><label>Catatan</label><div>{{ detailReq.data.notes }}</div></div>
            </div>

            <table class="ru-items">
              <thead>
                <tr>
                  <th>Tipe</th>
                  <th>Item</th>
                  <th class="r">Qty Minta</th>
                  <th class="r">Qty Kirim</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="it in detailReq.data.items" :key="it.id">
                  <td>{{ itemTypeLabel(it.item_type) }}</td>
                  <td>{{ it.item_name }}<span v-if="it.item_unit" class="ru-unit"> · {{ it.item_unit }}</span></td>
                  <td class="r">{{ it.qty_requested }}</td>
                  <td class="r">{{ it.qty_delivered ?? 0 }}</td>
                </tr>
              </tbody>
            </table>
          </template>
        </div>
        <footer class="ru-modal-foot">
          <button class="ru-btn" @click="detailReq.open = false">Tutup</button>
          <template v-if="canVerify && detailReq.data?.status === 'SUBMITTED'">
            <button class="ru-btn ru-btn-danger" @click="doReqAction(detailReq.data, 'reject'); detailReq.open = false">Tolak</button>
            <button class="ru-btn ru-btn-primary" @click="doReqAction(detailReq.data, 'approve'); detailReq.open = false">Setujui</button>
          </template>
          <template v-else-if="canVerify && detailReq.data?.status === 'APPROVED'">
            <button class="ru-btn ru-btn-danger" @click="doReqAction(detailReq.data, 'reject'); detailReq.open = false">Tolak</button>
            <button class="ru-btn ru-btn-primary" @click="detailReq.open = false; openDeliver(detailReq.data)">Kirim</button>
          </template>
          <template v-else-if="canVerify && detailReq.data?.status === 'DELIVERED'">
            <button class="ru-btn ru-btn-primary" @click="doReqAction(detailReq.data, 'close'); detailReq.open = false">Tutup Permintaan</button>
          </template>
        </footer>
      </div>
    </div>

    <!-- =================================================================
         MODAL KIRIM (deliver) — admin set qty & batch
         ================================================================= -->
    <div v-if="deliverModal.open" class="ru-modal-backdrop" @click.self="deliverModal.open = false">
      <div class="ru-modal">
        <header class="ru-modal-head">
          <h3>Kirim Barang — {{ deliverModal.request?.request_number }}</h3>
          <button class="ru-x" @click="deliverModal.open = false">×</button>
        </header>
        <div class="ru-modal-body">
          <p class="ru-help">Stok di inventori akan berkurang sesuai qty yang dikirim. Batch & expiry diambil otomatis dari inventori (FEFO — batch paling cepat expired).</p>
          <table class="ru-items">
            <thead>
              <tr>
                <th>Item</th>
                <th class="r" style="width: 90px;">Diminta</th>
                <th style="width: 100px;">Dikirim</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="it in deliverModal.request?.items ?? []" :key="it.id">
                <td>{{ it.item_name }}<span v-if="it.item_unit" class="ru-unit"> · {{ it.item_unit }}</span></td>
                <td class="r">{{ it.qty_requested }}</td>
                <td><input type="number" v-model="it._qty_deliver" min="0" :max="it.qty_requested" step="0.01" class="ru-inp ru-inp-sm" /></td>
              </tr>
            </tbody>
          </table>
        </div>
        <footer class="ru-modal-foot">
          <button class="ru-btn" @click="deliverModal.open = false">Batal</button>
          <button class="ru-btn ru-btn-primary" :disabled="deliverModal.submitting" @click="submitDeliver">
            {{ deliverModal.submitting ? 'Mengirim…' : 'Kirim & Kurangi Stok' }}
          </button>
        </footer>
      </div>
    </div>

    <!-- =================================================================
         MODAL DETAIL RETUR (read-only)
         ================================================================= -->
    <div v-if="detailRet.open" class="ru-modal-backdrop" @click.self="detailRet.open = false">
      <div class="ru-modal">
        <header class="ru-modal-head">
          <h3>Detail Retur {{ detailRet.data?.return_number ?? '' }}</h3>
          <button class="ru-x" @click="detailRet.open = false">×</button>
        </header>
        <div class="ru-modal-body">
          <div v-if="detailRet.loading" class="ru-loading">Memuat…</div>
          <template v-else-if="detailRet.data">
            <div class="ru-meta-grid">
              <div><label>Stasiun</label><div>{{ detailRet.data.returning_station }}</div></div>
              <div><label>Tanggal</label><div>{{ formatDate(detailRet.data.return_date) }}</div></div>
              <div><label>Status</label><div><span class="ru-badge" :class="RET_STATUS_BADGE[detailRet.data.status]?.cls">{{ RET_STATUS_BADGE[detailRet.data.status]?.label }}</span></div></div>
              <div v-if="detailRet.data.reason" class="ru-meta-wide"><label>Alasan</label><div>{{ detailRet.data.reason }}</div></div>
              <div v-if="detailRet.data.notes" class="ru-meta-wide"><label>Catatan</label><div>{{ detailRet.data.notes }}</div></div>
            </div>

            <p v-if="detailRet.data.status === 'SUBMITTED'" class="ru-help">
              Stok belum bertambah. Stok akan otomatis kembali ke inventori saat Anda klik "Terima".
            </p>

            <table class="ru-items">
              <thead>
                <tr>
                  <th>Tipe</th>
                  <th>Item</th>
                  <th class="r">Qty</th>
                  <th>Batch</th>
                  <th>Expiry</th>
                  <th>Kondisi</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="it in detailRet.data.items" :key="it.id">
                  <td>{{ itemTypeLabel(it.item_type) }}</td>
                  <td>{{ it.item_name }}<span v-if="it.item_unit" class="ru-unit"> · {{ it.item_unit }}</span></td>
                  <td class="r">{{ it.qty_returned }}</td>
                  <td>{{ it.batch_no ?? '—' }}</td>
                  <td>{{ formatDate(it.expiry_date) }}</td>
                  <td>{{ conditionLabel(it.condition) }}</td>
                </tr>
              </tbody>
            </table>
          </template>
        </div>
        <footer class="ru-modal-foot">
          <button class="ru-btn" @click="detailRet.open = false">Tutup</button>
          <template v-if="canVerify && detailRet.data?.status === 'SUBMITTED'">
            <button class="ru-btn ru-btn-danger" @click="doRetAction(detailRet.data, 'reject'); detailRet.open = false">Tolak</button>
            <button class="ru-btn ru-btn-primary" @click="doRetAction(detailRet.data, 'receive'); detailRet.open = false">Terima &amp; Tambah Stok</button>
          </template>
        </footer>
      </div>
    </div>

    <!-- Toast -->
    <div v-if="toast" class="ru-toast" :class="`ru-toast-${toast.type}`">{{ toast.msg }}</div>
  </div>
</template>

<style scoped>
.ru-wrap { display: flex; flex-direction: column; gap: 1rem; position: relative; }

.ru-head h2 { font-family: 'Space Grotesk', serif; font-size: 20px; color: var(--td); margin: 0; }
.ru-sub { font-size: 12.5px; color: var(--tm); margin: 3px 0 0; }

.ru-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); }
.ru-tab { padding: 9px 18px; border: none; background: transparent; color: var(--tm); font-size: 13px; font-weight: 500; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.ru-tab:hover { color: var(--td); }
.ru-tab.active { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }

.ru-subtabs { display: flex; gap: 4px; padding: 4px; background: var(--bs); border-radius: 8px; align-self: flex-start; }
.ru-subtab { padding: 6px 14px; border: none; background: transparent; color: var(--tm); font-size: 12.5px; font-weight: 500; cursor: pointer; border-radius: 6px; }
.ru-subtab:hover { color: var(--td); }
.ru-subtab.active { background: var(--bc); color: var(--ga); font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,0.06); }

.ru-panel { display: flex; flex-direction: column; gap: 12px; min-height: 40vh; }

.ru-toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ru-inp { padding: 7px 10px; border: 1px solid var(--gb); border-radius: 6px; font-size: 13px; background: var(--bc); color: var(--td); }
.ru-inp:focus { outline: none; border-color: var(--ga); }
.ru-inp-sm { padding: 5px 7px; font-size: 12px; width: 100%; }

.ru-btn { padding: 7px 14px; border: 1px solid var(--gb); border-radius: 6px; background: var(--bc); color: var(--td); font-size: 13px; cursor: pointer; }
.ru-btn:hover { background: var(--bs); }
.ru-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.ru-btn-primary { background: var(--ga); color: white; border-color: var(--ga); }
.ru-btn-primary:hover { background: var(--gd); }
.ru-btn-danger { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
.ru-btn-danger:hover { background: #fecaca; }

.ru-table-wrap { background: var(--bc); border: 1px solid var(--gb); border-radius: 10px; overflow: auto; }
.ru-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ru-table thead { background: var(--bs); }
.ru-table th { padding: 9px 10px; text-align: left; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--tu); border-bottom: 1px solid var(--gb); white-space: nowrap; }
.ru-table th.c, .ru-table td.c { text-align: center; }
.ru-table th.r, .ru-table td.r { text-align: right; }
.ru-table td { padding: 8px 10px; border-bottom: 1px solid var(--gb); vertical-align: middle; }
.ru-table tr:last-child td { border-bottom: none; }
.ru-table code { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--td); }
.ru-loading, .ru-empty { padding: 1.5rem !important; text-align: center; color: var(--tm); font-size: 13px; }

.ru-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.b-draft { background: #f1f5f9; color: #475569; }
.b-sent  { background: #fef3c7; color: #92400e; }
.b-info  { background: #dbeafe; color: #1e40af; }
.b-ok    { background: #d1fae5; color: #065f46; }
.b-muted { background: #e5e7eb; color: #374151; }
.b-err   { background: #fee2e2; color: #991b1b; }

.ru-link { background: none; border: none; color: var(--ga); font-size: 12px; cursor: pointer; padding: 2px 5px; }
.ru-link:hover { text-decoration: underline; }
.ru-link-danger { color: #dc2626; }

.ru-pager { display: flex; justify-content: center; align-items: center; gap: 10px; padding: 8px; font-size: 12.5px; color: var(--tm); }

/* Modal */
.ru-modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,0.55); display: flex; align-items: center; justify-content: center; z-index: 100; padding: 1rem; }
.ru-modal { background: var(--bc); border-radius: 12px; width: 100%; max-width: 800px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; }
.ru-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--gb); }
.ru-modal-head h3 { margin: 0; font-size: 16px; color: var(--td); }
.ru-x { background: none; border: none; font-size: 22px; cursor: pointer; color: var(--tm); }
.ru-x:hover { color: var(--td); }
.ru-modal-body { padding: 16px 18px; overflow: auto; display: flex; flex-direction: column; gap: 14px; }
.ru-modal-foot { padding: 12px 18px; border-top: 1px solid var(--gb); display: flex; justify-content: flex-end; gap: 8px; background: var(--bs); }

.ru-meta-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.ru-meta-grid > div { display: flex; flex-direction: column; gap: 2px; }
.ru-meta-grid label { font-size: 10.5px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: 0.04em; }
.ru-meta-grid > div > div { font-size: 13px; color: var(--td); }
.ru-meta-wide { grid-column: 1 / -1; }

.ru-items { width: 100%; border-collapse: collapse; font-size: 12.5px; border: 1px solid var(--gb); border-radius: 8px; }
.ru-items thead { background: var(--bs); }
.ru-items th { padding: 6px 8px; text-align: left; font-size: 10.5px; font-weight: 600; text-transform: uppercase; color: var(--tu); border-bottom: 1px solid var(--gb); }
.ru-items th.r, .ru-items td.r { text-align: right; }
.ru-items td { padding: 6px 8px; border-bottom: 1px solid var(--gb); vertical-align: middle; }
.ru-items tr:last-child td { border-bottom: none; }
.ru-unit { color: var(--tm); font-size: 11px; }

.ru-help { font-size: 12px; color: var(--tm); background: var(--bs); padding: 8px 10px; border-radius: 6px; margin: 0; border-left: 3px solid var(--ga); }

.ru-toast { position: fixed; bottom: 24px; right: 24px; padding: 10px 16px; border-radius: 8px; color: white; font-size: 13px; box-shadow: 0 4px 16px rgba(0,0,0,0.15); z-index: 200; }
.ru-toast-s { background: #16a34a; }
.ru-toast-e { background: #dc2626; }
</style>
