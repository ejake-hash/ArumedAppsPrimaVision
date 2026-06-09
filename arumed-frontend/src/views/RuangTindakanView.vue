<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { ruangTindakanApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'

const auth = useAuthStore()
const canWrite = computed(() => auth.can('ruang_tindakan.write'))

// ─── Tab aktif ──────────────────────────────────────────────────────────────────
const activeTab = ref('antrean')   // 'antrean' | 'terjadwal'

// ─── State papan ──────────────────────────────────────────────────────────────
const board = ref([])
const loading = ref(false)
const selId = ref(null)
const sel = computed(() => board.value.find(p => p.id === selId.value) || null)

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
const submitting = ref(false)   // kunci anti double-submit "Selesai Tindakan"
const resepSaved = ref(false)   // resep sudah terkirim → jangan dobel saat retry

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

function selectPatient(p) {
  selId.value = p.id
  // Hydrate form dari laporan tersimpan (operation_report) + kolom record
  // (complication/followup/disposition disimpan terpisah, bukan di operation_report).
  const rec = p.record
  form.value = {
    ...blankForm(),
    ...(rec?.laporan || {}),
    complication:        rec?.complication ?? '',
    followup_date:       rec?.followup_date ?? '',
    post_op_disposition: rec?.post_op_disposition ?? 'PULANG',
  }
  resepItems.value = []
  resepSaved.value = false
  obatSearch.value = ''
  obatOptions.value = []
}

// ─── Resep obat pulang (→ Farmasi setelah Kasir) ──────────────────────────────
const obatSearch = ref('')
const obatOptions = ref([])
const resepItems = ref([])   // [{ medication_id, name, quantity, dose, frequency, duration_days }]
let obatTimer = null

function searchObat() {
  clearTimeout(obatTimer)
  obatTimer = setTimeout(async () => {
    try {
      const res = await ruangTindakanApi.daftarObat(obatSearch.value || undefined)
      obatOptions.value = res.data?.data || []
    } catch { obatOptions.value = [] }
  }, 300)
}
function addResepObat(med) {
  if (!med) return
  if (resepItems.value.some(x => x.medication_id === med.id)) { toast('e', 'Obat sudah ada di resep.'); return }
  resepItems.value.push({ medication_id: med.id, name: med.name, quantity: 1, dose: '', frequency: '', duration_days: null })
  obatSearch.value = ''
  obatOptions.value = []
}
function removeResepObat(i) { resepItems.value.splice(i, 1) }

// ─── Tab "Tindakan Terjadwal" (weekpicker) ────────────────────────────────────
const jadwal = ref([])
const jadwalLoading = ref(false)
const weekStart = ref(null)   // null → mendatang; else Senin minggu terpilih (YYYY-MM-DD)

function mondayOf(dateStr) {
  const d = new Date(dateStr + 'T00:00:00')
  const dow = (d.getDay() + 6) % 7
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
function onPickWeek(e) {
  weekStart.value = e.target.value ? mondayOf(e.target.value) : null
  loadJadwal()
}
function shiftWeek(n) {
  const base = weekStart.value ?? mondayOf(new Date().toISOString().slice(0, 10))
  weekStart.value = addDays(base, n * 7)
  loadJadwal()
}
function clearWeek() { weekStart.value = null; loadJadwal() }

async function loadJadwal() {
  jadwalLoading.value = true
  try {
    const res = await ruangTindakanApi.jadwal(weekStart.value || undefined, weekEnd.value || undefined)
    jadwal.value = res.data?.data || []
  } catch (e) {
    toast('e', e?.response?.data?.message || 'Gagal memuat jadwal.')
  } finally {
    jadwalLoading.value = false
  }
}

function switchTab(tab) {
  activeTab.value = tab
  if (tab === 'terjadwal' && !jadwal.value.length) loadJadwal()
}

const statusLabel = (s) => ({ SCHEDULED: 'Terjadwal', IN_PROGRESS: 'Berlangsung', DONE: 'Selesai', CANCELLED: 'Batal' }[s] || s || '—')
const fmtDate = (d) => d ? new Date(d + 'T00:00:00').toLocaleDateString('id-ID', { weekday: 'short', day: '2-digit', month: 'short' }) : '—'

// ─── Status helper ────────────────────────────────────────────────────────────
function schedStatus(p) { return p.schedule?.status || 'SCHEDULED' }
function isRunning(p) { return schedStatus(p) === 'IN_PROGRESS' }
function isDone(p) { return schedStatus(p) === 'DONE' }

// ─── Aksi lifecycle ───────────────────────────────────────────────────────────
async function panggil(p) {
  // Panggil beroperasi pada antrean (queue). Pasien terjadwal yang belum punya
  // baris antrean belum bisa dipanggil — langsung "Mulai Tindakan" saja.
  if (!p.queue_id) return toast('e', 'Antrean belum terbentuk — langsung Mulai Tindakan.')
  try { await ruangTindakanApi.panggil(p.queue_id); toast('s', 'Pasien dipanggil.'); await loadBoard() }
  catch (e) { toast('e', e?.response?.data?.message || 'Gagal memanggil.') }
}

async function mulai(p) {
  if (!p.schedule?.id) return toast('e', 'Jadwal tidak ditemukan.')
  try { await ruangTindakanApi.mulai(p.schedule.id); toast('s', 'Tindakan dimulai.'); await loadBoard() }
  catch (e) { toast('e', e?.response?.data?.message || 'Gagal memulai.') }
}

async function selesai(p) {
  if (!p.schedule?.id) return
  if (!form.value.laser_type) return toast('e', 'Pilih jenis laser dulu.')
  if (resepItems.value.some(it => !it.quantity || it.quantity < 1)) return toast('e', 'Jumlah obat resep minimal 1.')
  if (submitting.value) return            // kunci anti double-submit (cegah resep/selesai dobel)
  submitting.value = true
  try {
    // Resep obat pulang (bila ada) → Prescription SUBMITTED, muncul di Farmasi setelah
    // Kasir. Hanya kirim SEKALI: bila "selesai" gagal lalu di-retry, jangan buat resep
    // dobel (storePostOpPrescription tak idempoten).
    if (resepItems.value.length && !resepSaved.value) {
      await ruangTindakanApi.resep(p.schedule.id, {
        items: resepItems.value.map(it => ({
          medication_id: it.medication_id,
          quantity: Number(it.quantity) || 1,
          dose: it.dose || null,
          frequency: it.frequency || null,
          duration_days: it.duration_days ? Number(it.duration_days) : null,
        })),
      })
      resepSaved.value = true
    }
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
      post_op_disposition: form.value.post_op_disposition,
      followup_date: form.value.followup_date || null,
      complication: form.value.complication || null,
      notes: form.value.notes || null,
    })
    const toRanap = form.value.post_op_disposition === 'RAWAT_INAP'
    toast('s', toRanap
      ? 'Tindakan selesai → pasien diteruskan ke Rawat Inap (Menunggu Kamar).'
      : (resepItems.value.length
          ? 'Tindakan selesai → Kasir, lalu resep ke Farmasi.'
          : 'Tindakan selesai → pasien diteruskan ke Kasir.'))
    selId.value = null
    await loadBoard()
  } catch (e) {
    toast('e', e?.response?.data?.message || 'Gagal menyelesaikan tindakan.')
  } finally {
    submitting.value = false
  }
}

// ─── Polling ──────────────────────────────────────────────────────────────────
let pollTimer = null
onMounted(() => {
  loadBoard()
  // Polling hanya untuk papan antrean hari ini (tab terjadwal di-refresh manual).
  pollTimer = setInterval(() => { if (activeTab.value === 'antrean') loadBoard() }, 15000)
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
      <button v-if="activeTab === 'antrean'" class="btn-soft" @click="loadBoard" :disabled="loading">
        {{ loading ? 'Memuat…' : 'Muat ulang' }}
      </button>
      <button v-else class="btn-soft" @click="loadJadwal" :disabled="jadwalLoading">
        {{ jadwalLoading ? 'Memuat…' : 'Muat ulang' }}
      </button>
    </header>

    <!-- Tab -->
    <div class="rt-tabs">
      <button class="rt-tab" :class="{ active: activeTab === 'antrean' }" @click="switchTab('antrean')">
        Antrean Hari Ini <span class="tab-cnt">{{ board.length }}</span>
      </button>
      <button class="rt-tab" :class="{ active: activeTab === 'terjadwal' }" @click="switchTab('terjadwal')">
        Tindakan Terjadwal
      </button>
    </div>

    <!-- TAB 1: Antrean hari ini -->
    <div v-show="activeTab === 'antrean'" class="rt-body">
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
            <button v-if="sel.queue_id && sel.status !== 'CALLED'" class="btn-primary" :disabled="!canWrite" @click="panggil(sel)">Panggil Pasien</button>
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

            <!-- Tindakan tertagih dari PAKET yang dipilih dokter di planning (otomatis). -->
            <div class="bill-note">
              Tindakan yang ditagih mengikuti <b>paket</b> yang dipilih dokter saat planning
              <template v-if="sel.schedule?.package?.name"> — <b>{{ sel.schedule.package.name }}</b></template>.
              Tagihan diteruskan otomatis ke Kasir saat tindakan selesai.
            </div>

            <!-- Resep obat pulang → Farmasi (setelah Kasir) -->
            <div class="fg">
              <label>Resep Obat Pulang <span class="opt">(opsional → Farmasi setelah Kasir)</span></label>
              <div class="obat-search">
                <input v-model="obatSearch" class="inp" placeholder="Cari obat (nama/kode)…" @input="searchObat" />
                <div v-if="obatOptions.length" class="obat-drop">
                  <button v-for="o in obatOptions" :key="o.id" type="button" class="obat-opt" @click="addResepObat(o)">
                    {{ o.code ? o.code + ' · ' : '' }}{{ o.name }}<span v-if="o.unit" class="u">/{{ o.unit }}</span><span v-if="o.is_active === false" class="rx-inactive-badge" title="Obat nonaktif">nonaktif</span>
                  </button>
                </div>
              </div>
              <table v-if="resepItems.length" class="resep-tbl">
                <thead><tr><th>Obat</th><th>Jml</th><th>Dosis</th><th>Frekuensi</th><th>Hari</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="(it, i) in resepItems" :key="it.medication_id">
                    <td class="nm">{{ it.name }}</td>
                    <td><input v-model.number="it.quantity" type="number" min="1" class="inp xs" /></td>
                    <td><input v-model="it.dose" class="inp xs" placeholder="1 tetes" /></td>
                    <td><input v-model="it.frequency" class="inp xs" placeholder="3×/hari" /></td>
                    <td><input v-model.number="it.duration_days" type="number" min="1" class="inp xs" /></td>
                    <td><button type="button" class="rm-btn" @click="removeResepObat(i)">✕</button></td>
                  </tr>
                </tbody>
              </table>
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
              <button class="btn-primary accent" :disabled="!canWrite || submitting" @click="selesai(sel)">{{ submitting ? 'Menyimpan…' : 'Selesai Tindakan' }}</button>
            </div>
          </div>
        </template>
      </section>
    </div>

    <!-- TAB 2: Tindakan terjadwal (per minggu) -->
    <div v-show="activeTab === 'terjadwal'" class="rt-jadwal">
      <div class="wk-bar">
        <div class="wk-nav">
          <button class="btn-soft sm" @click="shiftWeek(-1)">‹ Minggu lalu</button>
          <span class="wk-label">{{ weekLabel }}</span>
          <button class="btn-soft sm" @click="shiftWeek(1)">Minggu depan ›</button>
        </div>
        <div class="wk-actions">
          <input type="date" class="inp sm" :value="weekStart || ''" @change="onPickWeek" title="Pilih tanggal → snap ke minggunya" />
          <button v-if="weekStart" class="btn-soft sm" @click="clearWeek">Semua mendatang</button>
        </div>
      </div>

      <div class="jd-wrap">
        <div v-if="jadwalLoading" class="empty">Memuat jadwal…</div>
        <div v-else-if="!jadwal.length" class="empty">
          Tidak ada tindakan terjadwal {{ weekStart ? 'pada minggu ini' : 'mendatang' }}.
        </div>
        <table v-else class="jd-table">
          <thead>
            <tr>
              <th>Tanggal</th><th>Jam</th><th>Pasien</th><th>No. RM</th>
              <th>Diagnosa</th><th>Paket</th><th>Ruang</th><th>Operator</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="j in jadwal" :key="j.schedule_id">
              <td>{{ fmtDate(j.scheduled_date) }}</td>
              <td>{{ j.scheduled_time ? String(j.scheduled_time).slice(0,5) : '—' }}</td>
              <td class="nm">{{ j.patient?.name || '—' }}
                <span class="sub">{{ j.patient?.gender }}{{ j.patient?.age != null ? ' · ' + j.patient.age + ' th' : '' }}</span>
              </td>
              <td>{{ j.patient?.no_rm || '—' }}</td>
              <td>{{ j.diagnosa || '—' }}</td>
              <td>{{ j.package || '—' }}</td>
              <td>{{ j.room || '—' }}</td>
              <td>{{ j.operator || '—' }}</td>
              <td><span class="jd-st" :class="'st-' + (j.status || '').toLowerCase()">{{ statusLabel(j.status) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
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

.bill-note { background: #f0f9ff; border: 1px solid #bae6fd; color: #0369a1; border-radius: 9px; padding: 9px 12px; font-size: 0.8rem; margin-bottom: 11px; line-height: 1.45; }
.opt { font-weight: 400; color: #94a3b8; font-size: 0.74rem; }

/* Resep obat pulang */
.obat-search { position: relative; }
.obat-drop { position: absolute; z-index: 5; left: 0; right: 0; top: calc(100% + 2px); background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; max-height: 200px; overflow: auto; box-shadow: 0 6px 18px rgba(15,23,42,.12); }
.obat-opt { display: block; width: 100%; text-align: left; border: none; background: none; padding: 7px 11px; font-size: 0.82rem; color: #334155; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
.obat-opt:hover { background: #f0f9ff; }
.obat-opt .u { color: #94a3b8; }
.rx-inactive-badge { display: inline-block; margin-left: 5px; font-size: 9px; font-weight: 700; color: #b45309; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 4px; padding: 0 5px; vertical-align: middle; }
.resep-tbl { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 0.8rem; }
.resep-tbl th { text-align: left; padding: 5px 7px; color: #64748b; font-weight: 600; font-size: 0.72rem; border-bottom: 1px solid #e2e8f0; }
.resep-tbl td { padding: 4px 7px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.resep-tbl .nm { font-weight: 600; color: #1e293b; }
.inp.xs { padding: 5px 7px; font-size: 0.8rem; width: 100%; box-sizing: border-box; }
.resep-tbl td:nth-child(2) { width: 64px; }
.resep-tbl td:nth-child(5) { width: 64px; }
.rm-btn { border: none; background: none; color: #dc2626; cursor: pointer; font-size: 0.85rem; padding: 2px 6px; }
.rm-btn:hover { color: #b91c1c; }

/* Tabs */
.rt-tabs { display: flex; gap: 4px; border-bottom: 1px solid #e2e8f0; margin-bottom: 16px; }
.rt-tab { border: none; background: none; padding: 9px 16px; font-size: 0.88rem; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; display: flex; align-items: center; gap: 7px; }
.rt-tab:hover { color: #0E3A66; }
.rt-tab.active { color: #0E3A66; border-bottom-color: #1FAAE0; }
.tab-cnt { background: #1FAAE0; color: #fff; border-radius: 999px; padding: 1px 8px; font-size: 0.7rem; }

/* Weekpicker bar */
.rt-jadwal { border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 18px; background: #fff; }
.wk-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.wk-nav { display: flex; align-items: center; gap: 10px; }
.wk-label { font-weight: 700; color: #0E3A66; font-size: 0.9rem; min-width: 150px; text-align: center; }
.wk-actions { display: flex; align-items: center; gap: 8px; }
.btn-soft.sm, .inp.sm { padding: 6px 11px; font-size: 0.8rem; }

/* Jadwal table */
.jd-wrap { overflow-x: auto; }
.jd-table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
.jd-table th { text-align: left; padding: 8px 10px; color: #64748b; font-weight: 600; font-size: 0.76rem; text-transform: uppercase; letter-spacing: .02em; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.jd-table td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: top; }
.jd-table .nm { font-weight: 600; }
.jd-table .nm .sub { display: block; font-weight: 400; color: #94a3b8; font-size: 0.74rem; margin-top: 1px; }
.jd-st { font-size: 0.7rem; font-weight: 700; padding: 2px 9px; border-radius: 999px; background: #f1f5f9; color: #475569; white-space: nowrap; }
.jd-st.st-scheduled { background: #e0f2fe; color: #0369a1; }
.jd-st.st-in_progress { background: #fef3c7; color: #b45309; }
.jd-st.st-done { background: #dcfce7; color: #15803d; }
.jd-st.st-cancelled { background: #fee2e2; color: #b91c1c; }

/* Toast */
.toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); padding: 11px 20px; border-radius: 9px; color: #fff; font-size: 0.88rem; font-weight: 600; box-shadow: 0 8px 24px rgba(15,23,42,.2); z-index: 100; }
.toast.s { background: #16a34a; }
.toast.e { background: #dc2626; }
.toast-enter-active, .toast-leave-active { transition: opacity .25s, transform .25s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translate(-50%, 10px); }
</style>
