<script setup>
import { ref, computed, reactive, watch, onMounted, onUnmounted } from 'vue'
import { usePenunjangStore } from '@/stores/penunjangStore'
import PatientAvatar from '@/components/common/PatientAvatar.vue'
import JenisPenunjangView from '@/views/master-data/JenisPenunjangView.vue'

const store = usePenunjangStore()

// Kode master untuk pemeriksaan Biometri (form OD/OS khusus). diagnostic_orders.test_type
// kini menyimpan KODE master, jadi penanda biometri = kode ini (bukan nama 'Biometri').
const BIOMETRI_CODE = 'BIOM'

// ─── Tab utama modul: operasional antrean vs master jenis penunjang ───────────
const mainTab = ref('antrean')   // 'antrean' | 'jenis'

// ─── Local UI state ─────────────────────────────────────────────────────────
const qPrimary   = ref('waiting')   // 'waiting' | 'done'
const qSecondary = ref('semua')     // 'semua' | 'bpjs' | 'umum'
const qSearch    = ref('')

const toasts          = ref([])
const pendingCallIds  = ref([])
const pendingSkipIds  = ref([])
let   _tid            = 0

// ─── Filtered queue ─────────────────────────────────────────────────────────
const filteredQueue = computed(() => {
  let list = store.antrian

  if (qPrimary.value === 'waiting') {
    list = list.filter((q) => ['WAITING', 'CALLED', 'IN_PROGRESS'].includes(q.status))
  } else {
    list = list.filter((q) => q.status === 'COMPLETED')
  }

  if (qSecondary.value === 'bpjs') {
    list = list.filter((q) => q.visit?.guarantor_type === 'BPJS')
  } else if (qSecondary.value === 'umum') {
    list = list.filter((q) => q.visit?.guarantor_type && q.visit.guarantor_type !== 'BPJS')
  }

  if (qSearch.value) {
    const s = qSearch.value.toLowerCase()
    list = list.filter(
      (q) =>
        q.visit?.patient?.name?.toLowerCase().includes(s) ||
        q.queue_number?.toLowerCase().includes(s) ||
        q.visit?.patient?.no_rm?.toLowerCase().includes(s),
    )
  }
  return list
})

// ─── Classification helpers ─────────────────────────────────────────────────
const classColor = { Baru: 'cls-baru', 'Pre-Op': 'cls-preop', 'Post-Op': 'cls-postop', Kontrol: 'cls-kontrol' }
function clsCls(c) { return classColor[c] ?? 'cls-baru' }

// ─── Queue actions ──────────────────────────────────────────────────────────
async function pickPatient(q) {
  if (store.selectedQueue?.id === q.id) return
  // Ganti pasien akan menghapus semua form (watch selectedQueue.id) — konfirmasi
  // dulu kalau ada panel hasil dengan input belum tersimpan agar tak hilang diam2.
  if (hasUnsavedForm() &&
      !confirm('Ada input hasil pemeriksaan yang belum disimpan. Pindah pasien & buang perubahan?')) {
    return
  }
  store.pickPatient(q)
  toast('i', `Order — ${q.visit?.patient?.name ?? '—'} dibuka`)
}

// Dirty-check: panel hasil terbuka untuk order yang masih bisa diedit (bukan
// COMPLETED) dengan minimal satu field terisi → dianggap ada perubahan belum disimpan.
function hasUnsavedForm() {
  const oid = activeOrderId.value
  if (!oid) return false
  const f = forms[oid]
  if (!f) return false
  const order = selectedOrders.value.find((o) => o.id === oid)
  if (!order || order.status === 'COMPLETED') return false
  if (f.kesimpulan?.trim() || f.ringkasan?.trim() || f.notes?.trim() || f.attachment_path) return true
  // Biometri: cek nilai OD/OS apa pun yang terisi.
  if (order.test_type === BIOMETRI_CODE) {
    for (const eye of ['od', 'os']) {
      const b = f.biometri?.[eye] ?? {}
      if (b.recommended_iol_power || b.brand || (b.iol_type && b.iol_type !== 'MONOFOCAL')) return true
    }
  }
  return false
}

async function callPt(q, e) {
  e.stopPropagation()
  if (pendingCallIds.value.includes(q.id)) return
  const isRecall = q.status !== 'WAITING'
  pendingCallIds.value.push(q.id)
  try {
    await store.panggilAntrian(q.id)
    toast('i', `${isRecall ? 'Memanggil ulang' : 'Memanggil'} ${q.visit?.patient?.name} (${q.queue_number}) ke ruang penunjang`)
  } catch (err) {
    toast('w', err.message)
  } finally {
    pendingCallIds.value = pendingCallIds.value.filter((id) => id !== q.id)
  }
}

async function skipPt(q, e) {
  e.stopPropagation()
  if (pendingSkipIds.value.includes(q.id)) return
  pendingSkipIds.value.push(q.id)
  try {
    await store.lewatiAntrian(q.id)
    if (store.selectedQueue?.id === q.id) store.clearSelected()
    toast('w', `${q.visit?.patient?.name} (${q.queue_number}) diturunkan 1 antrean`)
    await store.fetchAntrian()
  } catch (err) {
    toast('w', err.message)
  } finally {
    pendingSkipIds.value = pendingSkipIds.value.filter((id) => id !== q.id)
  }
}

async function finishExam() {
  const q = store.selectedQueue
  if (!q) return
  try {
    const name = q.visit?.patient?.name ?? 'Pasien'
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
function calcAge(dob) {
  if (!dob) return null
  const d = new Date(dob), n = new Date()
  return n.getFullYear() - d.getFullYear()
    - (n < new Date(n.getFullYear(), d.getMonth(), d.getDate()) ? 1 : 0)
}

function fmtTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
}

const statusLabel = { WAITING: 'Menunggu', CALLED: 'Dipanggil', IN_PROGRESS: 'Proses', COMPLETED: 'Selesai' }

// ─── Diagnostic orders (delegated to store) ─────────────────────────────────
const selectedOrders = computed(() => store.selectedOrders)

const orderStatusLabel = { REQUESTED: 'Menunggu', IN_PROGRESS: 'Berlangsung', COMPLETED: 'Selesai', CANCELLED: 'Dibatalkan' }
const orderStatusCls   = { REQUESTED: 'req', IN_PROGRESS: 'prg', COMPLETED: 'don', CANCELLED: 'cnc' }

// ─── Per-order form state ──────────────────────────────────────────────────
// activeOrderId = order yg panel hasilnya terbuka (cuma 1 sekaligus)
const activeOrderId = ref(null)
// forms[orderId] = { kesimpulan, ringkasan, notes, attachment_path, attachment_url, biometri:{od,os} }
const forms       = reactive({})
const uploadingId = ref(null)

function blankForm() {
  return {
    kesimpulan:      '',
    ringkasan:       '',
    notes:           '',
    attachment_path: '',
    attachment_url:  '',
    biometri: {
      od: { recommended_iol_power: '', iol_type: 'MONOFOCAL', brand: '' },
      os: { recommended_iol_power: '', iol_type: 'MONOFOCAL', brand: '' },
    },
  }
}

function ensureForm(order) {
  if (forms[order.id]) return
  const f = blankForm()
  const existing = store.resultsByOrderId[order.id]
  if (existing) {
    const d = existing.expertise_data ?? {}
    f.kesimpulan      = d.kesimpulan ?? ''
    f.ringkasan       = d.ringkasan ?? ''
    f.notes           = existing.notes ?? ''
    f.attachment_path = existing.attachment_path ?? ''
    // URL absolut dari backend (accessor DiagnosticResult) — agar lampiran yang sudah
    // tersimpan / dikirim mesin tetap bisa diklik saat panel dibuka ulang, bukan hanya teks nama.
    f.attachment_url  = existing.attachment_url ?? ''
    if (order.test_type === BIOMETRI_CODE) {
      f.biometri.od = { ...f.biometri.od, ...(d.od ?? {}) }
      f.biometri.os = { ...f.biometri.os, ...(d.os ?? {}) }
    }
  }
  forms[order.id] = f
}

// Re-hydrate forms saat pasien lain dipilih (store.resultsByOrderId berubah).
watch(() => store.selectedQueue?.id, () => {
  for (const k of Object.keys(forms)) delete forms[k]
  activeOrderId.value = null
})

function openForm(order) {
  if (order.status === 'CANCELLED') return
  ensureForm(order)
  activeOrderId.value = order.id
}

function closeForm() {
  activeOrderId.value = null
}

async function startOrder(order) {
  try {
    await store.prosesOrder(order.id)
    toast('s', `Order ${order.test_type} dimulai`)
    openForm(order)
  } catch (err) {
    toast('w', err.message)
  }
}

async function cancelOrderAction(order) {
  if (!confirm(`Batalkan order ${order.test_type}?`)) return
  try {
    await store.cancelOrder(order.id)
    toast('w', `Order ${order.test_type} dibatalkan`)
    if (activeOrderId.value === order.id) activeOrderId.value = null
  } catch (err) {
    toast('w', err.message)
  }
}

async function uploadFile(order, ev) {
  const file = ev.target.files?.[0]
  if (!file) return
  uploadingId.value = order.id
  try {
    const { path, url } = await store.uploadAttachment(file)
    forms[order.id].attachment_path = path
    forms[order.id].attachment_url  = url
    toast('s', 'File terunggah')
  } catch (err) {
    toast('w', err.message)
  } finally {
    uploadingId.value = null
    ev.target.value = ''
  }
}

function buildExpertiseData(order) {
  const f = forms[order.id]
  const base = { kesimpulan: f.kesimpulan, ringkasan: f.ringkasan }
  if (order.test_type === BIOMETRI_CODE) {
    return { ...base, od: { ...f.biometri.od }, os: { ...f.biometri.os } }
  }
  return base
}

async function saveDraft(order) {
  try {
    await store.saveHasil(order.id, {
      expertise_data:  buildExpertiseData(order),
      attachment_path: forms[order.id].attachment_path || null,
      notes:           forms[order.id].notes || null,
    })
    toast('s', `Draf hasil ${order.test_type} tersimpan`)
  } catch (err) {
    toast('w', err.message)
  }
}

async function finalizeOrder(order) {
  try {
    // Simpan dulu kalau ada perubahan
    await store.saveHasil(order.id, {
      expertise_data:  buildExpertiseData(order),
      attachment_path: forms[order.id].attachment_path || null,
      notes:           forms[order.id].notes || null,
    })
    await store.finalizeHasil(order.id)
    toast('s', `Hasil ${order.test_type} selesai & dikirim ke dokter`)
    activeOrderId.value = null
  } catch (err) {
    toast('w', err.message)
  }
}

// ─── Inbox hasil tak-tertaut ─────────────────────────────────────────────────
const assignTargetId = ref(null)
const assignSearch    = ref('')
let   _assignTimer    = null

async function refreshInbox() {
  try { await store.fetchInbox() } catch (e) { toast('w', e.message) }
}

function startAssign(it) {
  assignTargetId.value = assignTargetId.value === it.id ? null : it.id
  assignSearch.value = ''
  if (assignTargetId.value) searchAssignable()
}

function searchAssignable() {
  clearTimeout(_assignTimer)
  _assignTimer = setTimeout(async () => {
    try {
      await store.fetchAssignable(assignSearch.value ? { search: assignSearch.value } : {})
    } catch (e) { toast('w', e.message) }
  }, 300)
}

async function doAssign(it, order) {
  try {
    await store.assignInboxItem(it.id, order.id)
    assignTargetId.value = null
    toast('s', `Hasil ditautkan ke ${order.visit?.patient?.name ?? 'order'}`)
  } catch (e) { toast('w', e.message) }
}

async function doDiscard(it) {
  if (!confirm('Buang berkas hasil ini? Tindakan tak bisa dibatalkan.')) return
  try {
    await store.discardInboxItem(it.id)
    toast('i', 'Berkas dibuang')
  } catch (e) { toast('w', e.message) }
}

watch(() => mainTab.value, (v) => { if (v === 'inbox') refreshInbox() })

// ─── Lifecycle ─────────────────────────────────────────────────────────────
onMounted(async () => {
  await store.fetchAntrian()
  store.fetchInbox().catch(() => {})   // populasi badge Inbox
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
      <button :class="['pj-maintab', mainTab === 'inbox' ? 'a' : '']" @click="mainTab = 'inbox'">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg>
        Inbox Hasil
        <span v-if="store.inboxCount" class="pj-maintab-badge">{{ store.inboxCount }}</span>
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

            <!-- Primary filter: Belum Dipanggil / Selesai -->
            <div class="primary-filter" role="group" aria-label="Filter utama antrean">
              <button
                :class="['pf-btn', qPrimary === 'waiting' ? 'a' : '']"
                @click="qPrimary = 'waiting'"
              >
                Belum Dipanggil
                <span v-if="store.belumDipanggilCount" class="pf-ct">{{ store.belumDipanggilCount }}</span>
              </button>
              <button
                :class="['pf-btn', qPrimary === 'done' ? 'a' : '']"
                @click="qPrimary = 'done'"
              >
                Selesai
                <span v-if="store.selesaiCount" class="pf-ct">{{ store.selesaiCount }}</span>
              </button>
            </div>

            <!-- Secondary filter: penjamin -->
            <div class="ptype-tabs" role="group" aria-label="Filter jenis penjamin">
              <button :class="['ptype-tab', qSecondary === 'semua' ? 'a' : '']" @click="qSecondary = 'semua'">Semua</button>
              <button :class="['ptype-tab ptype-bpjs', qSecondary === 'bpjs'  ? 'a' : '']" @click="qSecondary = 'bpjs'">BPJS</button>
              <button :class="['ptype-tab ptype-umum', qSecondary === 'umum'  ? 'a' : '']" @click="qSecondary = 'umum'">Umum/Asuransi</button>
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
                  <div class="q-name">{{ q.visit?.patient?.name ?? '—' }}</div>
                  <div class="q-meta">
                    {{ calcAge(q.visit?.patient?.date_of_birth) ?? '—' }} th
                    · {{ q.visit?.patient?.gender === 'L' ? 'L' : 'P' }}
                    · {{ q.visit?.classification ?? '—' }}
                  </div>
                  <div v-if="q.visit?.patient?.address" class="q-addr">{{ q.visit.patient.address }}</div>
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
              :name="store.selectedQueue.visit?.patient?.name"
              :src="store.selectedQueue.visit?.patient?.photo_url"
              :size="44" radius="50%" style="margin-top:2px"
            />
            <div class="pt-info">
              <div class="pt-name">{{ store.selectedQueue.visit?.patient?.name ?? '—' }}</div>
              <div class="pt-meta">
                RM: {{ store.selectedQueue.visit?.patient?.no_rm ?? '—' }}
                · {{ calcAge(store.selectedQueue.visit?.patient?.date_of_birth) ?? '—' }} th
                · {{ store.selectedQueue.visit?.patient?.gender === 'L' ? 'Laki-laki' : 'Perempuan' }}
              </div>
              <div v-if="store.selectedQueue.visit?.patient?.address" class="pt-addr">
                {{ store.selectedQueue.visit.patient.address }}
              </div>
              <div class="pt-badges">
                <span v-if="store.selectedQueue.visit?.classification"
                  :class="['ptg', clsCls(store.selectedQueue.visit.classification)]">
                  {{ store.selectedQueue.visit.classification }}
                </span>
                <span v-if="store.selectedQueue.visit?.guarantor_type === 'BPJS'" class="ptg ptg-b">
                  BPJS · {{ store.selectedQueue.visit?.patient?.bpjs_number ?? store.selectedQueue.visit?.no_sep ?? '—' }}
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
            <div class="card-head">
              <div class="card-head-title">Daftar Order dari Dokter</div>
              <div v-if="selectedOrders.length" class="card-head-sub">
                {{ store.pendingOrdersCount }} dari {{ selectedOrders.length }} belum selesai
              </div>
            </div>
            <div class="card-body">
              <div v-if="!selectedOrders.length" class="empty-section" style="margin: 0">
                Belum ada order penunjang untuk pasien ini
              </div>
              <div v-else class="order-list">
                <div v-for="o in selectedOrders" :key="o.id" class="order-block">
                  <div class="order-item" :class="{ active: activeOrderId === o.id }">
                    <div class="order-icon">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div class="order-info">
                      <div class="order-name">
                        {{ o.test_name ?? o.test_type ?? 'Pemeriksaan' }}
                        <span v-if="o.eye_side" class="eye-pill">{{ o.eye_side }}</span>
                      </div>
                      <div class="order-meta">
                        <span v-if="o.ordered_by_name">dr. {{ o.ordered_by_name }}</span>
                        <span v-if="o.created_at"> · {{ fmtTime(o.created_at) }}</span>
                      </div>
                      <div v-if="o.notes" class="order-notes">{{ o.notes }}</div>
                    </div>
                    <div class="order-right">
                      <span :class="['order-status', orderStatusCls[o.status] ?? 'req']">
                        {{ orderStatusLabel[o.status] ?? o.status }}
                      </span>
                      <div class="order-actions">
                        <button
                          v-if="o.status === 'REQUESTED'"
                          class="oa-btn oa-start"
                          @click="startOrder(o)"
                        >Mulai</button>
                        <button
                          v-if="o.status === 'IN_PROGRESS'"
                          class="oa-btn oa-input"
                          @click="openForm(o)"
                        >{{ activeOrderId === o.id ? 'Tutup' : 'Input Hasil' }}</button>
                        <button
                          v-if="o.status === 'COMPLETED'"
                          class="oa-btn oa-view"
                          @click="openForm(o)"
                        >Lihat</button>
                        <button
                          v-if="['REQUESTED', 'IN_PROGRESS'].includes(o.status)"
                          class="oa-btn oa-cancel"
                          @click="cancelOrderAction(o)"
                        >Batal</button>
                      </div>
                    </div>
                  </div>

                  <!-- Panel input hasil (per-order) -->
                  <div v-if="activeOrderId === o.id && forms[o.id]" class="hasil-panel">
                    <!-- Form khusus Biometri — fokus rekomendasi IOL (untuk request gudang Bedah) -->
                    <div v-if="o.test_type === BIOMETRI_CODE" class="biometri-grid">
                      <div v-for="eye in ['od', 'os']" :key="eye" class="biometri-col">
                        <div class="biometri-head">{{ eye === 'od' ? 'OD (Mata Kanan)' : 'OS (Mata Kiri)' }}</div>
                        <div class="bfield-row">
                          <label class="bfield"><span>IOL Power (D)</span>
                            <input v-model="forms[o.id].biometri[eye].recommended_iol_power" type="number" step="0.25" :disabled="o.status === 'COMPLETED'" /></label>
                          <label class="bfield"><span>Tipe IOL</span>
                            <select v-model="forms[o.id].biometri[eye].iol_type" :disabled="o.status === 'COMPLETED'">
                              <option>MONOFOCAL</option><option>MULTIFOCAL</option><option>TORIC</option>
                              <option>TRIFOCAL</option><option>EDOF</option><option>PHAKIC</option>
                            </select>
                          </label>
                        </div>
                        <label class="bfield"><span>Brand IOL</span>
                          <input v-model="forms[o.id].biometri[eye].brand" type="text" placeholder="cth: Alcon SN60WF" :disabled="o.status === 'COMPLETED'" /></label>
                      </div>
                    </div>

                    <!-- Form generic (semua test_type lain) -->
                    <div class="hp-row">
                      <label class="hp-field">
                        <span>Ringkasan Pemeriksaan</span>
                        <textarea v-model="forms[o.id].ringkasan" rows="2" placeholder="Mis. tekanan intraokular, ketebalan kornea, dst." :disabled="o.status === 'COMPLETED'"></textarea>
                      </label>
                    </div>
                    <div class="hp-row">
                      <label class="hp-field">
                        <span>Kesimpulan</span>
                        <textarea v-model="forms[o.id].kesimpulan" rows="2" placeholder="Mis. dalam batas normal / hipertensi okuli OD" :disabled="o.status === 'COMPLETED'"></textarea>
                      </label>
                    </div>
                    <div class="hp-row">
                      <label class="hp-field">
                        <span>Catatan Tambahan</span>
                        <textarea v-model="forms[o.id].notes" rows="1" :disabled="o.status === 'COMPLETED'"></textarea>
                      </label>
                    </div>

                    <!-- Upload lampiran -->
                    <div class="hp-row hp-attach">
                      <div class="hp-attach-info">
                        <span class="hp-attach-label">Lampiran (gambar/PDF, maks 10 MB)</span>
                        <div v-if="forms[o.id].attachment_path || forms[o.id].attachment_url" class="hp-attach-name">
                          <a v-if="forms[o.id].attachment_url" :href="forms[o.id].attachment_url" target="_blank" rel="noopener">{{ forms[o.id].attachment_path || 'Lihat file' }}</a>
                          <span v-else>{{ forms[o.id].attachment_path }}</span>
                        </div>
                      </div>
                      <label v-if="o.status !== 'COMPLETED'" class="hp-attach-btn" :class="{ pending: uploadingId === o.id }">
                        <input type="file" hidden accept="image/*,application/pdf" @change="(e) => uploadFile(o, e)" />
                        {{ uploadingId === o.id ? 'Mengunggah…' : (forms[o.id].attachment_path ? 'Ganti File' : 'Unggah File') }}
                      </label>
                    </div>

                    <!-- Actions panel -->
                    <div class="hp-actions" v-if="o.status !== 'COMPLETED'">
                      <button class="btn btn-ghost" @click="closeForm">Tutup</button>
                      <button class="btn btn-outline" :disabled="store.hasilSaving.has(o.id)" @click="saveDraft(o)">
                        <div v-if="store.hasilSaving.has(o.id)" class="sp sp-dark"></div>
                        Simpan Draf
                      </button>
                      <button class="btn btn-success" :disabled="store.hasilSaving.has(o.id)" @click="finalizeOrder(o)">
                        <div v-if="store.hasilSaving.has(o.id)" class="sp"></div>
                        Selesaikan Hasil
                      </button>
                    </div>
                  </div>
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
                  <template v-else-if="!selectedOrders.length">
                    Belum ada order untuk pasien ini. Kembalikan tanpa input hasil.
                  </template>
                  <template v-else-if="store.pendingOrdersCount > 0">
                    Masih ada <b>{{ store.pendingOrdersCount }}</b> order belum di-finalize. Selesaikan dulu di atas.
                  </template>
                  <template v-else>
                    Semua order sudah selesai. Klik untuk kembalikan pasien ke antrean dokter.
                  </template>
                </div>
              </div>
              <button
                class="btn btn-success btn-lg send-btn"
                :disabled="store.finalizing
                  || store.selectedQueue.status === 'COMPLETED'
                  || (selectedOrders.length > 0 && store.pendingOrdersCount > 0)"
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

    <!-- ══════════════════ INBOX HASIL TAK-TERTAUT ══════════════════ -->
    <section v-else-if="mainTab === 'inbox'" class="inbox-wrap">
      <div class="card">
        <div class="card-head">
          <div>
            <div class="card-head-title">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg>
              Inbox Hasil Tak-Tertaut
            </div>
            <div class="card-head-sub">Hasil dari alat yang belum bisa dicocokkan otomatis — tautkan ke order yang benar.</div>
          </div>
          <button class="oa-btn oa-view" @click="refreshInbox">Muat ulang</button>
        </div>
        <div class="card-body">
          <template v-if="store.inboxLoading">
            <div v-for="n in 3" :key="n" class="q-skeleton"></div>
          </template>
          <div v-else-if="!store.inbox.length" class="empty-section" style="margin:0">
            Tidak ada berkas menunggu — semua hasil sudah tertaut otomatis.
          </div>
          <div v-else class="inbox-list">
            <div v-for="it in store.inbox" :key="it.id" class="inbox-item">
              <div class="inbox-row">
                <div class="inbox-info">
                  <!-- Identitas pasien dulu (nama + No.RM), bukan nama file GUID acak. -->
                  <div class="inbox-name">
                    <template v-if="it.patient_name">{{ it.patient_name }}</template>
                    <span v-else class="inbox-unknown">Pasien tak dikenal</span>
                    <span v-if="it.patient_no_rm" class="inbox-rm">· RM {{ it.patient_no_rm }}</span>
                    <span v-if="it.patient_no_rm && !it.patient_name" class="inbox-warn">No.RM tak ada di sistem</span>
                  </div>
                  <div class="inbox-meta">
                    <span :class="['pill', it.source === 'OCT' ? 'pill-order' : 'pill-umum']">{{ it.source }}</span>
                    <span v-if="it.accession_number">Acc: {{ it.accession_number }}</span>
                    <span class="inbox-file" :title="it.original_filename || it.attachment_path">{{ it.original_filename || it.attachment_path }}</span>
                    <a v-if="it.attachment_url" :href="it.attachment_url" target="_blank" rel="noopener" class="inbox-link">Lihat berkas</a>
                  </div>
                </div>
                <div class="inbox-actions">
                  <button class="oa-btn oa-input" @click="startAssign(it)">{{ assignTargetId === it.id ? 'Tutup' : 'Tautkan' }}</button>
                  <button class="oa-btn oa-cancel" @click="doDiscard(it)">Buang</button>
                </div>
              </div>

              <!-- Picker order -->
              <div v-if="assignTargetId === it.id" class="assign-panel">
                <input v-model="assignSearch" @input="searchAssignable" class="q-search"
                       placeholder="Cari nama / No. RM (order penunjang terbuka hari ini)…" />
                <div v-if="!store.assignable.length" class="empty-section" style="margin:6px 0 0">
                  Tidak ada order terbuka cocok. Coba kata kunci lain.
                </div>
                <div v-else class="assign-list">
                  <button v-for="o in store.assignable" :key="o.id" class="assign-row" @click="doAssign(it, o)">
                    <b>{{ o.visit?.patient?.name ?? '—' }}</b>
                    <span class="assign-sub">
                      RM {{ o.visit?.patient?.no_rm ?? '—' }} · {{ o.test_type }}<template v-if="o.eye_side"> ({{ o.eye_side }})</template> · {{ o.accession_number }}
                    </span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

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
  color: var(--tu); cursor: pointer; font-family: 'Inter', sans-serif; transition: all .14s;
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

/* ── Primary filter (Belum Dipanggil / Selesai) ──────────────────────────── */
.primary-filter { display: flex; gap: 4px; margin-bottom: 0.5rem; }
.pf-btn { flex: 1; height: 32px; font-size: 11.5px; font-weight: 500; border: 1.5px solid var(--gb); border-radius: 8px; background: var(--bs); color: var(--tm); cursor: pointer; font-family: 'Inter', sans-serif; transition: all .13s; display: flex; align-items: center; justify-content: center; gap: 5px; }
.pf-btn:hover { border-color: var(--ga); color: var(--ga); }
.pf-btn.a { background: var(--gd); color: #fff; border-color: var(--gd); }
.pf-ct { font-size: 9px; font-weight: 700; padding: 0 5px; border-radius: 10px; background: rgba(255,255,255,.25); }

/* ── Secondary filter (penjamin) ─────────────────────────────────────────── */
.ptype-tabs { display: flex; gap: 3px; margin-bottom: 0.55rem; }
.ptype-tab { flex: 1; padding: 5px 4px; font-size: 10px; font-weight: 600; border: 1.5px solid var(--gb); border-radius: 7px; background: var(--bs); color: var(--tu); cursor: pointer; font-family: 'Inter',sans-serif; text-align: center; transition: all .13s; white-space: nowrap; }
.ptype-tab:hover { border-color: var(--ga); color: var(--ga); }
.ptype-tab.a { color: #fff; font-weight: 700; }
.ptype-bpjs.a { background: #1d4ed8; border-color: #1d4ed8; }
.ptype-umum.a { background: var(--ga); border-color: var(--ga); }

/* ── Queue scroll ────────────────────────────────────────────────────────── */
.pill-live { display: inline-flex; align-items: center; gap: 5px; font-size: 9.5px; font-weight: 700; padding: 2px 8px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; letter-spacing: 0.05em; }
.live-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--st); animation: blink 1.5s infinite; flex-shrink: 0; }
@keyframes blink { 0%,100% { opacity: 1; } 50% { opacity: .25; } }
.queue-scroll { padding: 0.6rem; max-height: calc(100vh - 200px); overflow-y: auto; }
.q-search-wrap { margin-bottom: 0.5rem; }
.q-search { width: 100%; height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 10px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.q-search:focus { border-color: var(--ga); background: #fff; }

/* ── Skeleton loader ─────────────────────────────────────────────────────── */
.q-skeleton { height: 68px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; margin-bottom: 5px; animation: shimmer 1.2s ease-in-out infinite; }
@keyframes shimmer { 0%,100% { opacity: 1; } 50% { opacity: .4; } }

/* ── Empty / error states ────────────────────────────────────────────────── */
.empty-section { text-align: center; padding: 0.75rem 1rem; font-size: 11px; color: var(--th); background: var(--bi); border-radius: 7px; margin-bottom: 6px; border: 1px dashed var(--gb); }
.empty-section.err { display: flex; flex-direction: column; align-items: center; gap: 4px; color: var(--et); background: var(--eb); border-color: var(--ebd); }
.empty-section.err svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.retry-btn { margin-top: 4px; padding: 2px 10px; font-size: 10px; border: 1px solid var(--et); border-radius: 5px; background: none; color: var(--et); cursor: pointer; font-family: 'Inter',sans-serif; }

/* ── Queue item ──────────────────────────────────────────────────────────── */
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
.q-addr { font-size: 10px; color: var(--tu); margin-top: 2px; line-height: 1.35; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.q-tags { display: flex; gap: 3px; margin-top: 3px; flex-wrap: wrap; min-width: 0; max-width: 100%; }
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
.q-act-btn:active:not(:disabled) { transform: scale(0.93); box-shadow: inset 0 1px 3px rgba(0,0,0,.12); }
.q-act-btn:disabled { opacity: .55; cursor: wait; }

/* ── Pills ───────────────────────────────────────────────────────────────── */
.pill { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px; max-width: 100%; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
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
.pt-name { font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--td); font-weight: 400; line-height: 1.1; }
.pt-meta { font-size: 11px; color: var(--tu); margin-top: 3px; }
.pt-addr { font-size: 11px; color: var(--tu); margin-top: 3px; line-height: 1.4; }
.pt-badges { display: flex; gap: 4px; margin-top: 5px; flex-wrap: wrap; }
.ptg { font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; }
.ptg-b { background: #dbeafe; color: #1e40af; }
.ptg-a { background: var(--wb); color: var(--wt); }
.ptg-u { background: var(--gl); color: var(--ga); }
.pt-right { display: flex; flex-direction: column; gap: 5px; align-items: flex-end; margin-left: auto; }

/* ── Order list ──────────────────────────────────────────────────────────── */
.order-list { display: flex; flex-direction: column; gap: 8px; }
.order-block { display: flex; flex-direction: column; gap: 0; }
.order-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; transition: border-color .12s; }
.order-item.active { border-color: var(--ga); background: var(--gl); border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
.order-icon svg { width: 22px; height: 22px; fill: none; stroke: var(--ga); stroke-width: 1.5; stroke-linecap: round; }
.order-info { flex: 1; min-width: 0; }
.order-name { font-size: 12.5px; font-weight: 600; color: var(--td); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.eye-pill { font-size: 9px; font-weight: 700; padding: 1px 6px; background: var(--gl); color: var(--ga); border-radius: 4px; }
.order-meta { font-size: 10.5px; color: var(--tu); margin-top: 2px; }
.order-notes { font-size: 11px; color: var(--tm); margin-top: 5px; padding: 5px 8px; background: var(--bc); border-radius: 6px; line-height: 1.4; }
.order-right { display: flex; flex-direction: column; gap: 5px; align-items: flex-end; flex-shrink: 0; }
.order-status { font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.03em; }
.order-status.req { background: #fef3c7; color: #92400e; }
.order-status.prg { background: #dbeafe; color: #1e40af; }
.order-status.don { background: var(--sb); color: var(--st); }
.order-status.cnc { background: var(--bi); color: var(--th); }

.order-actions { display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end; }
.oa-btn { font-size: 10px; font-weight: 600; padding: 3px 9px; border-radius: 5px; border: 1px solid; cursor: pointer; font-family: 'Inter', sans-serif; background: none; transition: background .12s, color .12s, transform .07s, box-shadow .07s; }
.oa-btn:active:not(:disabled) { transform: scale(.94); box-shadow: inset 0 1px 3px rgba(0,0,0,.12); }
.oa-start  { color: var(--ga); border-color: var(--ga); background: var(--gl); }
.oa-start:hover  { background: var(--ga); color: #fff; }
.oa-input  { color: #1d4ed8; border-color: #1d4ed8; background: #dbeafe; }
.oa-input:hover  { background: #1d4ed8; color: #fff; }
.oa-view   { color: var(--st); border-color: var(--sbd); background: var(--sb); }
.oa-view:hover   { background: var(--st); color: #fff; }
.oa-cancel { color: var(--tu); border-color: var(--gb); }
.oa-cancel:hover { background: var(--wb); color: var(--wt); border-color: var(--wbd); }

/* ── Hasil panel (per-order) ─────────────────────────────────────────────── */
.hasil-panel { background: var(--bc); border: 1.5px solid var(--ga); border-top: none; border-radius: 0 0 9px 9px; padding: 12px 14px; display: flex; flex-direction: column; gap: 10px; margin-top: -1px; }
.hp-row { display: flex; gap: 10px; }
.hp-field { flex: 1; display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.hp-field > span { font-size: 10.5px; font-weight: 600; color: var(--tm); letter-spacing: 0.02em; }
.hp-field textarea, .hp-field input, .hp-field select { width: 100%; font-family: 'Inter', sans-serif; font-size: 12px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 7px 10px; background: var(--bs); color: var(--td); outline: none; box-sizing: border-box; resize: vertical; }
.hp-field textarea:focus, .hp-field input:focus, .hp-field select:focus { border-color: var(--ga); background: #fff; }
.hp-field textarea:disabled, .hp-field input:disabled, .hp-field select:disabled { background: var(--bi); cursor: not-allowed; opacity: .85; }

/* Biometri grid */
.biometri-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 10px; background: var(--bs); border: 1px dashed var(--gb); border-radius: 8px; }
.biometri-col { display: flex; flex-direction: column; gap: 6px; }
.biometri-head { font-size: 11px; font-weight: 700; color: var(--td); padding-bottom: 4px; border-bottom: 1px solid var(--gb); }
.bfield-row { display: flex; gap: 6px; }
.bfield { flex: 1; display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.bfield > span { font-size: 9.5px; font-weight: 600; color: var(--tu); letter-spacing: 0.02em; }
.bfield input, .bfield select { width: 100%; font-family: 'Inter', sans-serif; font-size: 11px; border: 1.5px solid var(--gb); border-radius: 6px; padding: 5px 7px; background: #fff; color: var(--td); outline: none; box-sizing: border-box; }
.bfield input:focus, .bfield select:focus { border-color: var(--ga); }
.bfield input:disabled, .bfield select:disabled { background: var(--bi); cursor: not-allowed; }

/* Upload row */
.hp-attach { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: var(--bs); border: 1px dashed var(--gb); border-radius: 7px; }
.hp-attach-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.hp-attach-label { font-size: 10.5px; font-weight: 600; color: var(--tm); }
.hp-attach-name { font-size: 11px; color: var(--ga); word-break: break-all; }
.hp-attach-name a { color: inherit; text-decoration: underline; }
.hp-attach-btn { display: inline-flex; align-items: center; padding: 6px 12px; font-size: 11px; font-weight: 600; border: 1.5px solid var(--ga); color: var(--ga); background: var(--gl); border-radius: 7px; cursor: pointer; font-family: 'Inter', sans-serif; transition: all .12s; }
.hp-attach-btn:hover { background: var(--ga); color: #fff; }
.hp-attach-btn.pending { opacity: .65; cursor: wait; }

/* Panel actions */
.hp-actions { display: flex; gap: 8px; justify-content: flex-end; padding-top: 4px; border-top: 1px dashed var(--gb); }
.btn-ghost   { background: none; color: var(--tu); border-color: var(--gb); }
.btn-ghost:hover { background: var(--bs); color: var(--td); }
.btn-outline { background: var(--bs); color: var(--ga); border-color: var(--ga); }
.btn-outline:hover:not(:disabled) { background: var(--gl); }
.btn:disabled { opacity: .55; cursor: wait; }
.sp-dark { border: 2px solid rgba(0,0,0,.15); border-top-color: var(--ga); }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.btn { display: inline-flex; align-items: center; gap: 5px; padding: 0 13px; height: 32px; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 12px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; transition: all 0.15s; }
.btn-lg { height: 42px; padding: 0 20px; font-size: 13px; font-weight: 600; }
.btn-success { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-success:hover:not(:disabled) { background: var(--gm); }
.btn-success:disabled { background: var(--th); cursor: not-allowed; }
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

/* ── Kartu Kirim ke Dokter ───────────────────────────────────────────────── */
.send-card { margin-top: 0.75rem; border-color: var(--sbd); background: linear-gradient(180deg, var(--sb) 0%, var(--bc) 60%); }
.send-card-body { display: flex; align-items: center; gap: 1rem; padding: 0.9rem 1.1rem; }
.send-card-info { flex: 1; min-width: 0; }
.send-card-title { display: flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 700; color: var(--td); }
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

/* ── Inbox hasil tak-tertaut ─────────────────────────────────────────────── */
.pj-maintab-badge { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 10px; background: #fef3c7; color: #92400e; margin-left: 2px; }
.pj-maintab.a .pj-maintab-badge { background: rgba(255,255,255,.28); color: #fff; }
.inbox-wrap { max-width: 880px; }
.inbox-list { display: flex; flex-direction: column; gap: 8px; }
.inbox-item { border: 1.5px solid var(--gb); border-radius: 9px; background: var(--bs); overflow: hidden; }
.inbox-row { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; }
.inbox-info { flex: 1; min-width: 0; }
.inbox-name { font-size: 12.5px; font-weight: 600; color: var(--td); }
.inbox-unknown { color: var(--tu); font-style: italic; font-weight: 500; }
.inbox-rm { color: var(--tm); font-weight: 500; margin-left: 4px; }
.inbox-warn { display: inline-block; margin-left: 6px; padding: 1px 7px; font-size: 9.5px; font-weight: 700; border-radius: 10px; background: var(--eb); color: var(--et); border: 1px solid var(--ebd); }
.inbox-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 10.5px; color: var(--tu); margin-top: 4px; }
.inbox-file { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: .8; }
.inbox-link { color: var(--ga); text-decoration: underline; }
.inbox-actions { display: flex; gap: 4px; flex-shrink: 0; }
.assign-panel { border-top: 1px dashed var(--gb); padding: 10px 12px; background: var(--bc); }
.assign-list { display: flex; flex-direction: column; gap: 4px; margin-top: 6px; max-height: 280px; overflow-y: auto; }
.assign-row { display: flex; flex-direction: column; gap: 2px; text-align: left; padding: 7px 10px; border: 1px solid var(--gb); border-radius: 7px; background: var(--bs); cursor: pointer; font-family: 'Inter', sans-serif; transition: all .12s; }
.assign-row:hover { border-color: var(--ga); background: var(--gl); }
.assign-row b { font-size: 12px; color: var(--td); }
.assign-sub { font-size: 10.5px; color: var(--tu); }
</style>
