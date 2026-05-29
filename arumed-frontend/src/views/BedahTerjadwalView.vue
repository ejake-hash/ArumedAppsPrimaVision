<script setup>
import { ref, computed, onMounted } from 'vue'
import { bedahApi, masterApi, unitRequestApi, unitReturnApi } from '@/services/api'
import RequestBhpIolModal from '@/components/bedah/RequestBhpIolModal.vue'
import ReturBhpIolModal from '@/components/bedah/ReturBhpIolModal.vue'

// ── Data nyata ───────────────────────────────────────────────────────────────
const jadwal     = ref([])   // pasien terjadwal mendatang (scheduled_date > today)
const requests   = ref([])   // permintaan BHP/IOL ke gudang/farmasi
const bhpOptions = ref([])   // master BHP untuk dropdown request
const iolOptions = ref([])   // master IOL untuk dropdown request
const loadingJadwal   = ref(false)
const loadingRequests = ref(false)

// ── Weekpicker (filter jadwal per-minggu) ────────────────────────────────────
// weekStart = null → mode "semua mendatang"; else Senin minggu terpilih (YYYY-MM-DD).
const weekStart = ref(null)

function mondayOf(dateStr) {
  const d = new Date(dateStr + 'T00:00:00')
  const dow = (d.getDay() + 6) % 7   // 0 = Senin
  d.setDate(d.getDate() - dow)
  return d.toISOString().slice(0, 10)
}
function addDays(dateStr, n) {
  const d = new Date(dateStr + 'T00:00:00')
  d.setDate(d.getDate() + n)
  return d.toISOString().slice(0, 10)
}
const weekEnd = computed(() => weekStart.value ? addDays(weekStart.value, 6) : null)
const weekLabel = computed(() => {
  if (!weekStart.value) return 'Semua mendatang'
  const opt = { day: '2-digit', month: 'short' }
  const a = new Date(weekStart.value + 'T00:00:00').toLocaleDateString('id-ID', opt)
  const b = new Date(weekEnd.value + 'T00:00:00').toLocaleDateString('id-ID', { ...opt, year: 'numeric' })
  return `${a} – ${b}`
})

// Input date (hari mana pun) → snap ke Senin minggunya, lalu reload.
function onPickWeek(e) {
  const v = e.target.value
  weekStart.value = v ? mondayOf(v) : null
  loadJadwal()
}
function shiftWeek(n) {
  const base = weekStart.value ?? mondayOf(new Date().toISOString().slice(0, 10))
  weekStart.value = addDays(base, n * 7)
  loadJadwal()
}
function clearWeek() { weekStart.value = null; loadJadwal() }

// ── Helpers mapping ────────────────────────────────────────────────────────
function ageFromDob(dob) {
  if (!dob) return null
  const b = new Date(dob); if (isNaN(b)) return null
  const now = new Date()
  let a = now.getFullYear() - b.getFullYear()
  const m = now.getMonth() - b.getMonth()
  if (m < 0 || (m === 0 && now.getDate() < b.getDate())) a--
  return a
}

function iolLabel(i) {
  return [i.brand, i.model].filter(Boolean).join(' ') || i.iol_type || 'IOL'
}

// Map satu surgery_schedule (dengan visit/patient/examination/package) → bentuk view
function mapSchedule(s) {
  const v   = s.visit ?? null
  const pt  = v?.patient ?? null
  const ex  = v?.doctor_examination ?? null
  const pkg = s.surgery_package ?? null
  const items = pkg?.items ?? []
  const bhpCount = items.filter(it => it.item_type === 'BHP').length
  const iolCount = items.filter(it => it.item_type === 'IOL').length

  // IOL dari rekomendasi biometri (PenunjangView) — per mata. Kosong jika belum ada.
  const recs = v?.iol_recommendations ?? []
  const iolByEye = (eye) => {
    const r = recs.find(x => (x.eye_side || '').toUpperCase() === eye)
    if (!r) return null
    const power = r.recommended_power != null ? `+${Number(r.recommended_power)} D` : null
    return { power, type: r.iol_type ?? null, brand: r.brand ?? null }
  }
  const iol = { od: iolByEye('OD'), os: iolByEye('OS') }

  return {
    id:        s.id,
    visitId:   v?.id ?? null,
    name:      pt?.name ?? '—',
    rm:        pt?.no_rm ?? '—',
    age:       ageFromDob(pt?.date_of_birth),
    gender:    pt?.gender ?? '—',
    ptype:     v?.guarantor_type === 'BPJS' ? 'bpjs' : 'umum',
    dpjp:      s.lead_surgeon?.name ?? '—',
    diagnosa:  ex?.diagnosis_utama ?? '—',
    prosedur:  pkg?.name ?? '—',
    scheduledDate: s.scheduled_date,
    scheduledTime: s.scheduled_time,
    ruang:     s.operation_room ?? '—',
    iol,
    paket: {
      id:   pkg?.id ?? null,
      kode: pkg?.code ?? '—',
      nama: pkg?.name ?? 'Paket bedah',
      bhpCount, iolCount,
    },
  }
}

// Map satu surgery_request (per pasien/operasi → Bedah) → bentuk view
function mapRequest(r) {
  return {
    id:          'sr-' + r.id,
    rawId:       r.id,
    kind:        'bedah',
    tanggal:     (r.created_at ?? '').slice(0, 10),
    number:      null,
    pasienName:  r.visit?.patient?.name ?? null,
    paketKode:   r.surgery_schedule?.surgery_package?.code ?? null,
    keterangan:  '',
    status:      r.status ?? 'PENDING',
    rawItems:    [],
    bhpItems: (r.bhp_items ?? []).map(b => ({
      item:   b.bhp_item?.name ?? '—',
      jumlah: b.quantity,
      satuan: b.bhp_item?.unit ?? '',
    })),
    iolItems: (r.iol_items ?? []).map(o => ({
      item:   o.iol_item ? iolLabel(o.iol_item) : (o.requested_iol_type ?? 'IOL'),
      jumlah: 1,
      satuan: 'Pcs',
      power:  o.requested_power != null ? `+${o.requested_power} D` : '',
      eye:    o.eye_side,
    })),
  }
}

// Map satu unit_request (BHP/IOL → Inventori Farmasi) → bentuk view
function mapUnitRequest(r) {
  const items = Array.isArray(r.items) ? r.items : []
  return {
    id:          'ur-' + r.id,
    rawId:       r.id,
    kind:        'gudang',
    tanggal:     (r.request_date ?? r.created_at ?? '').slice(0, 10),
    number:      r.request_number ?? null,
    pasienName:  null,
    paketKode:   null,
    keterangan:  '',
    status:      r.status ?? 'DRAFT',
    // untuk prefill retur per-baris
    rawItems:    items.map(it => ({ item_type: it.item_type, item_id: it.item_id, qty: Number(it.qty_delivered || it.qty_requested || 1) })),
    bhpItems: items.filter(it => it.item_type === 'BHP').map(it => ({
      item: it.item_name ?? '—', jumlah: Number(it.qty_requested ?? 0), satuan: it.item_unit ?? '',
    })),
    iolItems: items.filter(it => it.item_type === 'IOL').map(it => ({
      item: it.item_name ?? 'IOL', jumlah: Number(it.qty_requested ?? 0), satuan: it.item_unit ?? 'Pcs', power: '', eye: null,
    })),
  }
}

// Map satu unit_return (retur BHP/IOL ke gudang) → bentuk view
function mapUnitReturn(r) {
  const items = Array.isArray(r.items) ? r.items : []
  return {
    id:          'rt-' + r.id,
    rawId:       r.id,
    kind:        'retur',
    tanggal:     (r.return_date ?? r.created_at ?? '').slice(0, 10),
    number:      r.return_number ?? null,
    pasienName:  null,
    paketKode:   null,
    keterangan:  r.reason ?? '',
    status:      r.status ?? 'DRAFT',
    rawItems:    [],
    bhpItems: items.filter(it => it.item_type === 'BHP').map(it => ({
      item: it.item_name ?? '—', jumlah: Number(it.qty_returned ?? 0), satuan: it.item_unit ?? '',
    })),
    iolItems: items.filter(it => it.item_type === 'IOL').map(it => ({
      item: it.item_name ?? 'IOL', jumlah: Number(it.qty_returned ?? 0), satuan: it.item_unit ?? 'Pcs', power: '', eye: null,
    })),
  }
}

// ── Loaders ──────────────────────────────────────────────────────────────────
async function loadJadwal() {
  loadingJadwal.value = true
  try {
    const params = weekStart.value
      ? { date_from: weekStart.value, date_to: weekEnd.value }
      : { upcoming: 1 }
    const res = await bedahApi.jadwal(params)
    const list = res.data?.data ?? []
    jadwal.value = (Array.isArray(list) ? list : []).map(mapSchedule)
  } catch (e) {
    jadwal.value = []
    toast('w', e.response?.data?.message ?? 'Gagal memuat pasien terjadwal')
  } finally {
    loadingJadwal.value = false
  }
}

async function loadRequests() {
  loadingRequests.value = true
  try {
    const [srRes, urRes, rtRes] = await Promise.all([
      bedahApi.listRequests().catch(() => null),
      unitRequestApi.list({ station: 'BEDAH', per_page: 100 }).catch(() => null),
      unitReturnApi.list({ station: 'BEDAH', per_page: 100 }).catch(() => null),
    ])
    const sr = (srRes ? (Array.isArray(srRes.data?.data) ? srRes.data.data : []) : []).map(mapRequest)
    const ur = (urRes ? unwrapList(urRes) : []).map(mapUnitRequest)
    const rt = (rtRes ? unwrapList(rtRes) : []).map(mapUnitReturn)
    requests.value = [...sr, ...ur, ...rt].sort((a, b) => (b.tanggal || '').localeCompare(a.tanggal || ''))
  } catch (e) {
    requests.value = []
    toast('w', e.response?.data?.message ?? 'Gagal memuat permintaan')
  } finally {
    loadingRequests.value = false
  }
}

// Envelope { data: { data: [...] } } (paginated) atau { data: [...] } → selalu Array.
function unwrapList(res) {
  const d = res?.data?.data
  if (Array.isArray(d)) return d
  if (d && Array.isArray(d.data)) return d.data   // LengthAwarePaginator
  return Array.isArray(res?.data) ? res.data : []
}

async function loadMasters() {
  try {
    const [bhpRes, iolRes] = await Promise.all([
      masterApi.bhp.list({ per_page: 500 }),
      masterApi.iol.list({ per_page: 500 }),
    ])
    bhpOptions.value = unwrapList(bhpRes)
    iolOptions.value = unwrapList(iolRes)
  } catch {
    bhpOptions.value = []
    iolOptions.value = []
  }
}

onMounted(() => {
  loadJadwal()
  loadRequests()
  loadMasters()
})

// ── UI State ───────────────────────────────────────────────────────────────
const tab = ref('pasien')
const showModal = ref(false)
const sourcePasien = ref(null)
const submitting = ref(false)

// Modal Minta/Retur stok ke gudang (konsep manajemen stok farmasi, station=BEDAH)
const showMintaModal = ref(false)
const showReturModal = ref(false)
const returPrefill   = ref(null)   // prefill retur dari satu permintaan (per-baris)
function onStockChanged(payload) {
  if (payload?.message) toast(payload.type ?? 'i', payload.message)
  showReturModal.value = false
  returPrefill.value = null
  loadRequests()
}

// Sub-tab tab 2: 'aktif' (berjalan) | 'riwayat' (final)
const reqSubTab = ref('aktif')
const FINAL_STATUSES = ['CLOSED', 'RECEIVED', 'REJECTED']
const activeRequests  = computed(() => requests.value.filter(r => !FINAL_STATUSES.includes(r.status)))
const historyRequests = computed(() => requests.value.filter(r =>  FINAL_STATUSES.includes(r.status)))
const shownRequests   = computed(() => reqSubTab.value === 'riwayat' ? historyRequests.value : activeRequests.value)

// Aksi per-baris
const rowBusy = ref(null)
async function rowAction(r, fn, msg) {
  rowBusy.value = r.id
  try {
    await fn()
    toast('s', msg)
    await loadRequests()
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Aksi gagal')
  } finally {
    rowBusy.value = null
  }
}
// Terima barang: gudang DELIVERED→close ; bedah SENT→terima
function terimaRow(r) {
  if (r.kind === 'gudang') return rowAction(r, () => unitRequestApi.close(r.rawId), `Barang ${r.number ?? ''} diterima`)
  if (r.kind === 'bedah')  return rowAction(r, () => bedahApi.terimaRequest(r.rawId), 'BHP/IOL diterima')
}
const canTerima = (r) => (r.kind === 'gudang' && r.status === 'DELIVERED') || (r.kind === 'bedah' && r.status === 'SENT')
// Retur: hanya permintaan gudang yang sudah diterima (CLOSED)
const canRetur = (r) => r.kind === 'gudang' && r.status === 'CLOSED'
function returRow(r) {
  returPrefill.value = { unit_request_id: r.rawId, items: r.rawItems ?? [] }
  showReturModal.value = true
}

// Modal konfirmasi 1-klik request dari isi paket bedah
const previewLoading = ref(false)
const preview = ref(null)   // { bhp_items:[...], iol_items:[...] } prefilled dari paket
const toasts = ref([])
let toastId = 0

// ── Computed ───────────────────────────────────────────────────────────────
const statusLabel = { DRAFT: 'Draft', SUBMITTED: 'Menunggu', PENDING: 'Menunggu', APPROVED: 'Disetujui', REJECTED: 'Ditolak', DELIVERED: 'Terkirim', SENT: 'Terkirim', RECEIVED: 'Diterima', CLOSED: 'Diterima' }
const statusCls   = { DRAFT: 'st-pending', SUBMITTED: 'st-pending', PENDING: 'st-pending', APPROVED: 'st-approved', REJECTED: 'st-rejected', DELIVERED: 'st-delivered', SENT: 'st-delivered', RECEIVED: 'st-approved', CLOSED: 'st-approved' }

// ── Helpers ────────────────────────────────────────────────────────────────
function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('id-ID', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' })
}

// Ringkasan item permintaan → "BSS 500ml ×2, IOL X ×1"
function reqItemsText(r) {
  const parts = [
    ...r.bhpItems.map(b => `${b.item} ×${b.jumlah}`),
    ...r.iolItems.map(o => `${o.item}${o.power ? ` ${o.power}` : ''} ×${o.jumlah}`),
  ]
  return parts.length ? parts.join(', ') : '—'
}

function toast(type, msg) {
  const id = ++toastId
  toasts.value.push({ id, type, msg })
  setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id) }, 3000)
}

// ── Modal konfirmasi (1-klik request dari isi paket) ─────────────────────────
async function openRequestFromPasien(p) {
  if (!p.visitId) { toast('w', 'Pasien terjadwal ini belum punya kunjungan terkait'); return }
  sourcePasien.value = p
  preview.value = null
  showModal.value = true
  previewLoading.value = true
  try {
    const res = await bedahApi.autoRequestPreview(p.id)
    const d = res.data?.data ?? {}
    preview.value = {
      package:   d.package ?? null,
      bhp_items: (d.bhp_items ?? []).map(b => ({ ...b })),
      iol_items: (d.iol_items ?? []).map(o => ({ ...o })),
    }
  } catch (e) {
    showModal.value = false
    toast('w', e.response?.data?.message ?? 'Gagal memuat isi paket')
  } finally {
    previewLoading.value = false
  }
}

// IOL hanya bisa ke unit-request bila item paket menunjuk master IOL (item_id).
const iolSendable = computed(() => (preview.value?.iol_items ?? []).filter(o => o.iol_item_id))
const iolSkipped  = computed(() => (preview.value?.iol_items ?? []).filter(o => !o.iol_item_id).length)
const previewEmpty = computed(() =>
  preview.value && !(preview.value.bhp_items?.length) && !iolSendable.value.length
)

async function submitRequest() {
  if (!preview.value) return
  const bhp = preview.value.bhp_items ?? []
  const iol = iolSendable.value
  if (!bhp.length && !iol.length) {
    toast('w', 'Paket tidak punya item BHP/IOL (dgn master) untuk dikirim ke gudang'); return
  }
  const items = [
    ...bhp.map(b => ({ item_type: 'BHP', item_id: b.bhp_item_id, qty_requested: b.quantity })),
    ...iol.map(o => ({ item_type: 'IOL', item_id: o.iol_item_id, qty_requested: o.quantity ?? 1 })),
  ]
  submitting.value = true
  try {
    const res = await unitRequestApi.create({
      requesting_station: 'BEDAH',
      notes: `Auto dari paket ${preview.value.package?.code ?? '-'} — ${sourcePasien.value.name}`,
      items,
    })
    const created = res.data?.data
    if (created?.id) await unitRequestApi.submit(created.id)
    showModal.value = false
    toast('s', `Permintaan ${sourcePasien.value.name} terkirim ke Inventori Farmasi`)
    tab.value = 'request'
    await loadRequests()
  } catch (e) {
    const errs = e.response?.data?.errors
    toast('w', errs ? Object.values(errs).flat()[0] : (e.response?.data?.message ?? 'Gagal mengirim permintaan'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="bdt">
    <!-- HEADER -->
    <div class="bdt-head">
      <div>
        <h1>Bedah · Pasien Terjadwal</h1>
        <p class="bdt-sub">Penjadwalan operasi mendatang &amp; permintaan BHP/IOL ke gudang / farmasi</p>
      </div>
    </div>

    <!-- TAB NAV -->
    <div class="bdt-tabs" role="tablist">
      <button :class="['bdt-tab', tab === 'pasien' ? 'a' : '']" @click="tab = 'pasien'">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Pasien Terjadwal
        <span class="bdt-tab-ct">{{ jadwal.length }}</span>
      </button>
      <button :class="['bdt-tab', tab === 'request' ? 'a' : '']" @click="tab = 'request'">
        <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        Request BHP/IOL
        <span class="bdt-tab-ct">{{ requests.length }}</span>
      </button>
    </div>

    <!-- ───────── TAB 1: PASIEN TERJADWAL ───────── -->
    <div v-if="tab === 'pasien'" class="bdt-body">
      <!-- Weekpicker toolbar -->
      <div class="wk-bar">
        <div class="wk-nav">
          <button class="wk-btn" title="Minggu sebelumnya" @click="shiftWeek(-1)">‹</button>
          <input type="date" class="wk-date" :value="weekStart || ''" @change="onPickWeek" />
          <button class="wk-btn" title="Minggu berikutnya" @click="shiftWeek(1)">›</button>
        </div>
        <span class="wk-label">{{ weekLabel }}</span>
        <button v-if="weekStart" class="wk-clear" @click="clearWeek">Semua mendatang</button>
      </div>

      <table class="jt">
        <thead>
          <tr>
            <th class="jt-no">No.</th>
            <th class="jt-tgl">Tanggal</th>
            <th>Nama Pasien</th>
            <th>Diagnosa</th>
            <th>Jenis Operasi</th>
            <th class="jt-iol">IOL (Biometri)</th>
            <th>Paket Operasi</th>
            <th class="jt-act"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(p, i) in jadwal" :key="p.id">
            <td class="jt-no">{{ i + 1 }}</td>
            <td class="jt-tgl">
              <div class="dt-day">{{ fmtDate(p.scheduledDate) }}</div>
              <div class="dt-time">{{ p.scheduledTime ? p.scheduledTime.slice(0,5) : 'Belum dijam' }} · {{ p.ruang }}</div>
            </td>
            <td>
              <div class="pt-name">{{ p.name }}</div>
              <div class="pt-meta">{{ p.rm }} · {{ p.age != null ? p.age + ' th' : '—' }} · {{ p.gender }} · <span :class="['ptype', `ptype-${p.ptype}`]">{{ p.ptype.toUpperCase() }}</span></div>
              <div class="pt-dpjp">DPJP: {{ p.dpjp }}</div>
            </td>
            <td class="td-dx">{{ p.diagnosa }}</td>
            <td class="td-op">{{ p.prosedur }}</td>
            <td class="jt-iol">
              <template v-if="p.iol.od || p.iol.os">
                <div v-if="p.iol.od" class="iol-eye">
                  <span class="iol-tag">OD</span> {{ p.iol.od.power || '—' }}
                  <span v-if="p.iol.od.type" class="iol-type">{{ p.iol.od.type }}</span>
                </div>
                <div v-if="p.iol.os" class="iol-eye">
                  <span class="iol-tag">OS</span> {{ p.iol.os.power || '—' }}
                  <span v-if="p.iol.os.type" class="iol-type">{{ p.iol.os.type }}</span>
                </div>
              </template>
              <span v-else class="iol-empty">—</span>
            </td>
            <td>
              <span class="paket-kode">{{ p.paket.kode }}</span>
              <div class="paket-nama">{{ p.paket.nama }}</div>
              <div class="paket-count">{{ p.paket.bhpCount }} BHP · {{ p.paket.iolCount }} IOL</div>
            </td>
            <td class="jt-act">
              <button v-if="p.visitId" class="btn-req" @click="openRequestFromPasien(p)">
                <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Request BHP/IOL
              </button>
              <span v-else class="no-visit" title="Jadwal ini belum terhubung kunjungan pasien">Tanpa kunjungan</span>
            </td>
          </tr>
          <tr v-if="loadingJadwal">
            <td colspan="8" class="jt-empty">Memuat pasien terjadwal…</td>
          </tr>
          <tr v-else-if="!jadwal.length">
            <td colspan="8" class="jt-empty">{{ weekStart ? 'Tidak ada pasien terjadwal di minggu ini' : 'Belum ada pasien terjadwal mendatang' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ───────── TAB 2: REQUEST DASHBOARD ───────── -->
    <div v-else class="bdt-body">
      <div class="dash-head">
        <div>
          <div class="dash-title">Permintaan BHP &amp; IOL ke Gudang / Farmasi</div>
          <div class="dash-sub">{{ activeRequests.length }} berjalan · {{ historyRequests.length }} riwayat</div>
        </div>
        <div class="dash-actions">
          <button class="btn-stock minta" @click="showMintaModal = true">
            <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
            Minta BHP/IOL
          </button>
          <button class="btn-stock retur" @click="returPrefill = null; showReturModal = true">
            <svg viewBox="0 0 24 24"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
            Retur BHP/IOL
          </button>
        </div>
      </div>

      <!-- Sub-tab: Aktif | Riwayat -->
      <div class="sub-tabs">
        <button :class="['sub-tab', reqSubTab === 'aktif' ? 'a' : '']" @click="reqSubTab = 'aktif'">
          Berjalan <span class="sub-ct">{{ activeRequests.length }}</span>
        </button>
        <button :class="['sub-tab', reqSubTab === 'riwayat' ? 'a' : '']" @click="reqSubTab = 'riwayat'">
          Riwayat <span class="sub-ct">{{ historyRequests.length }}</span>
        </button>
      </div>

      <table class="jt">
        <thead>
          <tr>
            <th class="jt-no">No.</th>
            <th class="jt-tgl">Tanggal</th>
            <th style="width:150px">Tujuan</th>
            <th>Item</th>
            <th style="width:150px">Keterangan</th>
            <th class="jt-stat">Status</th>
            <th class="jt-act2">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(r, i) in shownRequests" :key="r.id">
            <td class="jt-no">{{ i + 1 }}</td>
            <td class="jt-tgl">
              <div class="dt-day">{{ fmtDate(r.tanggal) }}</div>
              <div v-if="r.number" class="dt-time">{{ r.number }}</div>
            </td>
            <td>
              <span :class="['source-pill', r.kind === 'retur' ? 'src-retur' : (r.kind === 'gudang' ? 'src-gudang' : 'src-jadwal')]">
                {{ r.kind === 'retur' ? 'Retur' : (r.kind === 'gudang' ? 'Inventori Farmasi' : 'Bedah') }}
              </span>
              <div v-if="r.pasienName" class="req-sub">{{ r.pasienName }}<span v-if="r.paketKode"> · {{ r.paketKode }}</span></div>
            </td>
            <td class="td-items">{{ reqItemsText(r) }}</td>
            <td class="td-ket">{{ r.keterangan || '—' }}</td>
            <td class="jt-stat"><span :class="['status-pill', statusCls[r.status]]">{{ statusLabel[r.status] ?? r.status }}</span></td>
            <td class="jt-act2">
              <button v-if="canTerima(r)" class="row-btn ok" :disabled="rowBusy === r.id" @click="terimaRow(r)">
                {{ rowBusy === r.id ? '…' : 'Terima' }}
              </button>
              <button v-if="canRetur(r)" class="row-btn warn" :disabled="rowBusy === r.id" @click="returRow(r)">Retur</button>
              <span v-if="!canTerima(r) && !canRetur(r)" class="row-dash">—</span>
            </td>
          </tr>
          <tr v-if="loadingRequests">
            <td colspan="7" class="jt-empty">Memuat permintaan…</td>
          </tr>
          <tr v-else-if="!shownRequests.length">
            <td colspan="7" class="jt-empty">
              {{ reqSubTab === 'riwayat' ? 'Belum ada riwayat permintaan.' : 'Belum ada permintaan berjalan. Klik “Minta BHP/IOL” atau buat dari Pasien Terjadwal.' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ───────── MODAL: KONFIRMASI REQUEST DARI PAKET ───────── -->
    <div v-if="showModal" class="overlay" @click.self="showModal = false">
      <div class="modal">
        <div class="modal-head">
          <div>
            <div class="modal-title">
              <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              Minta BHP/IOL ke Inventori Farmasi — {{ sourcePasien?.name }}
            </div>
            <div v-if="sourcePasien" class="modal-sub">
              {{ sourcePasien.prosedur }} · Paket {{ sourcePasien.paket.kode }} — {{ sourcePasien.paket.nama }} · isi sesuai paket bedah
            </div>
          </div>
          <button class="modal-close" @click="showModal = false">×</button>
        </div>

        <div class="modal-body">
          <div v-if="previewLoading" class="empty-inline">Memuat isi paket…</div>

          <template v-else-if="preview">
            <div v-if="previewEmpty" class="bdt-empty">Paket ini tidak punya komponen BHP/IOL (dengan master stok) untuk diminta ke gudang.</div>

            <!-- BHP (read-only, dari paket) -->
            <div v-if="preview.bhp_items.length" class="sec">
              <div class="sec-hd">BHP <span class="sec-ct">{{ preview.bhp_items.length }}</span> <span class="sec-note">sesuai paket</span></div>
              <table class="form-tbl">
                <thead><tr><th>Item</th><th style="width:90px">Jml</th><th style="width:90px">Satuan</th></tr></thead>
                <tbody>
                  <tr v-for="(b, i) in preview.bhp_items" :key="i">
                    <td>{{ b.name }}</td>
                    <td>{{ b.quantity }}</td>
                    <td>{{ b.unit || '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- IOL (read-only — hanya yang punya master stok yang dikirim) -->
            <div v-if="iolSendable.length" class="sec">
              <div class="sec-hd">IOL <span class="sec-ct">{{ iolSendable.length }}</span> <span class="sec-note">sesuai paket</span></div>
              <table class="form-tbl">
                <thead><tr><th>Item</th><th style="width:90px">Jml</th></tr></thead>
                <tbody>
                  <tr v-for="(o, i) in iolSendable" :key="i">
                    <td>{{ o.master_label || 'IOL' }}</td>
                    <td>{{ o.quantity ?? 1 }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="iolSkipped" class="empty-inline">
              {{ iolSkipped }} item IOL di paket tidak terhubung master stok — tidak ikut dikirim ke gudang.
            </div>
          </template>
        </div>

        <div class="modal-foot">
          <button class="btn-sec" @click="showModal = false">Batal</button>
          <button class="btn-primary" :disabled="submitting || previewLoading || previewEmpty" @click="submitRequest">
            <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            {{ submitting ? 'Mengirim…' : 'Kirim ke Inventori Farmasi' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ───────── MODAL: MINTA & RETUR STOK KE GUDANG ───────── -->
    <RequestBhpIolModal
      :open="showMintaModal" :bhp="bhpOptions" :iol="iolOptions"
      @close="showMintaModal = false" @changed="onStockChanged"
    />
    <ReturBhpIolModal
      :open="showReturModal" :bhp="bhpOptions" :iol="iolOptions" :prefill="returPrefill"
      @close="showReturModal = false; returPrefill = null" @changed="onStockChanged"
    />

    <!-- ───────── TOAST ───────── -->
    <div class="toast-wrap">
      <div v-for="t in toasts" :key="t.id" :class="['toast', `toast-${t.type}`]">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
.bdt { padding: 1rem 1.25rem; }

/* Header */
.bdt-head { margin-bottom: 1rem; }
.bdt-head h1 { font-family: 'DM Serif Display', serif; font-size: 22px; color: var(--gd); line-height: 1.1; }
.bdt-sub { font-size: 12px; color: var(--tu); margin-top: 4px; }

/* Tabs */
.bdt-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); margin-bottom: 1rem; padding: 0 4px; }
.bdt-tab { display: inline-flex; align-items: center; gap: 7px; padding: 10px 16px; font-size: 12.5px; font-weight: 600; color: var(--tu); background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: 'DM Sans', sans-serif; transition: color .14s; }
.bdt-tab:hover { color: var(--td); }
.bdt-tab.a { color: var(--ga); border-bottom-color: var(--ga); }
.bdt-tab svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.bdt-tab-ct { font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 10px; background: var(--gl); color: var(--ga); }
.bdt-tab.a .bdt-tab-ct { background: var(--ga); color: #fff; }

.bdt-body { min-height: 50vh; }
.bdt-empty { text-align: center; padding: 2rem; font-size: 13px; color: var(--th); background: var(--bi); border: 1px dashed var(--gb); border-radius: 10px; }

/* ── TAB 1: weekpicker toolbar ── */
.wk-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 0.9rem; flex-wrap: wrap; }
.wk-nav { display: inline-flex; align-items: center; gap: 4px; }
.wk-btn { width: 30px; height: 30px; border: 1.5px solid var(--gb); background: var(--bc); border-radius: 7px; font-size: 16px; line-height: 1; color: var(--tm); cursor: pointer; font-family: 'DM Sans', sans-serif; }
.wk-btn:hover { border-color: var(--ga); color: var(--ga); }
.wk-date { height: 30px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 8px; font-size: 12px; font-family: 'DM Sans', sans-serif; background: var(--bs); color: var(--td); outline: none; }
.wk-date:focus { border-color: var(--ga); background: #fff; }
.wk-label { font-size: 12.5px; font-weight: 700; color: var(--gd); }
.wk-clear { font-size: 11px; font-weight: 600; color: var(--ga); background: var(--gl); border: 1.5px solid var(--ga); border-radius: 7px; padding: 5px 10px; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.wk-clear:hover { background: var(--ga); color: #fff; }

/* ── TAB 1: tabel jadwal ── */
.jt { width: 100%; border-collapse: collapse; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; font-size: 12px; }
.jt thead th { background: var(--bs); font-size: 10.5px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.05em; text-align: left; padding: 10px 14px; border-bottom: 1px solid var(--gb); }
.jt tbody td { padding: 11px 14px; border-bottom: 1px solid var(--gb); vertical-align: top; }
.jt tbody tr:last-child td { border-bottom: none; }
.jt tbody tr:hover td { background: var(--gl); }
.jt-no { width: 46px; text-align: center; font-variant-numeric: tabular-nums; font-weight: 700; color: var(--tu); }
.jt-tgl { width: 168px; }
.jt-act { width: 158px; text-align: right; }
.jt-empty { text-align: center; padding: 1.8rem; color: var(--th); font-size: 13px; }

.dt-day { font-weight: 700; color: var(--gd); }
.dt-time { font-size: 11px; color: var(--tu); margin-top: 2px; }
.pt-name { font-size: 13px; font-weight: 600; color: var(--td); }
.pt-meta { font-size: 11px; color: var(--tu); margin-top: 2px; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.pt-dpjp { font-size: 10.5px; color: var(--tu); margin-top: 3px; }
.ptype { font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 4px; }
.ptype-bpjs { background: #dbeafe; color: #1e40af; }
.ptype-umum { background: var(--gl); color: var(--ga); }
.ptype-asn  { background: var(--wb); color: var(--wt); }

.td-dx { color: var(--td); line-height: 1.5; }
.td-op { color: var(--gm); font-weight: 500; line-height: 1.5; }

.jt-iol { width: 140px; }
.iol-eye { display: flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 600; color: var(--td); white-space: nowrap; }
.iol-eye + .iol-eye { margin-top: 4px; }
.iol-tag { flex: none; width: 24px; text-align: center; font-size: 9px; font-weight: 700; padding: 1px 0; border-radius: 4px; background: #ede9fe; color: #5b21b6; }
.iol-type { font-size: 9.5px; font-weight: 600; color: var(--tu); }
.iol-empty { color: var(--th); }

.paket-kode { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 2px 7px; background: var(--gl); color: var(--ga); border-radius: 4px; margin-bottom: 3px; font-family: 'DM Mono', monospace; }
.paket-nama { font-size: 11.5px; color: var(--td); line-height: 1.4; }
.paket-count { font-size: 10px; color: var(--tu); margin-top: 3px; }

.btn-req { display: inline-flex; align-items: center; gap: 5px; padding: 7px 12px; background: var(--ga); color: #fff; border: none; border-radius: 8px; font-size: 11.5px; font-weight: 600; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: background .14s; white-space: nowrap; }
.btn-req:hover { background: var(--gm); }
.btn-req svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.no-visit { font-size: 11px; color: var(--th); font-style: italic; white-space: nowrap; }

/* ── TAB 2: dashboard ── */
.dash-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.dash-title { font-size: 14px; font-weight: 700; color: var(--td); }
.dash-sub { font-size: 11.5px; color: var(--tu); margin-top: 3px; }
.btn-add { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; background: var(--ga); color: #fff; border: none; border-radius: 8px; font-size: 12.5px; font-weight: 600; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: background .14s; }
.btn-add:hover { background: var(--gm); }
.btn-add svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }

.dash-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.btn-stock { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 600; cursor: pointer; font-family: 'DM Sans', sans-serif; border: 1.5px solid; background: var(--bc); transition: all .14s; }
.btn-stock svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
.btn-stock.minta { color: #1763d4; border-color: #1763d4; }
.btn-stock.minta:hover { background: #1763d4; color: #fff; }
.btn-stock.retur { color: #b45309; border-color: #f59e0b; }
.btn-stock.retur:hover { background: #f59e0b; color: #fff; }

.dash-group { margin-bottom: 1.25rem; }
.dash-date { display: flex; align-items: center; gap: 7px; font-size: 11.5px; font-weight: 700; color: var(--gd); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.55rem; padding: 0 4px; }
.dash-date svg { width: 13px; height: 13px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.dash-date-ct { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 10px; background: var(--gl); color: var(--ga); margin-left: 4px; }

.req-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 11px; padding: 12px 14px; margin-bottom: 8px; }
.req-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 9px; flex-wrap: wrap; gap: 8px; }
.req-source { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.source-pill { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 20px; }
.source-pill svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.src-jadwal { background: #dbeafe; color: #1e40af; }
.src-manual { background: var(--gl); color: var(--ga); }
.src-gudang { background: #fef3c7; color: #92400e; }
.req-pasien { font-size: 12.5px; font-weight: 600; color: var(--td); }
.req-paket { font-size: 10px; font-weight: 700; padding: 2px 6px; background: var(--bi); color: var(--tu); border-radius: 4px; font-family: 'DM Mono', monospace; }

.status-pill { font-size: 10px; font-weight: 700; padding: 3px 10px; border-radius: 20px; letter-spacing: 0.03em; }
.st-pending   { background: #fef3c7; color: #92400e; }
.st-approved  { background: var(--sb); color: var(--st); }
.st-rejected  { background: var(--eb); color: var(--et); }
.st-delivered { background: #dbeafe; color: #1e40af; }

/* tab 2 — sub-tabs + tabel permintaan */
.sub-tabs { display: flex; gap: 4px; margin-bottom: 0.8rem; }
.sub-tab { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; font-size: 12px; font-weight: 600; color: var(--tu); background: var(--bs); border: 1.5px solid var(--gb); border-radius: 8px; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.sub-tab:hover { border-color: var(--ga); color: var(--ga); }
.sub-tab.a { background: var(--ga); border-color: var(--ga); color: #fff; }
.sub-ct { font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 10px; background: rgba(0,0,0,.08); }
.sub-tab.a .sub-ct { background: rgba(255,255,255,.25); }

.jt-stat { width: 110px; }
.jt-act2 { width: 120px; text-align: right; }
.req-sub { font-size: 10.5px; color: var(--tu); margin-top: 4px; }
.td-items { color: var(--td); line-height: 1.5; }
.td-ket { font-size: 11px; color: var(--tm); line-height: 1.4; }
.src-retur { background: var(--eb); color: var(--et); }
.row-btn { padding: 5px 11px; font-size: 11px; font-weight: 600; border-radius: 6px; border: 1.5px solid; cursor: pointer; font-family: 'DM Sans', sans-serif; background: var(--bc); margin-left: 4px; }
.row-btn:disabled { opacity: .5; cursor: not-allowed; }
.row-btn.ok { color: #166534; border-color: #16a34a; }
.row-btn.ok:hover:not(:disabled) { background: #16a34a; color: #fff; }
.row-btn.warn { color: #b45309; border-color: #f59e0b; }
.row-btn.warn:hover:not(:disabled) { background: #f59e0b; color: #fff; }
.row-dash { color: var(--th); }

.req-items { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 9px; }
.items-block { background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; padding: 8px 10px; }
.items-block.items-iol { background: #faf5ff; border-color: #e9d5ff; }
.items-label { font-size: 10px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
.items-tbl { width: 100%; border-collapse: collapse; font-size: 11.5px; }
.items-tbl td { padding: 3px 0; color: var(--td); }
.items-tbl td.num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; color: var(--gm); white-space: nowrap; padding-left: 8px; }
.iol-pwr { display: inline-block; margin-left: 5px; font-size: 10px; font-weight: 700; color: #7e22ce; background: #f3e8ff; padding: 1px 5px; border-radius: 4px; }

.req-notes { font-size: 11.5px; color: var(--tm); padding: 6px 10px; background: var(--bi); border-radius: 6px; line-height: 1.45; margin-bottom: 7px; }
.req-foot { display: flex; justify-content: space-between; font-size: 10.5px; color: var(--tu); }

/* ── MODAL ── */
.overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 200; padding: 1rem; }
.modal { background: var(--bc); border-radius: 14px; width: 100%; max-width: 720px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.15); overflow: hidden; }
.modal-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--gb); }
.modal-title { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: var(--td); }
.modal-title svg { width: 16px; height: 16px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.modal-sub { font-size: 11.5px; color: var(--tu); margin-top: 4px; line-height: 1.5; }
.modal-close { width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--gb); background: var(--bs); font-size: 16px; cursor: pointer; color: var(--tu); flex-shrink: 0; line-height: 1; }
.modal-close:hover { background: var(--eb); color: var(--et); }

.modal-body { padding: 16px 18px; overflow-y: auto; }
.fld { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
.fld label { font-size: 10.5px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.04em; }
.fld input, .fld textarea { padding: 8px 10px; border: 1.5px solid var(--gb); border-radius: 8px; font-size: 12.5px; font-family: 'DM Sans', sans-serif; background: var(--bs); color: var(--td); outline: none; resize: vertical; }
.fld input:focus, .fld textarea:focus { border-color: var(--ga); background: #fff; }
.fld input[type="date"] { max-width: 200px; }

.sec { margin-bottom: 14px; padding: 11px 12px; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; }
.sec-hd { font-size: 11px; font-weight: 700; color: var(--td); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.sec-ct { font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 10px; background: var(--gl); color: var(--ga); }
.sec-note { font-size: 9.5px; font-weight: 600; color: var(--tu); text-transform: none; letter-spacing: 0; }
.empty-inline { font-size: 11px; color: var(--th); font-style: italic; padding: 6px 0; }
.rec-badge { display: inline-block; font-size: 9px; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: #dbeafe; color: #1e40af; text-transform: uppercase; letter-spacing: .03em; }

.form-tbl { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 8px; background: var(--bc); border-radius: 6px; overflow: hidden; }
.form-tbl th { background: var(--bi); padding: 6px 8px; text-align: left; font-size: 10px; font-weight: 700; color: var(--tu); text-transform: uppercase; }
.form-tbl td { padding: 6px 8px; border-top: 1px solid var(--bg); color: var(--td); }
.qty-input { width: 56px; padding: 4px 6px; border: 1px solid var(--gb); border-radius: 5px; font-size: 12px; font-family: 'DM Sans', sans-serif; text-align: center; }
.del-btn { background: none; border: none; color: var(--et); cursor: pointer; font-size: 13px; padding: 2px 6px; border-radius: 4px; }
.del-btn:hover { background: var(--eb); }

.add-row { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 6px; }
.add-row select, .add-row input { padding: 6px 8px; border: 1px solid var(--gb); border-radius: 6px; font-size: 12px; font-family: 'DM Sans', sans-serif; background: var(--bc); }
.add-row select { flex: 2; }
.btn-add-sm { padding: 6px 12px; background: var(--gl); border: 1px solid var(--ga); color: var(--gm); border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; white-space: nowrap; font-family: 'DM Sans', sans-serif; }
.btn-add-sm:hover { background: var(--ga); color: #fff; }

.modal-foot { display: flex; gap: 8px; justify-content: flex-end; padding: 12px 18px; border-top: 1px solid var(--gb); background: var(--bs); }
.btn-sec { padding: 9px 18px; background: var(--bg); border: 1px solid var(--gb); color: var(--tm); border-radius: 8px; font-size: 12.5px; font-weight: 600; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.btn-sec:hover { border-color: var(--ga); }
.btn-primary { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; background: var(--ga); color: #fff; border: none; border-radius: 8px; font-size: 12.5px; font-weight: 700; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.btn-primary:hover { background: var(--gm); }
.btn-primary svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

/* ── TOAST ── */
.toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 999; display: flex; flex-direction: column; gap: 6px; }
.toast { padding: 9px 13px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 12px rgba(0,0,0,.08); min-width: 230px; max-width: 320px; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
</style>
