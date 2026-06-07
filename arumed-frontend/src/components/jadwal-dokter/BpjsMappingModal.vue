<script setup>
/**
 * BpjsMappingModal — pusat integrasi BPJS terkait Jadwal Dokter.
 * Dipakai dari JadwalDokterView. Endpoint via integrasiApi (semua sudah ada di BE).
 *
 *  1. Pemetaan Poli   : poli_code lokal → kode poli BPJS (sumber /referensi/poli)
 *  2. Kode DPJP       : set employees.bpjs_dpjp_code per dokter (sumber /referensi/dokter)
 *  3. Sinkron Jadwal  : kirim jadwal minggu aktif ke BPJS Antrean
 *  4. Referensi BPJS  : lookup kamus kode (dipindah dari Bridging → VClaim)
 *  5. Waktu Tunggu    : dashboard waktu tunggu BPJS (dipindah dari Bridging → Antrean Online)
 *  6. Validasi Booking: cek kode JKN Mobile (dipindah dari Bridging → Antrean Online)
 */
import { ref, reactive, onMounted } from 'vue'
import { integrasiApi, masterApi } from '@/services/api'

const props = defineProps({
  weekStart: { type: String, default: null },
  initialTab: { type: String, default: 'poli' }, // tab dibuka pertama ('poli' | 'dpjp' | ...)
})
const emit = defineEmits(['close'])

const tab = ref(props.initialTab || 'poli') // poli | dpjp | sync

const loading = ref(true)
const toast = reactive({ show: false, ok: true, msg: '' })
function flash(ok, msg) { toast.ok = ok; toast.msg = msg; toast.show = true; setTimeout(() => (toast.show = false), 3000) }

// ── Data ────────────────────────────────────────────────────────────────────
const poliRows = ref([])   // { poli_code, poli_name, bpjs_poli_code, bpjs_poli_name, mapped }
const dokter   = ref([])   // employees (profession dokter) + bpjs_dpjp_code
const savingKey = ref(null)

async function load() {
  loading.value = true
  try {
    const [poliRes, pegRes] = await Promise.all([
      integrasiApi.poliMappingStatus(),
      masterApi.pegawai.list({ per_page: 200 }),
    ])
    poliRows.value = poliRes.data?.data ?? []

    const peg = pegRes.data?.data
    const list = Array.isArray(peg) ? peg : (peg?.data ?? [])
    // Hanya dokter (profession mengandung 'dokter') — DPJP itu dokter.
    dokter.value = list
      .filter((e) => (e.profession ?? '').toLowerCase().includes('dokter'))
      .map((e) => ({ id: e.id, name: e.name, sip: e.sip, draft: e.bpjs_dpjp_code ?? '', saved: e.bpjs_dpjp_code ?? '' }))
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal memuat data pemetaan')
  } finally {
    loading.value = false
  }
}

// ── Simpan pemetaan poli ──────────────────────────────────────────────────
async function savePoli(row) {
  if (!row.bpjs_poli_code) { flash(false, 'Isi kode poli BPJS dulu'); return }
  savingKey.value = 'poli:' + row.poli_code
  try {
    await integrasiApi.upsertPoliMapping({
      poli_code: row.poli_code,
      poli_name: row.poli_name,
      bpjs_poli_code: row.bpjs_poli_code,
      bpjs_poli_name: row.bpjs_poli_name || null,
    })
    row.mapped = true
    flash(true, `Poli ${row.poli_code} → ${row.bpjs_poli_code} disimpan`)
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal menyimpan poli')
  } finally {
    savingKey.value = null
  }
}

// ── Simpan kode DPJP ──────────────────────────────────────────────────────
async function saveDpjp(d) {
  savingKey.value = 'dpjp:' + d.id
  try {
    // Normalisasi: field kosong → null (clear). Samakan draft & saved agar
    // tombol Simpan & badge "Tersimpan" konsisten dengan nilai backend.
    const val = d.draft.trim() || ''
    await integrasiApi.setDpjpCode(d.id, { bpjs_dpjp_code: val || null })
    d.draft = val
    d.saved = val
    flash(true, val ? `Kode DPJP ${d.name} disimpan` : `Kode DPJP ${d.name} dikosongkan`)
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal menyimpan DPJP')
  } finally {
    savingKey.value = null
  }
}

// ── Referensi helper (cari kode BPJS) ─────────────────────────────────────
const refModal = reactive({ open: false, jenis: 'poli', q: '', loading: false, rows: [], target: null })
function openRef(jenis, target) {
  refModal.open = true; refModal.jenis = jenis; refModal.target = target; refModal.q = ''; refModal.rows = []
}
async function searchRef() {
  refModal.loading = true; refModal.rows = []
  try {
    const res = await integrasiApi.referensi(refModal.jenis, { q: refModal.q.trim() })
    const bpjs = res.data?.data ?? {}
    if (!bpjs.is_success) { flash(false, bpjs.metaData?.message || 'Pencarian gagal'); return }
    const d = bpjs.response ?? {}
    refModal.rows = Array.isArray(d) ? d : (d.list ?? d.poli ?? [])
  } catch (e) {
    flash(false, (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message ?? 'Referensi gagal'))
  } finally {
    refModal.loading = false
  }
}
function pickRef(it) {
  const code = it.kode ?? it.kodeDokter ?? it.kodePoli
  const name = it.nama ?? it.namaDokter ?? it.namaPoli
  if (refModal.jenis === 'poli' && refModal.target) {
    refModal.target.bpjs_poli_code = code
    refModal.target.bpjs_poli_name = name
  } else if (refModal.jenis === 'dokter' && refModal.target) {
    refModal.target.draft = code
  }
  refModal.open = false
}

// ── Referensi BPJS (lookup mandiri — kamus kode, dipindah dari tab VClaim) ────
// Berbeda dari refModal di atas yg merupakan PICKER (terikat target pemetaan);
// ini lookup browse-only 6 jenis untuk mencari kode resmi BPJS.
const REF_JENIS = [
  { v: 'diagnosa',     l: 'Diagnosa (ICD-10)' },
  { v: 'poli',         l: 'Poli' },
  { v: 'dokter',       l: 'Dokter (DPJP)' },
  { v: 'procedure',    l: 'Prosedur (ICD-9)' },
  { v: 'spesialistik', l: 'Spesialistik' },
  { v: 'kelasrawat',   l: 'Kelas Rawat' },
]
const lookup = reactive({ jenis: 'diagnosa', q: '', loading: false, rows: [], searched: false })
async function searchLookup() {
  lookup.loading = true; lookup.rows = []; lookup.searched = true
  try {
    const res = await integrasiApi.referensi(lookup.jenis, { q: lookup.q.trim() })
    const bpjs = res.data?.data ?? {}
    if (!bpjs.is_success) { flash(false, bpjs.metaData?.message || 'Pencarian gagal'); return }
    const d = bpjs.response ?? {}
    lookup.rows = Array.isArray(d) ? d : (d.list ?? d.diagnosa ?? d.poli ?? d.procedure ?? d.faskes ?? [])
  } catch (e) {
    flash(false, (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message ?? 'Referensi gagal'))
  } finally {
    lookup.loading = false
  }
}
const refCode = (it) => it.kode ?? it.kodeDokter ?? it.kodePoli ?? it.kodeDiagnosa ?? '—'
const refName = (it) => it.nama ?? it.namaDokter ?? it.namaPoli ?? it.namaDiagnosa ?? '—'
async function copyCode(code) {
  try { await navigator.clipboard.writeText(code); flash(true, `Kode "${code}" disalin`) } catch { /* clipboard tak tersedia */ }
}

// ── Antrean Online (Dashboard Waktu Tunggu + Validasi Booking) ────────────────
// Dipindah dari section Bridging → Antrean Online ke modul Jadwal Dokter.
// Tanggal lokal (WIB) — bukan toISOString() (UTC bisa mundur 1 hari di pagi WIB).
const todayWib = () => new Date().toLocaleDateString('sv-SE')
function makePanel() { return reactive({ loading: false, error: '', data: null }) }
async function runAntrean(panel, fn) {
  panel.loading = true; panel.error = ''; panel.data = null
  try {
    const res = await fn()
    const bpjs = res.data?.data ?? {}
    if (bpjs.is_success) panel.data = bpjs.response ?? bpjs
    else panel.error = bpjs.metaData?.message || res.data?.message || 'Tidak ada data.'
  } catch (e) {
    const s = e.response?.status
    panel.error = (s === 503 ? '⚠ ' : '') + (e.response?.data?.message || 'Gagal memanggil layanan.')
  } finally {
    panel.loading = false
  }
}
function prettyJson(v) { try { return JSON.stringify(v, null, 2) } catch { return String(v) } }

const dash = reactive({ jenis: 'tanggal', tanggal: todayWib(), bulan: String(new Date().getMonth() + 1).padStart(2, '0'), tahun: String(new Date().getFullYear()), waktu: 'rs', ...makePanel() })
function loadDashboard() {
  const p = dash.jenis === 'bulan'
    ? { bulan: dash.bulan, tahun: dash.tahun, waktu: dash.waktu }
    : { tanggal: dash.tanggal, waktu: dash.waktu }
  runAntrean(dash, () => integrasiApi.antreanDashboard(dash.jenis, p))
}

const booking = reactive({ code: '', tgl: todayWib(), ...makePanel() })
function validateBooking() {
  runAntrean(booking, () => integrasiApi.validateBooking({ booking_code: booking.code.trim(), tgl_periksa: booking.tgl }))
}

// ── Sinkron jadwal ke BPJS ──────────────────────────────────────────────────
const syncing = ref(false)
const syncResult = ref(null)
async function doSync() {
  if (!props.weekStart) { flash(false, 'Minggu tidak diketahui'); return }
  syncing.value = true; syncResult.value = null
  try {
    const res = await integrasiApi.syncJadwalDokter({ week_start: props.weekStart })
    syncResult.value = res.data?.data ?? null
    flash(true, 'Sinkron jadwal ke BPJS selesai')
  } catch (e) {
    flash(false, (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message ?? 'Sinkron gagal'))
  } finally {
    syncing.value = false
  }
}

onMounted(load)
</script>

<template>
  <Teleport to="body">
    <div class="bm-overlay" @click.self="emit('close')">
      <div class="bm-box">
        <div class="bm-head">
          <span>Pemetaan BPJS — Poli &amp; DPJP</span>
          <button class="bm-close" @click="emit('close')">✕</button>
        </div>

        <div class="bm-tabs">
          <button :class="{ active: tab === 'poli' }" @click="tab = 'poli'">Pemetaan Poli</button>
          <button :class="{ active: tab === 'dpjp' }" @click="tab = 'dpjp'">Kode DPJP</button>
          <button :class="{ active: tab === 'sync' }" @click="tab = 'sync'">Sinkron Jadwal</button>
          <button :class="{ active: tab === 'referensi' }" @click="tab = 'referensi'">Referensi BPJS</button>
          <button :class="{ active: tab === 'waktutunggu' }" @click="tab = 'waktutunggu'">Waktu Tunggu</button>
          <button :class="{ active: tab === 'booking' }" @click="tab = 'booking'">Validasi Booking</button>
        </div>

        <div class="bm-body">
          <p v-if="loading" class="muted">Memuat…</p>

          <!-- POLI -->
          <template v-else-if="tab === 'poli'">
            <p class="hint">Petakan kode poli lokal (dari jadwal dokter) ke kode poli BPJS. Klik "Cari" untuk ambil dari referensi BPJS.</p>
            <p v-if="!poliRows.length" class="muted">Belum ada poli pada jadwal. Tambahkan jadwal dokter ber-<code>poli_code</code> dulu.</p>
            <table v-else class="bm-tbl">
              <thead><tr><th>Poli Lokal</th><th>Kode BPJS</th><th>Nama BPJS</th><th></th></tr></thead>
              <tbody>
                <tr v-for="row in poliRows" :key="row.poli_code">
                  <td>
                    <code>{{ row.poli_code }}</code>
                    <div class="sub">{{ row.poli_name || '—' }}</div>
                  </td>
                  <td><input v-model="row.bpjs_poli_code" class="inp sm" placeholder="kode" /></td>
                  <td><input v-model="row.bpjs_poli_name" class="inp" placeholder="nama poli BPJS (opsional)" /></td>
                  <td class="actions">
                    <button class="btn ghost" @click="openRef('poli', row)">Cari</button>
                    <button class="btn primary" :disabled="savingKey === 'poli:' + row.poli_code" @click="savePoli(row)">
                      {{ savingKey === 'poli:' + row.poli_code ? '…' : 'Simpan' }}
                    </button>
                    <span v-if="row.mapped" class="ok-dot" title="Sudah dipetakan">✓</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </template>

          <!-- DPJP -->
          <template v-else-if="tab === 'dpjp'">
            <p class="hint">Set kode DPJP BPJS tiap dokter (dari referensi dokter BPJS). Dipakai saat terbitkan SEP &amp; sinkron jadwal.</p>
            <p v-if="!dokter.length" class="muted">Tidak ada pegawai dengan profesi dokter.</p>
            <table v-else class="bm-tbl">
              <thead><tr><th class="num">No.</th><th>Dokter</th><th>Kode DPJP BPJS</th><th></th></tr></thead>
              <tbody>
                <tr v-for="(d, i) in dokter" :key="d.id">
                  <td class="num">{{ i + 1 }}</td>
                  <td>{{ d.name }}<div class="sub">{{ d.sip || '—' }}</div></td>
                  <td><input v-model="d.draft" class="inp sm" placeholder="kode DPJP" /></td>
                  <td class="actions">
                    <button class="btn ghost" @click="openRef('dokter', d)">Cari</button>
                    <button class="btn primary" :disabled="savingKey === 'dpjp:' + d.id || d.draft.trim() === d.saved" @click="saveDpjp(d)">
                      {{ savingKey === 'dpjp:' + d.id ? '…' : 'Simpan' }}
                    </button>
                    <span v-if="d.saved" class="ok-dot" title="Tersimpan">✓</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </template>

          <!-- SYNC -->
          <template v-else-if="tab === 'sync'">
            <p class="hint">Kirim jadwal dokter minggu aktif ke BPJS Antrean (untuk JKN Mobile). Dokter/poli yang belum dipetakan akan dilewati.</p>
            <button class="btn primary lg" :disabled="syncing" @click="doSync">
              {{ syncing ? 'Mengirim…' : 'Sinkron Jadwal Minggu Ini ke BPJS' }}
            </button>

            <div v-if="syncResult" class="sync-out">
              <div class="sync-sec">
                <strong>Terkirim ({{ syncResult.sent?.length || 0 }})</strong>
                <ul>
                  <li v-for="(s, i) in syncResult.sent" :key="i" :class="s.is_success ? 'ok' : 'fail'">
                    {{ s.dokter }} · poli {{ s.poli }} — {{ s.is_success ? 'OK' : (s.message || 'gagal') }}
                  </li>
                </ul>
              </div>
              <div v-if="syncResult.skipped?.length" class="sync-sec">
                <strong>Dilewati ({{ syncResult.skipped.length }})</strong>
                <ul>
                  <li v-for="(s, i) in syncResult.skipped" :key="i" class="warn">
                    {{ s.dokter || '—' }} · {{ s.poli_code }} — {{ s.alasan }}
                  </li>
                </ul>
              </div>
            </div>
          </template>

          <!-- REFERENSI BPJS (lookup kamus kode — dipindah dari tab VClaim) -->
          <template v-else-if="tab === 'referensi'">
            <p class="hint">Cari kode resmi BPJS (diagnosa, poli, dokter, prosedur, dll). Pakai kode ini untuk mengisi Pemetaan Poli &amp; Kode DPJP di tab sebelah. Klik kode untuk menyalin.</p>
            <div class="form-row">
              <select v-model="lookup.jenis" class="inp" style="flex:0 0 auto;width:auto">
                <option v-for="r in REF_JENIS" :key="r.v" :value="r.v">{{ r.l }}</option>
              </select>
              <input v-model="lookup.q" class="inp" placeholder="Kata kunci (kode atau nama)…" @keyup.enter="searchLookup" />
              <button class="btn primary" :disabled="lookup.loading" @click="searchLookup">{{ lookup.loading ? '…' : 'Cari' }}</button>
            </div>

            <table v-if="lookup.rows.length" class="bm-tbl">
              <thead><tr><th>Kode</th><th>Nama</th></tr></thead>
              <tbody>
                <tr v-for="(it, i) in lookup.rows" :key="i">
                  <td><button class="code-copy" :title="'Salin ' + refCode(it)" @click="copyCode(refCode(it))"><code>{{ refCode(it) }}</code></button></td>
                  <td>{{ refName(it) }}</td>
                </tr>
              </tbody>
            </table>
            <p v-else-if="lookup.searched && !lookup.loading" class="muted">Tidak ada hasil.</p>
          </template>

          <!-- WAKTU TUNGGU (Dashboard waktu tunggu BPJS — dipindah dari Antrean Online) -->
          <template v-else-if="tab === 'waktutunggu'">
            <p class="hint">Data waktu tunggu yang dilaporkan ke BPJS (penilaian keaktifan faskes).</p>
            <div class="form-row">
              <select v-model="dash.jenis" class="inp" style="flex:0 0 auto;width:auto">
                <option value="tanggal">Per Tanggal</option>
                <option value="bulan">Per Bulan</option>
              </select>
              <input v-if="dash.jenis === 'tanggal'" v-model="dash.tanggal" type="date" class="inp" style="flex:0 0 auto;width:auto" />
              <template v-else>
                <select v-model="dash.bulan" class="inp" style="flex:0 0 auto;width:auto">
                  <option v-for="m in 12" :key="m" :value="String(m).padStart(2, '0')">{{ String(m).padStart(2, '0') }}</option>
                </select>
                <input v-model="dash.tahun" class="inp" style="flex:0 0 auto;width:80px" placeholder="Tahun" />
              </template>
              <select v-model="dash.waktu" class="inp" style="flex:0 0 auto;width:auto">
                <option value="rs">Waktu RS</option>
                <option value="server">Waktu Server BPJS</option>
              </select>
              <button class="btn primary" :disabled="dash.loading" @click="loadDashboard">{{ dash.loading ? '…' : 'Tampilkan' }}</button>
            </div>
            <p v-if="dash.error" class="muted err-note">{{ dash.error }}</p>
            <pre v-else-if="dash.data" class="json">{{ prettyJson(dash.data) }}</pre>
          </template>

          <!-- VALIDASI BOOKING (cek kode JKN Mobile — dipindah dari Antrean Online) -->
          <template v-else>
            <p class="hint">Cek kode booking dari aplikasi Mobile JKN sebelum pasien dilayani.</p>
            <div class="form-row">
              <input v-model="booking.code" class="inp" placeholder="Kode booking JKN Mobile" @keyup.enter="validateBooking" />
              <input v-model="booking.tgl" type="date" class="inp" style="flex:0 0 auto;width:auto" />
              <button class="btn primary" :disabled="booking.loading || !booking.code" @click="validateBooking">{{ booking.loading ? '…' : 'Validasi' }}</button>
            </div>
            <p v-if="booking.error" class="muted err-note">{{ booking.error }}</p>
            <pre v-else-if="booking.data" class="json">{{ prettyJson(booking.data) }}</pre>
          </template>
        </div>
      </div>
    </div>

    <!-- Referensi picker -->
    <div v-if="refModal.open" class="bm-overlay" style="z-index: 1200" @click.self="refModal.open = false">
      <div class="bm-box sm-box">
        <div class="bm-head">
          <span>Cari {{ refModal.jenis === 'poli' ? 'Poli' : 'Dokter' }} BPJS</span>
          <button class="bm-close" @click="refModal.open = false">✕</button>
        </div>
        <div class="bm-body">
          <div class="form-row">
            <input v-model="refModal.q" class="inp" placeholder="kata kunci…" @keyup.enter="searchRef" autofocus />
            <button class="btn primary" :disabled="refModal.loading" @click="searchRef">{{ refModal.loading ? '…' : 'Cari' }}</button>
          </div>
          <table v-if="refModal.rows.length" class="bm-tbl">
            <thead><tr><th>Kode</th><th>Nama</th><th></th></tr></thead>
            <tbody>
              <tr v-for="(it, i) in refModal.rows" :key="i">
                <td><code>{{ it.kode ?? it.kodeDokter ?? it.kodePoli }}</code></td>
                <td>{{ it.nama ?? it.namaDokter ?? it.namaPoli }}</td>
                <td><button class="btn ghost" @click="pickRef(it)">Pilih</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <transition name="fade">
      <div v-if="toast.show" class="bm-toast" :class="toast.ok ? 't-ok' : 't-fail'">{{ toast.msg }}</div>
    </transition>
  </Teleport>
</template>

<style scoped>
.bm-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.45); display: flex; align-items: center; justify-content: center; z-index: 1100; padding: 1rem; }
.bm-box { background: #fff; border-radius: 14px; width: 760px; max-width: 100%; max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 50px rgba(15,23,42,0.25); }
.sm-box { width: 480px; }

.bm-head { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid var(--gb); font-weight: 600; color: var(--td); font-size: 15px; }
.bm-close { border: none; background: transparent; font-size: 16px; cursor: pointer; color: var(--tm); }

.bm-tabs { display: flex; gap: 2px; padding: 0 18px; border-bottom: 1px solid var(--gb); }
.bm-tabs button { padding: 10px 14px; border: none; background: transparent; font-size: 13px; font-weight: 600; color: var(--tm); cursor: pointer; border-bottom: 2px solid transparent; }
.bm-tabs button.active { color: #1763d4; border-bottom-color: #1763d4; }

.bm-body { padding: 16px 18px; overflow-y: auto; }
.muted { color: var(--tm); font-size: 13px; }
.hint { font-size: 12px; color: var(--tm); margin: 0 0 0.8rem; }

.bm-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.bm-tbl th { text-align: left; padding: 7px 8px; background: var(--bs); color: var(--tm); font-size: 11px; text-transform: uppercase; }
.bm-tbl td { padding: 7px 8px; border-top: 1px solid var(--gb); color: var(--td); vertical-align: top; }
.bm-tbl th.num, .bm-tbl td.num { width: 38px; text-align: right; color: var(--tm); padding-right: 12px; }
.bm-tbl code { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--td); }
.sub { font-size: 11px; color: var(--tm); margin-top: 2px; }

.inp { padding: 6px 8px; border: 1px solid var(--gb); border-radius: 6px; font-size: 13px; color: #000; background: #fff; width: 100%; }
.inp.sm { width: 110px; }
.inp:focus { outline: none; border-color: #1763d4; }

.actions { display: flex; gap: 5px; align-items: center; white-space: nowrap; }
.btn { padding: 6px 11px; border: 1px solid var(--gb); border-radius: 6px; font-size: 12px; font-weight: 600; background: #fff; color: #000; cursor: pointer; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn.primary { background: #1763d4; color: #fff; border-color: #1763d4; }
.btn.ghost { color: #1763d4; border-color: #1763d4; }
.btn.lg { padding: 10px 18px; font-size: 13px; }
.ok-dot { color: #166534; font-weight: 700; }

.form-row { display: flex; gap: 0.5rem; margin-bottom: 0.8rem; }
.form-row .inp { flex: 1; }

/* Referensi BPJS: kode dapat diklik untuk salin */
.code-copy { border: none; background: transparent; padding: 0; cursor: pointer; }
.code-copy code { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: #1763d4; font-weight: 600; }
.code-copy:hover code { text-decoration: underline; }

/* Antrean Online: output JSON + pesan error */
.json { margin: 0.6rem 0 0; padding: 12px; background: #0f172a; color: #e2e8f0; border-radius: 8px; font-size: 11.5px; line-height: 1.5; max-height: 380px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
.err-note { background: #fef3c7; color: #92400e; padding: 9px 11px; border-radius: 8px; margin: 0.6rem 0 0; }

.sync-out { margin-top: 1rem; display: flex; flex-direction: column; gap: 0.8rem; }
.sync-sec strong { font-size: 12.5px; color: var(--td); }
.sync-sec ul { margin: 0.3rem 0 0; padding-left: 1.1rem; }
.sync-sec li { font-size: 12.5px; margin: 2px 0; }
.sync-sec li.ok { color: #166534; }
.sync-sec li.fail { color: #991b1b; }
.sync-sec li.warn { color: #92400e; }

.bm-toast { position: fixed; bottom: 24px; right: 24px; padding: 10px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; color: #fff; z-index: 1300; box-shadow: 0 8px 24px rgba(15,23,42,0.2); }
.t-ok { background: #166534; }
.t-fail { background: #991b1b; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
