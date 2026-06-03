<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { ruangTindakanApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'

const auth = useAuthStore()
const canWrite = computed(() => auth.can('ruang_tindakan.write'))

// ─── State papan ──────────────────────────────────────────────────────────────
const board = ref([])
const loading = ref(false)
const selId = ref(null)
const sel = computed(() => board.value.find(p => p.id === selId.value) || null)

// ─── Master procedure laser (dropdown) ────────────────────────────────────────
const procedures = ref([])
const procSearch = ref('')

// ─── Form laporan laser ───────────────────────────────────────────────────────
const EYE_OPTS = [
  { v: 'OD', l: 'OD (kanan)' },
  { v: 'OS', l: 'OS (kiri)' },
  { v: 'ODS', l: 'ODS (keduanya)' },
]
const LASER_TYPES = ['Laser YAG (Kapsulotomi)', 'Laser YAG (Iridotomi)', 'Laser Retina / PRP', 'Laser Fokal', 'Lainnya']
const DISPOSITIONS = [
  { v: 'PULANG', l: 'Pulang → Kasir' },
  { v: 'RAWAT_INAP', l: 'Rawat Inap' },
]

const form = ref(blankForm())
const selectedProcedures = ref([]) // [{id, code, name}]

function blankForm() {
  return {
    eye: 'OD',
    laser_type: '',
    energy: '',     // mJ / mW
    total_shots: '',
    spots: '',
    findings: '',
    complication: '',
    notes: '',
    followup_date: '',
    post_op_disposition: 'PULANG',
  }
}

// ─── Toast ────────────────────────────────────────────────────────────────────
const toastMsg = ref('')
const toastType = ref('s')
let toastTimer = null
function toast(type, msg) {
  toastType.value = type; toastMsg.value = msg
  clearTimeout(toastTimer); toastTimer = setTimeout(() => { toastMsg.value = '' }, 3500)
}

// ─── Fetch ────────────────────────────────────────────────────────────────────
async function loadBoard() {
  loading.value = true
  try {
    const res = await ruangTindakanApi.antrian()
    board.value = res.data?.data || []
    // Pertahankan pilihan bila masih ada; else pilih pertama.
    if (selId.value && !board.value.some(p => p.id === selId.value)) selId.value = null
    if (!selId.value && board.value.length) selectPatient(board.value[0])
  } catch (e) {
    toast('e', e?.response?.data?.message || 'Gagal memuat antrean.')
  } finally {
    loading.value = false
  }
}

async function loadProcedures() {
  try {
    const res = await ruangTindakanApi.procedures(procSearch.value || undefined)
    procedures.value = res.data?.data || []
  } catch { /* non-blok */ }
}

function selectPatient(p) {
  selId.value = p.id
  // Hydrate form dari laporan tersimpan bila ada.
  const lap = p.record?.laporan
  form.value = lap ? { ...blankForm(), ...lap } : blankForm()
  selectedProcedures.value = []
}

// ─── Status helper ────────────────────────────────────────────────────────────
function schedStatus(p) { return p.schedule?.status || 'SCHEDULED' }
function isWaiting(p) { return ['WAITING', 'CALLED'].includes(p.status) && schedStatus(p) === 'SCHEDULED' }
function isRunning(p) { return schedStatus(p) === 'IN_PROGRESS' }
function isDone(p) { return schedStatus(p) === 'DONE' }

// ─── Aksi lifecycle ───────────────────────────────────────────────────────────
async function panggil(p) {
  try { await ruangTindakanApi.panggil(p.id); toast('s', 'Pasien dipanggil.'); await loadBoard() }
  catch (e) { toast('e', e?.response?.data?.message || 'Gagal memanggil.') }
}

async function mulai(p) {
  if (!p.schedule?.id) return toast('e', 'Jadwal tidak ditemukan.')
  try { await ruangTindakanApi.mulai(p.schedule.id); toast('s', 'Tindakan dimulai.'); await loadBoard() }
  catch (e) { toast('e', e?.response?.data?.message || 'Gagal memulai.') }
}

function toggleProc(proc) {
  const i = selectedProcedures.value.findIndex(x => x.id === proc.id)
  if (i >= 0) selectedProcedures.value.splice(i, 1)
  else selectedProcedures.value.push({ id: proc.id, code: proc.code, name: proc.name })
}
function isProcSelected(id) { return selectedProcedures.value.some(x => x.id === id) }

async function selesai(p) {
  if (!p.schedule?.id) return
  if (!form.value.laser_type) return toast('e', 'Pilih jenis laser dulu.')
  try {
    await ruangTindakanApi.selesai(p.schedule.id, {
      laporan: {
        eye: form.value.eye,
        laser_type: form.value.laser_type,
        energy: form.value.energy || null,
        total_shots: form.value.total_shots || null,
        spots: form.value.spots || null,
        findings: form.value.findings || null,
        notes: form.value.notes || null,
      },
      procedure_ids: selectedProcedures.value.map(x => x.id),
      post_op_disposition: form.value.post_op_disposition,
      followup_date: form.value.followup_date || null,
      complication: form.value.complication || null,
      notes: form.value.notes || null,
    })
    toast('s', 'Tindakan selesai → pasien diteruskan ke Kasir.')
    selId.value = null
    await loadBoard()
  } catch (e) {
    toast('e', e?.response?.data?.message || 'Gagal menyelesaikan tindakan.')
  }
}

// ─── Polling ──────────────────────────────────────────────────────────────────
let pollTimer = null
onMounted(() => {
  loadBoard(); loadProcedures()
  pollTimer = setInterval(loadBoard, 15000)
})
onUnmounted(() => { clearInterval(pollTimer); clearTimeout(toastTimer) })
</script>

<template>
  <div class="rt-page">
    <header class="rt-head">
      <div>
        <h1>Ruang Tindakan</h1>
        <p class="sub">Tindakan laser (YAG &amp; Retina/PRP) — pasien terjadwal hari ini.</p>
      </div>
      <button class="btn-soft" @click="loadBoard" :disabled="loading">
        {{ loading ? 'Memuat…' : 'Muat ulang' }}
      </button>
    </header>

    <div class="rt-body">
      <!-- Papan antrean -->
      <aside class="rt-board">
        <div class="board-title">Antrean <span class="cnt">{{ board.length }}</span></div>
        <div v-if="!board.length && !loading" class="empty">Tidak ada pasien tindakan hari ini.</div>
        <button
          v-for="p in board" :key="p.id"
          class="pt-card" :class="{ active: p.id === selId }"
          @click="selectPatient(p)"
        >
          <div class="pt-top">
            <span class="pt-no">{{ p.queue_number || '—' }}</span>
            <span class="pt-status" :class="'st-' + (schedStatus(p) || '').toLowerCase()">
              {{ isDone(p) ? 'Selesai' : isRunning(p) ? 'Berlangsung' : p.status === 'CALLED' ? 'Dipanggil' : 'Menunggu' }}
            </span>
          </div>
          <div class="pt-name">{{ p.patient?.name || '-' }}</div>
          <div class="pt-meta">
            {{ p.patient?.no_rm || '' }} · {{ p.patient?.age != null ? p.patient.age + ' th' : '-' }}
            <span v-if="p.schedule?.scheduled_time"> · {{ String(p.schedule.scheduled_time).slice(0,5) }}</span>
          </div>
        </button>
      </aside>

      <!-- Detail / form -->
      <section class="rt-detail">
        <div v-if="!sel" class="empty-detail">Pilih pasien dari antrean.</div>
        <template v-else>
          <div class="dt-head">
            <div>
              <h2>{{ sel.patient?.name }}</h2>
              <div class="dt-sub">
                {{ sel.patient?.no_rm }} · {{ sel.patient?.gender }} · {{ sel.patient?.age != null ? sel.patient.age + ' th' : '-' }}
                · {{ sel.visit?.insurer_name || sel.visit?.guarantor_type || 'Umum' }}
              </div>
            </div>
            <div class="dt-tags">
              <span class="tag">{{ sel.schedule?.package?.name || 'Tindakan Laser' }}</span>
              <span v-if="sel.visit?.diagnosa" class="tag tag-dx">{{ sel.visit.diagnosa }}</span>
            </div>
          </div>

          <!-- Pra-tindakan (visus/IOP bila ada) -->
          <div v-if="sel.preop" class="preop-box">
            <span v-if="sel.preop.visus_od">Visus OD: <b>{{ sel.preop.visus_od }}</b></span>
            <span v-if="sel.preop.visus_os">Visus OS: <b>{{ sel.preop.visus_os }}</b></span>
            <span v-if="sel.preop.iop_od">TIO OD: <b>{{ sel.preop.iop_od }}</b></span>
            <span v-if="sel.preop.iop_os">TIO OS: <b>{{ sel.preop.iop_os }}</b></span>
          </div>

          <!-- Aksi state: Menunggu → Panggil → Mulai -->
          <div v-if="!isRunning(sel) && !isDone(sel)" class="action-row">
            <button v-if="sel.status !== 'CALLED'" class="btn-primary" :disabled="!canWrite" @click="panggil(sel)">Panggil Pasien</button>
            <button class="btn-primary accent" :disabled="!canWrite" @click="mulai(sel)">Mulai Tindakan</button>
          </div>
          <div v-else-if="isDone(sel)" class="done-banner">✓ Tindakan selesai — pasien telah diteruskan ke Kasir.</div>

          <!-- Form laporan laser (saat berlangsung) -->
          <div v-if="isRunning(sel)" class="laser-form">
            <h3>Laporan Tindakan Laser</h3>
            <div class="g2">
              <div class="fg">
                <label>Mata</label>
                <select v-model="form.eye" class="inp">
                  <option v-for="e in EYE_OPTS" :key="e.v" :value="e.v">{{ e.l }}</option>
                </select>
              </div>
              <div class="fg">
                <label>Jenis Laser <span class="req">*</span></label>
                <select v-model="form.laser_type" class="inp">
                  <option value="">— Pilih —</option>
                  <option v-for="t in LASER_TYPES" :key="t" :value="t">{{ t }}</option>
                </select>
              </div>
            </div>
            <div class="g3">
              <div class="fg">
                <label>Energi (mJ/mW)</label>
                <input v-model="form.energy" class="inp" placeholder="mis. 1.8 mJ" />
              </div>
              <div class="fg">
                <label>Total Tembakan</label>
                <input v-model="form.total_shots" class="inp" type="number" min="0" />
              </div>
              <div class="fg">
                <label>Spots</label>
                <input v-model="form.spots" class="inp" type="number" min="0" />
              </div>
            </div>
            <div class="fg">
              <label>Temuan / Hasil</label>
              <textarea v-model="form.findings" class="inp" rows="2" placeholder="Temuan klinis, hasil tindakan…"></textarea>
            </div>
            <div class="fg">
              <label>Komplikasi (jika ada)</label>
              <input v-model="form.complication" class="inp" placeholder="Kosongkan bila tidak ada" />
            </div>

            <!-- Tindakan ditagih (procedure → visit_services) -->
            <div class="fg">
              <label>Tindakan Ditagih (untuk Kasir)</label>
              <div class="proc-search">
                <input v-model="procSearch" class="inp" placeholder="Cari tindakan laser…" @input="loadProcedures" />
              </div>
              <div class="proc-list">
                <label v-for="pr in procedures" :key="pr.id" class="proc-item">
                  <input type="checkbox" :checked="isProcSelected(pr.id)" @change="toggleProc(pr)" />
                  <span>{{ pr.code ? pr.code + ' · ' : '' }}{{ pr.name }}</span>
                </label>
                <div v-if="!procedures.length" class="proc-empty">Tidak ada tindakan ditemukan.</div>
              </div>
              <div v-if="selectedProcedures.length" class="proc-chips">
                <span v-for="x in selectedProcedures" :key="x.id" class="chip">{{ x.name }}</span>
              </div>
            </div>

            <div class="g2">
              <div class="fg">
                <label>Disposisi</label>
                <select v-model="form.post_op_disposition" class="inp">
                  <option v-for="d in DISPOSITIONS" :key="d.v" :value="d.v">{{ d.l }}</option>
                </select>
              </div>
              <div class="fg">
                <label>Tgl Kontrol (opsional)</label>
                <input v-model="form.followup_date" type="date" class="inp" />
              </div>
            </div>

            <div class="action-row">
              <button class="btn-primary accent" :disabled="!canWrite" @click="selesai(sel)">Selesai Tindakan</button>
            </div>
          </div>
        </template>
      </section>
    </div>

    <transition name="toast">
      <div v-if="toastMsg" class="toast" :class="toastType">{{ toastMsg }}</div>
    </transition>
  </div>
</template>

<style scoped>
.rt-page { padding: 20px 24px; }
.rt-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.rt-head h1 { font-size: 1.4rem; font-weight: 700; color: #0E3A66; margin: 0; }
.rt-head .sub { margin: 4px 0 0; color: #64748b; font-size: 0.85rem; }

.rt-body { display: grid; grid-template-columns: 300px 1fr; gap: 16px; }

/* Papan */
.rt-board { border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px; background: #fff; max-height: calc(100vh - 160px); overflow: auto; }
.board-title { font-weight: 700; color: #0E3A66; font-size: 0.9rem; margin-bottom: 10px; display: flex; align-items: center; gap: 7px; }
.board-title .cnt { background: #1FAAE0; color: #fff; border-radius: 999px; padding: 1px 8px; font-size: 0.72rem; }
.pt-card { display: block; width: 100%; text-align: left; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 11px; margin-bottom: 8px; background: #fff; cursor: pointer; transition: border-color .15s, background .15s; }
.pt-card:hover { border-color: #94a3b8; }
.pt-card.active { border-color: #1FAAE0; background: #f0f9ff; }
.pt-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.pt-no { font-weight: 700; color: #0E3A66; font-size: 0.85rem; }
.pt-status { font-size: 0.68rem; font-weight: 700; padding: 1px 8px; border-radius: 999px; background: #f1f5f9; color: #475569; }
.st-in_progress { background: #fef3c7; color: #b45309; }
.st-done { background: #dcfce7; color: #15803d; }
.pt-name { font-weight: 600; color: #1e293b; font-size: 0.9rem; }
.pt-meta { font-size: 0.75rem; color: #64748b; margin-top: 2px; }

/* Detail */
.rt-detail { border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; background: #fff; min-height: 300px; }
.empty, .empty-detail { color: #94a3b8; text-align: center; padding: 30px 12px; font-size: 0.85rem; }
.dt-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 14px; }
.dt-head h2 { font-size: 1.1rem; font-weight: 700; color: #0E3A66; margin: 0; }
.dt-sub { font-size: 0.8rem; color: #64748b; margin-top: 3px; }
.dt-tags { display: flex; flex-direction: column; gap: 5px; align-items: flex-end; }
.tag { background: #f1f5f9; color: #334155; font-size: 0.72rem; font-weight: 600; padding: 3px 10px; border-radius: 999px; }
.tag-dx { background: #e0f2fe; color: #0369a1; }

.preop-box { display: flex; gap: 16px; flex-wrap: wrap; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 9px 12px; font-size: 0.8rem; color: #475569; margin-bottom: 14px; }

.action-row { display: flex; gap: 10px; margin-top: 14px; }
.btn-primary { padding: 9px 18px; border: 1px solid #cbd5e1; background: #fff; border-radius: 9px; font-weight: 600; font-size: 0.88rem; color: #334155; cursor: pointer; }
.btn-primary:hover:not(:disabled) { background: #f1f5f9; }
.btn-primary.accent { background: #0E3A66; border-color: #0E3A66; color: #fff; }
.btn-primary.accent:hover:not(:disabled) { background: #0c3155; }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.btn-soft { padding: 7px 14px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; font-size: 0.85rem; font-weight: 600; color: #334155; cursor: pointer; }
.btn-soft:hover:not(:disabled) { background: #f1f5f9; }
.btn-soft:disabled { opacity: .55; }
.done-banner { margin-top: 14px; background: #dcfce7; color: #15803d; border: 1px solid #86efac; border-radius: 9px; padding: 11px 14px; font-weight: 600; font-size: 0.85rem; }

/* Form laser */
.laser-form { margin-top: 16px; border-top: 1px solid #f1f5f9; padding-top: 14px; }
.laser-form h3 { font-size: 0.95rem; font-weight: 700; color: #0E3A66; margin: 0 0 12px; }
.g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.g3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
.fg { display: flex; flex-direction: column; gap: 5px; margin-bottom: 11px; }
.fg label { font-size: 0.78rem; font-weight: 600; color: #475569; }
.fg .req { color: #dc2626; }
.inp { border: 1px solid #cbd5e1; border-radius: 8px; padding: 7px 10px; font-size: 0.85rem; color: #1e293b; font-family: inherit; }
.inp:focus { outline: none; border-color: #1FAAE0; box-shadow: 0 0 0 2px rgba(31,170,224,.15); }

.proc-list { border: 1px solid #e2e8f0; border-radius: 8px; max-height: 140px; overflow: auto; }
.proc-item { display: flex; align-items: center; gap: 8px; padding: 6px 10px; font-size: 0.82rem; color: #334155; cursor: pointer; border-bottom: 1px solid #f8fafc; }
.proc-item:hover { background: #f8fafc; }
.proc-item input { accent-color: #1FAAE0; }
.proc-empty { padding: 10px; color: #94a3b8; font-size: 0.8rem; text-align: center; }
.proc-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.chip { background: #e0f2fe; color: #0369a1; font-size: 0.74rem; font-weight: 600; padding: 3px 10px; border-radius: 999px; }

/* Toast */
.toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); padding: 11px 20px; border-radius: 9px; color: #fff; font-size: 0.88rem; font-weight: 600; box-shadow: 0 8px 24px rgba(15,23,42,.2); z-index: 100; }
.toast.s { background: #16a34a; }
.toast.e { background: #dc2626; }
.toast-enter-active, .toast-leave-active { transition: opacity .25s, transform .25s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translate(-50%, 10px); }
</style>
