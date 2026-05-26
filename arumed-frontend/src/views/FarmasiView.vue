<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { farmasiApi } from '@/services/api'

const pgTab = ref('dispensing')

// ─── Antrean Farmasi ────────────────────────────────────────────────────────
const queue          = ref([])
const queueLoading   = ref(false)
const queueError     = ref('')
const rxTab          = ref('Semua')
const rxSearch       = ref('')

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

const filtRx = computed(() => {
  let l = queue.value
  if (rxTab.value === 'Semua')         l = l.filter((q) => rxStatusOf(q) !== 'done')
  else if (rxTab.value === 'Menunggu') l = l.filter((q) => ['menunggu','verifikasi','disiapkan'].includes(rxStatusOf(q)))
  else if (rxTab.value === 'BPJS')     l = l.filter((q) => guarantorType(q) === 'bpjs' && rxStatusOf(q) !== 'done')
  else if (rxTab.value === 'Umum')     l = l.filter((q) => guarantorType(q) !== 'bpjs' && rxStatusOf(q) !== 'done')
  else if (rxTab.value === 'Selesai')  l = l.filter((q) => rxStatusOf(q) === 'done')
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

// ─── Lifecycle / polling ────────────────────────────────────────────────────
let _poll = null
onMounted(() => {
  fetchQueue()
  fetchStok()
  _poll = setInterval(fetchQueue, 30_000)
})
onUnmounted(() => { if (_poll) clearInterval(_poll) })

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
    <div v-if="pgTab === 'dispensing'">
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
          <div class="rx-head">
            <div class="rx-title">Antrean Resep</div>
            <input v-model="rxSearch" class="fi" placeholder="Cari..." />
          </div>
          <div class="filter-tabs">
            <button v-for="t in ['Semua', 'Menunggu', 'BPJS', 'Umum', 'Selesai']" :key="t" :class="['ft', rxTab === t ? 'a' : '']" @click="rxTab = t">
              {{ t }}
              <span v-if="t === 'Selesai' && queue.filter((q) => rxStatusOf(q) === 'done').length" class="ft-badge">{{ queue.filter((q) => rxStatusOf(q) === 'done').length }}</span>
            </button>
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
    <div v-if="pgTab === 'stok'">
      <div class="stok-head">
        <input v-model="stokSearch" class="fi" placeholder="Cari obat..." style="width: 220px" />
        <button class="btn btn-primary btn-sm" @click="toast('i', 'Modal Tambah Stok belum diimplementasikan')">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Stok Masuk
        </button>
      </div>
      <div v-if="lowStockCount" class="low-alert">
        <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
        {{ lowStockCount }} item stok low/habis: {{ stokList.filter((s) => Number(s.stock) <= Number(s.min_stock ?? 0)).map((s) => s.name).join(', ') }}
      </div>
      <div class="card">
        <table class="tbl">
          <thead>
            <tr>
              <th>Nama Produk</th>
              <th>Formularium</th>
              <th class="num">Stok</th>
              <th class="num">Min</th>
              <th>Unit</th>
              <th>Batch</th>
              <th>Exp</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="stokLoading && !stokList.length"><td colspan="7" class="muted" style="text-align:center;padding:1rem">Memuat stok…</td></tr>
            <tr v-for="s in stokFiltered" :key="s.id ?? s.name">
              <td><b>{{ s.name }}</b></td>
              <td><span class="kategori-pill">{{ s.formularium ?? '—' }}</span></td>
              <td class="num">
                <div class="stok-cell">
                  <span :class="{ out: Number(s.stock) === 0, low: Number(s.stock) > 0 && Number(s.stock) <= Number(s.min_stock ?? 0) }">{{ s.stock }}</span>
                  <div class="bar"><div :class="['bar-fill', Number(s.stock) === 0 ? 'out' : Number(s.stock) <= Number(s.min_stock ?? 0) ? 'low' : 'ok']" :style="{ width: Math.min((Number(s.stock) / Math.max(Number(s.min_stock ?? 0) * 5, 1)) * 100, 100) + '%' }"></div></div>
                </div>
              </td>
              <td class="num muted">{{ s.min_stock ?? '—' }}</td>
              <td class="muted">{{ s.unit ?? '—' }}</td>
              <td class="muted">{{ s.batch_number ?? '—' }}</td>
              <td class="muted">{{ s.expiry_date ? new Date(s.expiry_date).toLocaleDateString('id-ID', { month: '2-digit', year: 'numeric' }) : '—' }}</td>
            </tr>
            <tr v-if="!stokLoading && !stokFiltered.length"><td colspan="7" class="muted" style="text-align:center;padding:1rem">Tidak ada data stok</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="pgTab === 'opname'" class="placeholder-card">
      Stok Opname — modul rekonsiliasi fisik vs sistem akan dibangun di iterasi berikutnya.
    </div>
    <div v-if="pgTab === 'laporan'" class="placeholder-card">
      Laporan farmasi (pemakaian harian, top-N obat, expired items) akan dibangun di iterasi berikutnya.
    </div>

    <div class="toast-wrap">
      <div v-for="t in toasts" :key="t.id" :class="['toast', `toast-${t.type}`]">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
.farmasi { display: flex; flex-direction: column; gap: 1rem; }

.nav-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); padding: 0 4px; }
.nt { padding: 0.6rem 1rem; font-size: 12px; font-weight: 500; color: var(--tu); background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: 'DM Sans', sans-serif; display: inline-flex; align-items: center; gap: 6px; }
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
.rx-col { display: flex; flex-direction: column; gap: 0.5rem; }
.rx-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.rx-title { font-size: 11.5px; font-weight: 600; color: var(--tm); }
.fi { height: 28px; font-size: 11px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 9px; background: var(--bs); font-family: 'DM Sans', sans-serif; outline: none; color: var(--td); width: 110px; }
.fi:focus { border-color: var(--ga); background: #fff; }
.filter-tabs { display: flex; gap: 3px; }
.ft { padding: 4px 10px; font-size: 10.5px; font-weight: 500; color: var(--tu); background: var(--bs); border: 1.5px solid var(--gb); border-radius: 7px; cursor: pointer; font-family: 'DM Sans', sans-serif; display: inline-flex; align-items: center; }
.ft.a { background: var(--gd); color: #fff; border-color: var(--gd); font-weight: 600; }
.ft-badge { font-size: 8.5px; font-weight: 700; padding: 1px 5px; border-radius: 10px; background: var(--sb); color: var(--st); margin-left: 3px; }

.rx-list { display: flex; flex-direction: column; gap: 5px; max-height: calc(100vh - 280px); overflow-y: auto; padding-right: 4px; }
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
.rx-act-btn { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; font-size: 10px; font-weight: 600; border-radius: 5px; border: 1px solid; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .12s; }
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
.disp-title { font-family: 'DM Serif Display', serif; font-size: 16px; line-height: 1.1; }
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
.dd-qty { width: 60px; height: 30px; border: 1.5px solid var(--gb); border-radius: 6px; padding: 0 8px; text-align: center; font-size: 12px; font-weight: 600; outline: none; font-family: 'DM Sans', sans-serif; background: var(--bs); }
.dd-qty:focus { border-color: var(--ga); background: #fff; }
.dd-unit { font-size: 9px; color: var(--tu); }

.doc-note { margin: 0.65rem 1.1rem; padding: 7px 11px; background: var(--ib); border: 1px solid var(--ibd); color: var(--it); border-radius: 7px; font-size: 11px; }

/* Obat Bebas (OTC) form */
.otc-section { padding: 0.6rem 1.1rem; border-top: 1px dashed var(--gb); }
.otc-toggle { gap: 5px; }
.otc-form { background: var(--gl); border: 1px solid var(--ga); border-radius: 9px; padding: .7rem .9rem; }
.otc-form-title { font-size: 10.5px; font-weight: 700; color: var(--gm); margin-bottom: .5rem; text-transform: uppercase; letter-spacing: .04em; }
.otc-fields { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .55rem; }
.otc-field { display: flex; flex-direction: column; gap: 2px; }
.otc-wide { flex: 2; min-width: 160px; }
.otc-narrow { flex: 0 0 70px; }
.otc-field:not(.otc-wide):not(.otc-narrow) { flex: 1; min-width: 110px; }
.otc-label { font-size: 9px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: .03em; }
.otc-input { height: 28px; font-size: 11px; width: 100%; box-sizing: border-box; }
.otc-form-actions { display: flex; gap: .4rem; }

.disp-actions { padding: 0.85rem 1.1rem; border-top: 1px solid var(--gb); display: flex; gap: 0.5rem; flex-wrap: wrap; background: var(--bs); }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 0 14px; height: 36px; border-radius: 8px; font-family: 'DM Sans', sans-serif; font-size: 12.5px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; }
.btn-sm { height: 28px; padding: 0 10px; font-size: 11px; }
.btn-lg { height: 42px; padding: 0 18px; font-size: 13px; font-weight: 600; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover { background: var(--gm); }
.btn-info { background: var(--it); color: #fff; border-color: var(--it); }
.btn-info:hover { background: #1e40af; }
.btn-warning { background: var(--lm); color: var(--gd); border-color: var(--lm); }
.btn-warning:hover { background: var(--ld); color: #fff; }
.btn-success { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-success:hover:not(:disabled) { background: var(--gm); }
.btn-success:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover { border-color: var(--ga); color: var(--gd); background: var(--gl); }
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

.done-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; font-size: 12px; font-weight: 600; }
.done-pill svg { width: 14px; height: 14px; }

/* STOK */
.stok-head { display: flex; gap: 0.5rem; align-items: center; justify-content: flex-end; margin-bottom: 0.75rem; }
.stok-head .fi { width: 220px; }
.low-alert { display: flex; align-items: center; gap: 8px; padding: 9px 13px; background: var(--eb); border: 1px solid var(--ebd); border-radius: 9px; color: var(--et); font-size: 11.5px; margin-bottom: 0.75rem; }
.low-alert svg { width: 16px; height: 16px; fill: none; stroke: var(--et); stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }

.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
.tbl { width: 100%; border-collapse: collapse; }
.tbl th { font-size: 10px; font-weight: 600; color: var(--tu); letter-spacing: 0.06em; text-transform: uppercase; padding: 10px 13px; border-bottom: 1px solid var(--gb); text-align: left; }
.tbl td { padding: 9px 13px; border-bottom: 1px solid rgba(0, 0, 0, 0.03); font-size: 12px; color: var(--td); }
.tbl tr:last-child td { border-bottom: none; }
.tbl tr:hover td { background: var(--bi); }
.tbl .num { text-align: right; font-variant-numeric: tabular-nums; }
.tbl .muted { color: var(--tu); }
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

.toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 999; display: flex; flex-direction: column; gap: 6px; }
.toast { padding: 9px 13px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); min-width: 230px; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
</style>
