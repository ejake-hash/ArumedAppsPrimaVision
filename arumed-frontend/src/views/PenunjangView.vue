<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { usePenunjangStore } from '@/stores/penunjangStore'
import PatientAvatar from '@/components/common/PatientAvatar.vue'
import JenisPenunjangView from '@/views/master-data/JenisPenunjangView.vue'

const store = usePenunjangStore()

// ─── Tab utama modul: operasional antrean vs master jenis penunjang ───────────
const mainTab = ref('antrean')   // 'antrean' | 'jenis'

// ─── Local UI state ─────────────────────────────────────────────────────────
const qTab    = ref('SEMUA')     // 'SEMUA' | 'BPJS' | 'UMUM' | 'SELESAI'
const qSearch = ref('')

const toasts          = ref([])
const pendingCallIds  = ref([])
let   _tid            = 0

// ─── Filtered queue ─────────────────────────────────────────────────────────
const filteredQueue = computed(() => {
  let list = store.antrian

  if (qTab.value === 'SELESAI') {
    list = list.filter((q) => q.status === 'COMPLETED')
  } else {
    list = list.filter((q) => q.status !== 'COMPLETED')
    if (qTab.value === 'BPJS') {
      list = list.filter((q) => q.visit?.guarantor_type === 'BPJS')
    } else if (qTab.value === 'UMUM') {
      list = list.filter((q) => q.visit?.guarantor_type && q.visit.guarantor_type !== 'BPJS')
    }
  }

  if (qSearch.value) {
    const s = qSearch.value.toLowerCase()
    list = list.filter(
      (q) =>
        q.patient?.name?.toLowerCase().includes(s) ||
        q.queue_number?.toLowerCase().includes(s) ||
        q.patient?.no_rm?.toLowerCase().includes(s),
    )
  }
  return list
})

const tabCounts = computed(() => {
  const all = store.antrian
  return {
    SEMUA:   all.filter((q) => q.status !== 'COMPLETED').length,
    BPJS:    all.filter((q) => q.status !== 'COMPLETED' && q.visit?.guarantor_type === 'BPJS').length,
    UMUM:    all.filter((q) => q.status !== 'COMPLETED' && q.visit?.guarantor_type && q.visit.guarantor_type !== 'BPJS').length,
    SELESAI: all.filter((q) => q.status === 'COMPLETED').length,
  }
})

// ─── Classification helpers ─────────────────────────────────────────────────
const classColor = { Baru: 'cls-baru', 'Pre-Op': 'cls-preop', 'Post-Op': 'cls-postop', Kontrol: 'cls-kontrol' }
function clsCls(c) { return classColor[c] ?? 'cls-baru' }

// ─── Queue actions ──────────────────────────────────────────────────────────
async function pickPatient(q) {
  if (store.selectedQueue?.id === q.id) return
  store.pickPatient(q)
  toast('i', `Order — ${q.patient?.name ?? '—'} dibuka`)
}

async function callPt(q, e) {
  e.stopPropagation()
  if (pendingCallIds.value.includes(q.id)) return
  const isRecall = q.status !== 'WAITING'
  pendingCallIds.value.push(q.id)
  try {
    await store.panggilAntrian(q.id)
    toast('i', `${isRecall ? 'Memanggil ulang' : 'Memanggil'} ${q.patient?.name} (${q.queue_number}) ke ruang penunjang`)
  } catch (err) {
    toast('w', err.message)
  } finally {
    pendingCallIds.value = pendingCallIds.value.filter((id) => id !== q.id)
  }
}

function skipPt(q, e) {
  e.stopPropagation()
  const arr = store.antrian
  const idx = arr.findIndex((x) => x.id === q.id)
  if (idx === -1) return
  if (idx >= arr.length - 1) {
    toast('w', `${q.patient?.name} sudah di posisi paling bawah`)
    return
  }
  const next = arr[idx + 1]
  arr.splice(idx, 2, next, q)
  if (store.selectedQueue?.id === q.id) store.clearSelected()
  toast('w', `${q.patient?.name} (${q.queue_number}) diturunkan 1 posisi`)
}

async function finishExam() {
  const q = store.selectedQueue
  if (!q) return
  try {
    const name = q.patient?.name ?? 'Pasien'
    await store.selesaiAntrian(q.id)
    toast('s', `${name} selesai pemeriksaan — dikembalikan ke antrean dokter`)
    store.clearSelected()
  } catch (err) {
    toast('w', err.message)
  }
}

// ─── Toast ─────────────────────────────────────────────────────────────────
function toast(type, msg) {
  const id = ++_tid
  toasts.value.push({ id, type, msg })
  setTimeout(() => { toasts.value = toasts.value.filter((t) => t.id !== id) }, 3200)
}

// ─── Helpers ───────────────────────────────────────────────────────────────
function fmtTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
}

const statusLabel = { WAITING: 'Menunggu', CALLED: 'Dipanggil', IN_PROGRESS: 'Proses', COMPLETED: 'Selesai' }

// ─── Diagnostic orders for selected visit ──────────────────────────────────
const selectedOrders = computed(() => {
  return store.selectedQueue?.visit?.diagnostic_orders ?? []
})

const orderStatusLabel = { REQUESTED: 'Menunggu', IN_PROGRESS: 'Berlangsung', DONE: 'Selesai', CANCELLED: 'Dibatalkan' }
const orderStatusCls   = { REQUESTED: 'req', IN_PROGRESS: 'prg', DONE: 'don', CANCELLED: 'cnc' }

// ─── Lifecycle ─────────────────────────────────────────────────────────────
onMounted(async () => {
  await store.fetchAntrian()
  store.startPolling()
})

onUnmounted(() => {
  store.stopPolling()
  store.clearSelected()
})
</script>

<template>
  <div class="penunjang">

    <!-- ══════════════════ TAB MODUL ══════════════════ -->
    <div class="pj-maintabs">
      <button :class="['pj-maintab', mainTab === 'antrean' ? 'a' : '']" @click="mainTab = 'antrean'">
        <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        Antrean Penunjang
      </button>
      <button :class="['pj-maintab', mainTab === 'jenis' ? 'a' : '']" @click="mainTab = 'jenis'">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2v-4M9 21H5a2 2 0 01-2-2v-4"/></svg>
        Jenis Penunjang
      </button>
    </div>

    <div v-if="mainTab === 'antrean'" class="main-grid">

      <!-- ══════════════════ LEFT: QUEUE ══════════════════ -->
      <aside class="col-queue">
        <div class="card">
          <div class="card-head">
            <div>
              <div class="card-head-title">
                <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Antrean Penunjang
              </div>
              <div class="card-head-sub">{{ store.totalCount }} pasien hari ini</div>
            </div>
            <span class="pill-live"><span class="live-dot"></span>LIVE</span>
          </div>

          <div class="card-body queue-scroll" role="region" aria-label="Daftar antrean penunjang">

            <!-- Stats bar -->
            <div class="stats-bar">
              <div class="stat-item">
                <span class="stat-label">Belum Dipanggil</span>
                <b class="stat-num stat-waiting">{{ store.belumDipanggilCount }}</b>
              </div>
              <div class="stat-divider"></div>
              <div class="stat-item">
                <span class="stat-label">Selesai</span>
                <b class="stat-num stat-done">{{ store.selesaiCount }}</b>
              </div>
              <div class="stat-divider"></div>
              <div class="stat-item">
                <span class="stat-label">Total</span>
                <b class="stat-num">{{ store.totalCount }}</b>
              </div>
            </div>

            <!-- Filter tabs: Semua / BPJS / UMUM-ASURANSI / Selesai -->
            <div class="ptype-tabs" role="group" aria-label="Filter antrean">
              <button :class="['ptype-tab', qTab === 'SEMUA' ? 'a a-default' : '']" @click="qTab = 'SEMUA'">
                Semua <span class="tab-ct">{{ tabCounts.SEMUA }}</span>
              </button>
              <button :class="['ptype-tab ptype-bpjs', qTab === 'BPJS' ? 'a' : '']" @click="qTab = 'BPJS'">
                BPJS <span class="tab-ct">{{ tabCounts.BPJS }}</span>
              </button>
              <button :class="['ptype-tab ptype-umum', qTab === 'UMUM' ? 'a' : '']" @click="qTab = 'UMUM'">
                Umum/Asuransi <span class="tab-ct">{{ tabCounts.UMUM }}</span>
              </button>
              <button :class="['ptype-tab ptype-done', qTab === 'SELESAI' ? 'a' : '']" @click="qTab = 'SELESAI'">
                Selesai <span class="tab-ct">{{ tabCounts.SELESAI }}</span>
              </button>
            </div>

            <!-- Search -->
            <div class="q-search-wrap">
              <input v-model="qSearch" class="q-search" placeholder="Cari nama / no. antrean / RM…" />
            </div>

            <!-- Loading skeleton -->
            <template v-if="store.queueLoading">
              <div v-for="n in 4" :key="n" class="q-skeleton"></div>
            </template>

            <!-- Error -->
            <div v-else-if="store.queueError" class="empty-section err">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              {{ store.queueError }}
              <button class="retry-btn" @click="store.fetchAntrian()">Coba lagi</button>
            </div>

            <!-- Empty -->
            <div v-else-if="!filteredQueue.length" class="empty-section" aria-live="polite">
              Tidak ada pasien dalam filter ini
            </div>

            <!-- Queue list -->
            <div v-else role="list" aria-label="Daftar antrean penunjang">
              <div
                v-for="q in filteredQueue" :key="q.id"
                role="listitem"
                :class="['q-item',
                  store.selectedQueue?.id === q.id ? 'active' : '',
                  q.status === 'COMPLETED' ? 'done' : '',
                ]"
                tabindex="0"
                @click="pickPatient(q)"
                @keydown.enter="pickPatient(q)"
              >
                <div class="qi-left">
                  <div class="q-num">{{ q.queue_number }}</div>
                  <span :class="['pill', `pill-${q.status.toLowerCase()}`]">
                    <svg v-if="q.status === 'WAITING'"      viewBox="0 0 24 24" class="pill-icon"><path d="M5 2h14M5 22h14M6 2v5l4 5-4 5v5M18 2v5l-4 5 4 5v5"/></svg>
                    <svg v-else-if="q.status === 'CALLED'"  viewBox="0 0 24 24" class="pill-icon"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 014.69 12a19.79 19.79 0 01-3.07-8.67A2 2 0 013.6 1.27h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.91 8.91a16 16 0 006.18 6.18l.96-.96a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                    <svg v-else-if="q.status === 'IN_PROGRESS'" viewBox="0 0 24 24" class="pill-icon"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                    <svg v-else viewBox="0 0 24 24" class="pill-icon"><polyline points="20 6 9 17 4 12"/></svg>
                    {{ statusLabel[q.status] }}
                  </span>
                </div>

                <div class="q-info">
                  <div class="q-name">{{ q.patient?.name ?? '—' }}</div>
                  <div class="q-meta">
                    {{ q.patient?.age ?? '—' }} th
                    · {{ q.patient?.gender === 'L' ? 'L' : 'P' }}
                    · {{ q.visit?.classification ?? '—' }}
                  </div>
                  <div class="q-tags">
                    <span :class="['pill', q.visit?.guarantor_type === 'BPJS' ? 'pill-bpjs' : 'pill-umum']">
                      {{ q.visit?.guarantor_type === 'BPJS' ? 'BPJS' : q.visit?.guarantor_type ?? 'Umum' }}
                    </span>
                    <span v-if="q.visit?.classification" :class="['pill', clsCls(q.visit.classification)]">
                      {{ q.visit.classification }}
                    </span>
                    <span v-if="q.visit?.diagnostic_orders?.length" class="pill pill-order">
                      {{ q.visit.diagnostic_orders.length }} order
                    </span>
                  </div>
                  <div v-if="q.status !== 'COMPLETED'" class="q-actions" @click.stop>
                    <button
                      :class="['q-act-btn', 'call', q.status !== 'WAITING' ? 'recall' : '']"
                      :disabled="pendingCallIds.includes(q.id)"
                      @click="callPt(q, $event)"
                    >
                      <svg v-if="q.status === 'WAITING'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 014.69 12a19.79 19.79 0 01-3.07-8.67A2 2 0 013.6 1.27h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.91 8.91a16 16 0 006.18 6.18l.96-.96a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                      <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                      {{ q.status === 'WAITING' ? 'Panggil' : 'Panggil Ulang' }}
                    </button>
                    <button class="q-act-btn skip" @click="skipPt(q, $event)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="7 13 12 18 17 13"/><polyline points="7 6 12 11 17 6"/></svg>
                      Lewati
                    </button>
                  </div>
                </div>

                <div class="qi-time">{{ fmtTime(q.created_at) }}</div>
              </div>
            </div>
          </div>
        </div>
      </aside>

      <!-- ══════════════════ RIGHT: WORK AREA ══════════════════ -->
      <section class="col-form">

        <!-- Empty state -->
        <div v-if="!store.selectedQueue" class="card empty-card">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
          <p>Pilih pasien dari daftar antrean<br />untuk mulai input hasil pemeriksaan penunjang</p>
        </div>

        <template v-else>
          <!-- Patient header -->
          <div class="pt-header">
            <PatientAvatar
              :name="store.selectedQueue.patient?.name"
              :src="store.selectedQueue.patient?.photo_url"
              :size="44" radius="50%" style="margin-top:2px"
            />
            <div class="pt-info">
              <div class="pt-name">{{ store.selectedQueue.patient?.name ?? '—' }}</div>
              <div class="pt-meta">
                RM: {{ store.selectedQueue.patient?.no_rm ?? '—' }}
                · {{ store.selectedQueue.patient?.age ?? '—' }} th
                · {{ store.selectedQueue.patient?.gender === 'L' ? 'Laki-laki' : 'Perempuan' }}
              </div>
              <div class="pt-badges">
                <span v-if="store.selectedQueue.visit?.classification"
                  :class="['ptg', clsCls(store.selectedQueue.visit.classification)]">
                  {{ store.selectedQueue.visit.classification }}
                </span>
                <span v-if="store.selectedQueue.visit?.guarantor_type === 'BPJS'" class="ptg ptg-b">
                  BPJS · {{ store.selectedQueue.patient?.bpjs_number ?? store.selectedQueue.visit?.no_sep ?? '—' }}
                </span>
                <span v-else-if="store.selectedQueue.visit?.insurer_name" class="ptg ptg-a">
                  {{ store.selectedQueue.visit.insurer_name }}
                </span>
                <span v-else-if="store.selectedQueue.visit?.guarantor_type" class="ptg ptg-u">
                  {{ store.selectedQueue.visit.guarantor_type }}
                </span>
              </div>
            </div>
            <div class="pt-right">
              <span :class="['pill', `pill-${store.selectedQueue.status.toLowerCase()}`, 'pill-lg']">
                {{ statusLabel[store.selectedQueue.status] }}
              </span>
            </div>
          </div>

          <!-- Order list -->
          <div class="card">
            <div class="card-head"><div class="card-head-title">Daftar Order dari Dokter</div></div>
            <div class="card-body">
              <div v-if="!selectedOrders.length" class="empty-section" style="margin: 0">
                Belum ada order penunjang untuk pasien ini
              </div>
              <div v-else class="order-list">
                <div v-for="o in selectedOrders" :key="o.id" class="order-item">
                  <div class="order-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  </div>
                  <div class="order-info">
                    <div class="order-name">{{ o.test_name ?? o.test_type ?? 'Pemeriksaan' }}</div>
                    <div class="order-meta">
                      <span v-if="o.ordered_by_name">dr. {{ o.ordered_by_name }}</span>
                      <span v-if="o.created_at"> · {{ fmtTime(o.created_at) }}</span>
                    </div>
                    <div v-if="o.notes" class="order-notes">{{ o.notes }}</div>
                  </div>
                  <span :class="['order-status', orderStatusCls[o.status] ?? 'req']">
                    {{ orderStatusLabel[o.status] ?? o.status }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- ── Kartu aksi: Kirim ke Dokter ── -->
          <div class="card send-card">
            <div class="card-body send-card-body">
              <div class="send-card-info">
                <div class="send-card-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                  Kembalikan ke Dokter
                </div>
                <div class="send-card-sub">
                  <template v-if="store.selectedQueue.status === 'COMPLETED'">
                    Pemeriksaan sudah selesai — pasien sudah kembali ke antrean dokter.
                  </template>
                  <template v-else>
                    Klik untuk menutup antrean penunjang dan mengembalikan pasien ke antrean dokter untuk pembacaan hasil.
                  </template>
                </div>
              </div>
              <button
                class="btn btn-success btn-lg send-btn"
                :disabled="store.finalizing || store.selectedQueue.status === 'COMPLETED'"
                @click="finishExam"
              >
                <div v-if="store.finalizing" class="sp"></div>
                <template v-else>
                  Selesai &amp; Kembalikan ke Dokter
                  <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                </template>
              </button>
            </div>
          </div>
        </template>
      </section>
    </div>

    <!-- ══════════════════ MASTER: JENIS PENUNJANG ══════════════════ -->
    <JenisPenunjangView v-else-if="mainTab === 'jenis'" />

    <!-- ══════════════════ TOAST ══════════════════ -->
    <div class="toast-wrap" aria-live="polite" role="status">
      <div v-for="t in toasts" :key="t.id" :class="['toast', `toast-${t.type}`]">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
/* ── Tab modul (Antrean | Jenis Penunjang) ───────────────────────────────── */
.pj-maintabs { display: flex; gap: 6px; margin-bottom: 1rem; }
.pj-maintab {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 8px 16px; font-size: 12.5px; font-weight: 600;
  border: 1.5px solid var(--gb); border-radius: 9px; background: var(--bs);
  color: var(--tu); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .14s;
}
.pj-maintab svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.pj-maintab:hover { border-color: var(--ga); color: var(--ga); }
.pj-maintab.a { background: var(--gd); border-color: var(--gd); color: #fff; }

/* ── Layout ──────────────────────────────────────────────────────────────── */
.penunjang { padding: 0; }
.main-grid { display: grid; grid-template-columns: 340px 1fr; gap: 1rem; align-items: start; }

/* ── Card ────────────────────────────────────────────────────────────────── */
.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
.card-head { padding: 0.85rem 1.1rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.card-head-title { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--td); }
.card-head-title svg { width: 14px; height: 14px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.card-head-sub { font-size: 11px; color: var(--tu); margin-top: 3px; }
.card-body { padding: 1rem; }

/* ── Stats bar ───────────────────────────────────────────────────────────── */
.stats-bar { display: flex; align-items: center; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 8px 12px; margin-bottom: 0.65rem; gap: 0; }
.stat-item { flex: 1; text-align: center; }
.stat-divider { width: 1px; height: 28px; background: var(--gb); flex-shrink: 0; }
.stat-label { display: block; font-size: 9.5px; color: var(--tu); letter-spacing: 0.03em; margin-bottom: 2px; }
.stat-num { display: block; font-size: 17px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.stat-waiting { color: #d97706; }
.stat-done { color: var(--st); }

/* ── Filter tabs (single row) ────────────────────────────────────────────── */
.ptype-tabs { display: flex; gap: 3px; margin-bottom: 0.55rem; flex-wrap: wrap; }
.ptype-tab { flex: 1 1 0; min-width: 0; padding: 6px 4px; font-size: 10.5px; font-weight: 600; border: 1.5px solid var(--gb); border-radius: 7px; background: var(--bs); color: var(--tu); cursor: pointer; font-family: 'DM Sans',sans-serif; text-align: center; transition: all .13s; white-space: nowrap; display: inline-flex; align-items: center; justify-content: center; gap: 4px; }
.ptype-tab:hover { border-color: var(--ga); color: var(--ga); }
.ptype-tab.a { color: #fff; font-weight: 700; }
.ptype-tab.a-default { background: var(--gd); border-color: var(--gd); }
.ptype-bpjs.a { background: #1d4ed8; border-color: #1d4ed8; }
.ptype-umum.a { background: var(--ga); border-color: var(--ga); }
.ptype-done.a { background: var(--st); border-color: var(--st); }
.tab-ct { font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 10px; background: rgba(0,0,0,.08); color: inherit; }
.ptype-tab.a .tab-ct { background: rgba(255,255,255,.25); }

/* ── Queue scroll ────────────────────────────────────────────────────────── */
.pill-live { display: inline-flex; align-items: center; gap: 5px; font-size: 9.5px; font-weight: 700; padding: 2px 8px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; letter-spacing: 0.05em; }
.live-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--st); animation: blink 1.5s infinite; flex-shrink: 0; }
@keyframes blink { 0%,100% { opacity: 1; } 50% { opacity: .25; } }
.queue-scroll { padding: 0.6rem; max-height: calc(100vh - 200px); overflow-y: auto; }
.q-search-wrap { margin-bottom: 0.5rem; }
.q-search { width: 100%; height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 10px; background: var(--bs); font-family: 'DM Sans', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.q-search:focus { border-color: var(--ga); background: #fff; }

/* ── Skeleton loader ─────────────────────────────────────────────────────── */
.q-skeleton { height: 68px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; margin-bottom: 5px; animation: shimmer 1.2s ease-in-out infinite; }
@keyframes shimmer { 0%,100% { opacity: 1; } 50% { opacity: .4; } }

/* ── Empty / error states ────────────────────────────────────────────────── */
.empty-section { text-align: center; padding: 0.75rem 1rem; font-size: 11px; color: var(--th); background: var(--bi); border-radius: 7px; margin-bottom: 6px; border: 1px dashed var(--gb); }
.empty-section.err { display: flex; flex-direction: column; align-items: center; gap: 4px; color: var(--et); background: var(--eb); border-color: var(--ebd); }
.empty-section.err svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.retry-btn { margin-top: 4px; padding: 2px 10px; font-size: 10px; border: 1px solid var(--et); border-radius: 5px; background: none; color: var(--et); cursor: pointer; font-family: 'DM Sans',sans-serif; }

/* ── Queue item ──────────────────────────────────────────────────────────── */
.q-item { display: flex; gap: 8px; padding: 8px 10px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; margin-bottom: 5px; cursor: pointer; transition: all 0.14s; width: 100%; text-align: left; font-family: 'DM Sans', sans-serif; flex-wrap: wrap; }
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
.q-act-btn { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; font-size: 10px; font-weight: 600; border-radius: 5px; border: 1px solid; cursor: pointer; font-family: 'DM Sans',sans-serif; transition: background .12s, color .12s, border-color .12s, transform .07s, box-shadow .07s; background: none; user-select: none; }
.q-act-btn svg { width: 10px; height: 10px; }
.q-act-btn.call { color: var(--ga); border-color: var(--ga); background: var(--gl); }
.q-act-btn.call:hover { background: var(--ga); color: #fff; }
.q-act-btn.call.recall { color: #b45309; border-color: #fbbf24; background: #fef3c7; }
.q-act-btn.call.recall:hover { background: #f59e0b; color: #fff; border-color: #f59e0b; }
.q-act-btn.skip { color: var(--tu); border-color: var(--gb); }
.q-act-btn.skip:hover { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.q-act-btn:active:not(:disabled) { transform: scale(0.93); box-shadow: inset 0 1px 3px rgba(0,0,0,.12); }
.q-act-btn:disabled { opacity: .55; cursor: wait; }

/* ── Pills ───────────────────────────────────────────────────────────────── */
.pill { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px; }
.pill-icon { width: 8px; height: 8px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; flex-shrink: 0; }
.pill-waiting     { background: #fef3c7; color: #92400e; }
.pill-called      { background: #dbeafe; color: #1e40af; }
.pill-in_progress { background: #dbeafe; color: #1e40af; }
.pill-completed   { background: var(--sb); color: var(--st); }
.pill-bpjs  { background: #dbeafe; color: #1e40af; }
.pill-umum  { background: var(--gl); color: var(--ga); }
.pill-order { background: #ede9fe; color: #6d28d9; }
.pill-lg    { font-size: 11px; padding: 4px 10px; border-radius: 6px; }

/* ── Classification colors ───────────────────────────────────────────────── */
.cls-baru    { background: #dbeafe; color: #1e40af; }
.cls-preop   { background: #fef3c7; color: #92400e; }
.cls-postop  { background: var(--sb); color: var(--st); }
.cls-kontrol { background: #f3e8ff; color: #7e22ce; }

/* ── Empty card ──────────────────────────────────────────────────────────── */
.empty-card { min-height: 400px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1rem; padding: 2rem; color: var(--th); text-align: center; }
.empty-card svg { width: 56px; height: 56px; fill: none; stroke: var(--gb); stroke-width: 1.5; stroke-linecap: round; }
.empty-card p { font-size: 13.5px; line-height: 1.7; }

/* ── Patient header ──────────────────────────────────────────────────────── */
.pt-header { display: flex; align-items: flex-start; gap: 0.85rem; padding: 0.85rem 1.1rem; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; margin-bottom: 0.75rem; flex-wrap: wrap; }
.pt-avatar { width: 44px; height: 44px; border-radius: 50%; background: var(--gl); color: var(--ga); font-weight: 700; font-size: 18px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
.pt-info { flex: 1; min-width: 0; }
.pt-name { font-family: 'DM Serif Display', serif; font-size: 18px; color: var(--gd); font-weight: 400; line-height: 1.1; }
.pt-meta { font-size: 11px; color: var(--tu); margin-top: 3px; }
.pt-badges { display: flex; gap: 4px; margin-top: 5px; flex-wrap: wrap; }
.ptg { font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; }
.ptg-b { background: #dbeafe; color: #1e40af; }
.ptg-a { background: var(--wb); color: var(--wt); }
.ptg-u { background: var(--gl); color: var(--ga); }
.pt-right { display: flex; flex-direction: column; gap: 5px; align-items: flex-end; margin-left: auto; }

/* ── Order list ──────────────────────────────────────────────────────────── */
.order-list { display: flex; flex-direction: column; gap: 6px; }
.order-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; }
.order-icon svg { width: 22px; height: 22px; fill: none; stroke: var(--ga); stroke-width: 1.5; stroke-linecap: round; }
.order-info { flex: 1; min-width: 0; }
.order-name { font-size: 12.5px; font-weight: 600; color: var(--td); }
.order-meta { font-size: 10.5px; color: var(--tu); margin-top: 2px; }
.order-notes { font-size: 11px; color: var(--tm); margin-top: 5px; padding: 5px 8px; background: var(--bc); border-radius: 6px; line-height: 1.4; }
.order-status { font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; flex-shrink: 0; letter-spacing: 0.03em; }
.order-status.req { background: #fef3c7; color: #92400e; }
.order-status.prg { background: #dbeafe; color: #1e40af; }
.order-status.don { background: var(--sb); color: var(--st); }
.order-status.cnc { background: var(--bi); color: var(--th); }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.btn { display: inline-flex; align-items: center; gap: 5px; padding: 0 13px; height: 32px; border-radius: 8px; font-family: 'DM Sans', sans-serif; font-size: 12px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; transition: all 0.15s; }
.btn-lg { height: 42px; padding: 0 20px; font-size: 13px; font-weight: 600; }
.btn-success { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-success:hover:not(:disabled) { background: var(--gm); }
.btn-success:disabled { background: var(--th); cursor: not-allowed; }
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

/* ── Kartu Kirim ke Dokter ───────────────────────────────────────────────── */
.send-card { margin-top: 0.75rem; border-color: var(--sbd); background: linear-gradient(180deg, var(--sb) 0%, var(--bc) 60%); }
.send-card-body { display: flex; align-items: center; gap: 1rem; padding: 0.9rem 1.1rem; }
.send-card-info { flex: 1; min-width: 0; }
.send-card-title { display: flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 700; color: var(--gd); }
.send-card-title svg { width: 15px; height: 15px; fill: none; stroke: var(--ga); stroke-width: 2.5; stroke-linecap: round; }
.send-card-sub { font-size: 11.5px; color: var(--tm); margin-top: 3px; line-height: 1.5; }
.send-btn { flex-shrink: 0; }

/* ── Spinner ─────────────────────────────────────────────────────────────── */
.sp { width: 14px; height: 14px; border-radius: 50%; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; animation: spin 0.7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Toast ───────────────────────────────────────────────────────────────── */
.toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 999; display: flex; flex-direction: column; gap: 6px; }
.toast { padding: 9px 13px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 12px rgba(0,0,0,.08); min-width: 230px; max-width: 320px; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
</style>
