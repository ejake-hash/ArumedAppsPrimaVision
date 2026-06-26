<script setup>
/**
 * BridgingRekamMedisView — dashboard WS Rekam Medis BPJS (push RM → i-Care).
 * Parity dgn dashboard Satu Sehat, disesuaikan konteks RM: banner status +
 * tombol kirim (batch hari ini / drain tunggakan), date-range, kartu kirim +
 * isi-bundle, kunjungan rentang, kesiapan data prasyarat, tren 7 hari, dan
 * tabel log (expand Bundle/respons + Retry per kunjungan gagal).
 *
 * CATATAN: rasio kepatuhan 4-minggu Kemenkes TIDAK ditampilkan — itu khusus
 * Satu Sehat; WS Rekam Medis hanya mengisi i-Care nasional (tanpa ambang rasio).
 */
import { ref, reactive, computed, onMounted } from 'vue'
import { integrasiApi } from '@/services/api'

// 'sv-SE' = YYYY-MM-DD zona perangkat (WIB). toISOString() = UTC → jam 00:00–07:00
// mundur 1 hari, rentang dashboard default salah hari.
const today = new Date().toLocaleDateString('sv-SE')

const data    = ref(null)
const range   = reactive({ from: today, to: today })
const loading = ref(true)
const busy    = ref(null)        // 'AUTO' | 'BACKLOG' saat batch berjalan
const resending = ref(null)      // visit_id baris yg sedang di-retry
const toast   = reactive({ show: false, ok: true, msg: '' })

// Log
const filters = reactive({ status: '', q: '', tanggal: '' })
const rows    = ref([])
const logMeta = reactive({ current: 1, last: 1, total: 0 })
const logLoading = ref(false)
const expanded = ref(null)
const showReady = ref(null)

function flash(ok, msg) {
  toast.ok = ok; toast.msg = msg; toast.show = true
  setTimeout(() => (toast.show = false), 3800)
}

async function loadDash() {
  loading.value = true
  try {
    const res = await integrasiApi.rmDashboard({ from: range.from, to: range.to })
    data.value = res.data?.data ?? null
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal memuat dashboard')
  } finally {
    loading.value = false
  }
}

async function loadLog(page = 1) {
  logLoading.value = true
  expanded.value = null
  try {
    const params = { per_page: 20, page }
    if (filters.status)  params.status  = filters.status
    if (filters.q)       params.q       = filters.q
    if (filters.tanggal) params.tanggal = filters.tanggal
    const res = await integrasiApi.rmLog(params)
    const p = res.data?.data ?? {}
    rows.value       = p.data ?? []
    logMeta.current  = p.current_page ?? 1
    logMeta.last     = p.last_page ?? 1
    logMeta.total    = p.total ?? 0
  } catch {
    rows.value = []
  } finally {
    logLoading.value = false
  }
}

async function kirimBatch(mode) {
  if (busy.value) return
  let limit = null
  if (mode === 'BACKLOG') {
    const ans = window.prompt('Berapa kunjungan tunggakan dikirim sekarang? (tertua dulu)', '200')
    if (ans === null) return
    limit = Math.max(1, parseInt(ans, 10) || 200)
  } else if (!window.confirm('Kirim rekam medis kunjungan SELESAI hari ini ke BPJS?')) {
    return
  }
  busy.value = mode
  try {
    const res = await integrasiApi.rmSendBatch({ mode, limit })
    const r = res.data?.data ?? {}
    flash(true, `Batch selesai: terkirim ${r.sent ?? 0}, gagal ${r.failed ?? 0}`)
    await Promise.all([loadDash(), loadLog(1)])
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal mengirim batch')
  } finally {
    busy.value = null
  }
}

async function retry(row) {
  if (!row.visit_id || resending.value) return
  resending.value = row.visit_id
  try {
    await integrasiApi.rmResend(row.visit_id)
    flash(true, 'Rekam medis terkirim ulang')
    await Promise.all([loadDash(), loadLog(logMeta.current)])
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal kirim ulang')
  } finally {
    resending.value = null
  }
}

// ── Derived ──────────────────────────────────────────────────────────────────
const conn    = computed(() => data.value?.connection ?? {})
const cards   = computed(() => data.value?.cards ?? {})
const rng     = computed(() => data.value?.range ?? {})
const ready   = computed(() => data.value?.readiness ?? {})
const trend   = computed(() => data.value?.trend ?? [])
const maxTrend = computed(() => Math.max(1, ...trend.value.map((t) => (t.success || 0) + (t.failed || 0))))

// Isi Bundle: urut & label resource FHIR yang dikirim.
const RES_LABEL = {
  Composition: 'Composition', Patient: 'Patient', Encounter: 'Encounter',
  Practitioner: 'Practitioner', Organization: 'Organization', Condition: 'Diagnosis',
  MedicationRequest: 'Resep', Procedure: 'Tindakan', Device: 'Alkes/BHP',
}
const breakdown = computed(() => {
  const b = data.value?.resource_breakdown ?? {}
  const order = Object.keys(RES_LABEL)
  const keys = [...order.filter((k) => k in b), ...Object.keys(b).filter((k) => !RES_LABEL[k])]
  return keys.map((k) => ({ key: k, label: RES_LABEL[k] ?? k, count: b[k] || 0 }))
})

const connBadge = computed(() => {
  const c = conn.value
  if (!c.has_credentials) return { cls: 'b-empty', txt: 'Belum dikonfigurasi' }
  if (!c.is_enabled)      return { cls: 'b-off',   txt: 'Nonaktif' }
  if (c.last_test_status === 'FAILED') return { cls: 'b-fail', txt: 'Aktif · Test gagal' }
  return { cls: 'b-ok', txt: 'Aktif' }
})

const readyWarnings = computed(() => {
  const r = ready.value
  const out = []
  if (r.doctors_without_sip_nik)  out.push({ kind: 'doctor', txt: `${r.doctors_without_sip_nik} dokter belum lengkap SIP/NIK`, list: r.doctors_without_sip_nik_list })
  if (r.medications_without_code) out.push({ kind: 'med',    txt: `${r.medications_without_code} obat belum punya kode lokal`, list: r.medications_without_code_list })
  if (r.clinic_has_kemenkes_code === false) out.push({ kind: 'clinic', txt: 'Kode Faskes Kemenkes (Organization) belum diisi di Profil Klinik', list: [] })
  if (r.visits_without_diagnosis) out.push({ kind: 'dx', txt: `${r.visits_without_diagnosis} kunjungan ber-SEP belum terkirim tanpa diagnosis ICD-10`, list: [] })
  return out
})

function fmtTime(t) {
  if (!t) return '—'
  return new Date(t).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}
function fmtDay(d) {
  if (!d) return ''
  const dt = new Date(d)
  return `${String(dt.getDate()).padStart(2, '0')}-${String(dt.getMonth() + 1).padStart(2, '0')}`
}
function pretty(v) {
  if (v == null) return '—'
  try { return JSON.stringify(typeof v === 'string' ? JSON.parse(v) : v, null, 2) }
  catch { return String(v) }
}

function applyRange() { loadDash() }

onMounted(() => { loadDash(); loadLog(1) })
</script>

<template>
  <div class="rm">
    <!-- BANNER STATUS + AKSI -->
    <section class="banner" :class="connBadge.cls">
      <div class="b-ic">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2" /></svg>
      </div>
      <div class="b-main">
        <div class="b-title">WS Rekam Medis BPJS <span class="badge" :class="connBadge.cls">{{ connBadge.txt }}</span></div>
        <div class="b-sub">
          <span v-if="conn.kode_faskes">PPK {{ conn.kode_faskes }}</span>
          <span v-if="conn.service_name">· service {{ conn.service_name }}</span>
          <span v-if="conn.last_tested_at">· Test {{ conn.last_test_status }} · {{ fmtTime(conn.last_tested_at) }}</span>
        </div>
      </div>
      <div class="b-actions">
        <button class="btn" :disabled="loading" @click="loadDash(); loadLog(logMeta.current)">↻ Segarkan</button>
        <button class="btn" :disabled="busy || !conn.is_enabled" :title="!conn.is_enabled ? 'Aktifkan kredensial REKAM_MEDIS dulu' : ''" @click="kirimBatch('BACKLOG')">
          {{ busy === 'BACKLOG' ? 'Mengirim…' : '⟳ Drain Tunggakan' }}
        </button>
        <button class="btn primary" :disabled="busy || !conn.is_enabled" :title="!conn.is_enabled ? 'Aktifkan kredensial REKAM_MEDIS dulu' : ''" @click="kirimBatch('AUTO')">
          {{ busy === 'AUTO' ? 'Mengirim…' : '⤴ Kirim Hari Ini' }}
        </button>
      </div>
    </section>

    <!-- DATE RANGE -->
    <div class="range">
      <span class="r-ic">📅</span>
      <label>Dari <input v-model="range.from" type="date" class="inp" @change="applyRange" /></label>
      <label>Sampai <input v-model="range.to" type="date" class="inp" @change="applyRange" /></label>
      <span class="muted">Angka kartu, isi bundle &amp; kunjungan mengikuti rentang ini.</span>
    </div>

    <p v-if="loading" class="muted">Memuat…</p>

    <template v-else>
      <!-- KARTU -->
      <div class="cards">
        <div class="card ok">
          <div class="card-v">{{ cards.sent_range ?? 0 }}</div>
          <div class="card-l">RM Terkirim (rentang)</div>
          <div class="card-s">{{ cards.today_sent ?? 0 }} hari ini</div>
        </div>
        <div class="card fail">
          <div class="card-v">{{ cards.failed_range ?? 0 }}</div>
          <div class="card-l">Gagal Kirim (rentang)</div>
          <div class="card-s">{{ cards.today_failed ?? 0 }} hari ini</div>
        </div>
        <div class="card">
          <div class="card-v">{{ cards.visits_sent ?? 0 }}</div>
          <div class="card-l">Total Kunjungan Terkirim</div>
          <div class="card-s">akumulatif</div>
        </div>
        <div class="card warn">
          <div class="card-v">{{ cards.visits_unsent ?? 0 }}</div>
          <div class="card-l">Belum Terkirim (backlog)</div>
          <div class="card-s">kunjungan BPJS ber-SEP</div>
        </div>
      </div>

      <!-- PANEL: kunjungan rentang + kesiapan data -->
      <div class="row2">
        <section class="panel">
          <h3>Kunjungan ber-SEP (rentang)</h3>
          <ul class="kv">
            <li><span class="k dot-navy">Total ber-SEP</span><b>{{ rng.total ?? 0 }}</b></li>
            <li><span class="k dot-green">Sudah terkirim</span><b class="green">{{ rng.sent ?? 0 }}</b></li>
            <li><span class="k dot-amber">Belum terkirim</span><b class="amber">{{ rng.pending ?? 0 }}</b></li>
            <li><span class="k dot-red">Gagal</span><b class="red">{{ rng.failed ?? 0 }}</b></li>
          </ul>
        </section>

        <section class="panel">
          <h3>Kesiapan Data <span class="muted">(prasyarat bundle diterima BPJS)</span></h3>
          <p v-if="!readyWarnings.length" class="ok-note">✓ Semua prasyarat utama terpenuhi.</p>
          <ul v-else class="warn-list">
            <li v-for="(w, i) in readyWarnings" :key="i">
              <span>⚠ {{ w.txt }}</span>
              <button v-if="w.list && w.list.length" class="link" @click="showReady = showReady === i ? null : i">
                {{ showReady === i ? 'Tutup' : 'Lihat' }}
              </button>
              <ul v-if="showReady === i" class="sublist">
                <li v-for="(it, j) in w.list" :key="j">{{ it.name }}<span v-if="w.kind==='doctor'" class="muted"> — SIP: {{ it.sip || '—' }} · NIK: {{ it.nik || '—' }}</span></li>
              </ul>
            </li>
          </ul>
        </section>
      </div>

      <!-- ISI BUNDLE (breakdown resource) -->
      <section class="panel">
        <h3>Isi Bundle Terkirim (rentang)</h3>
        <div v-if="breakdown.some((b) => b.count)" class="chips">
          <div v-for="b in breakdown" :key="b.key" class="chip" :class="{ zero: !b.count }">
            <span class="chip-v">{{ b.count }}</span><span class="chip-l">{{ b.label }}</span>
          </div>
        </div>
        <p v-else class="muted">Belum ada resource terkirim pada rentang ini.</p>
      </section>

      <!-- TREN 7 HARI -->
      <section class="panel">
        <div class="panel-head">
          <h3>Tren Kirim 7 Hari</h3>
          <span class="legend"><i class="lg green"></i> Sukses <i class="lg red"></i> Gagal</span>
        </div>
        <div class="trend">
          <div v-for="(t, i) in trend" :key="i" class="t-col">
            <div class="t-bars">
              <div class="t-bar green" :style="{ height: ((t.success || 0) / maxTrend * 70) + 'px' }" :title="t.success + ' sukses'"></div>
              <div class="t-bar red" :style="{ height: ((t.failed || 0) / maxTrend * 70) + 'px' }" :title="t.failed + ' gagal'"></div>
            </div>
            <div class="t-lbl">{{ fmtDay(t.date) }}</div>
          </div>
        </div>
      </section>

      <!-- LOG -->
      <section class="panel">
        <div class="toolbar">
          <select v-model="filters.status" class="inp" @change="loadLog(1)">
            <option value="">Semua status</option>
            <option value="SUCCESS">Sukses</option>
            <option value="FAILED">Gagal</option>
          </select>
          <input v-model="filters.q" type="text" class="inp grow" placeholder="Cari No. SEP / nama / No. RM…" @keyup.enter="loadLog(1)" />
          <input v-model="filters.tanggal" type="date" class="inp" @change="loadLog(1)" />
          <button class="btn" @click="loadLog(1)">Terapkan</button>
        </div>

        <p v-if="logLoading" class="muted">Memuat…</p>
        <p v-else-if="!rows.length" class="muted">Belum ada pengiriman rekam medis ke BPJS.</p>

        <table v-else class="tbl">
          <thead>
            <tr><th>Waktu</th><th>No. SEP</th><th>Pasien</th><th>HTTP</th><th>Status</th><th></th><th></th></tr>
          </thead>
          <tbody>
            <template v-for="row in rows" :key="row.id">
              <tr class="rw">
                <td @click="expanded = expanded === row.id ? null : row.id">{{ fmtTime(row.created_at) }}</td>
                <td @click="expanded = expanded === row.id ? null : row.id"><code>{{ row.no_sep ?? '—' }}</code></td>
                <td @click="expanded = expanded === row.id ? null : row.id">{{ row.visit?.patient?.name ?? '—' }}</td>
                <td @click="expanded = expanded === row.id ? null : row.id">{{ row.http_status ?? '—' }}</td>
                <td><span class="dot" :class="row.status === 'SUCCESS' ? 'ok' : 'fail'">{{ row.status === 'SUCCESS' ? 'Sukses' : 'Gagal' }}</span></td>
                <td>
                  <button v-if="row.status === 'FAILED' && row.visit_id" class="link" :disabled="resending === row.visit_id" @click="retry(row)">
                    {{ resending === row.visit_id ? '…' : 'Retry' }}
                  </button>
                </td>
                <td class="chev" @click="expanded = expanded === row.id ? null : row.id">{{ expanded === row.id ? '▾' : '▸' }}</td>
              </tr>
              <tr v-if="expanded === row.id" class="detail">
                <td colspan="7">
                  <div v-if="row.error_message" class="err">⚠ {{ row.error_message }}</div>
                  <div class="payloads">
                    <div>
                      <div class="pl-title">Bundle FHIR (dikirim)</div>
                      <pre>{{ pretty(row.fhir_payload) }}</pre>
                    </div>
                    <div>
                      <div class="pl-title">Respons BPJS</div>
                      <pre>{{ pretty(row.response_payload) }}</pre>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>

        <div v-if="logMeta.last > 1" class="pager">
          <button class="btn" :disabled="logMeta.current <= 1" @click="loadLog(logMeta.current - 1)">‹ Sebelumnya</button>
          <span class="muted">Hal {{ logMeta.current }} / {{ logMeta.last }} · {{ logMeta.total }} log</span>
          <button class="btn" :disabled="logMeta.current >= logMeta.last" @click="loadLog(logMeta.current + 1)">Berikutnya ›</button>
        </div>
      </section>
    </template>

    <transition name="t-fade">
      <div v-if="toast.show" class="toast" :class="toast.ok ? 'tk-ok' : 'tk-err'">{{ toast.msg }}</div>
    </transition>
  </div>
</template>

<style scoped>
.rm { display: flex; flex-direction: column; gap: 1rem; }
.muted { color: var(--tm); font-size: 12.5px; font-weight: 400; }

/* Banner */
.banner { display: flex; align-items: center; gap: 0.9rem; background: #fff; border: 1px solid var(--gb); border-left: 4px solid #94a3b8; border-radius: 14px; padding: 0.9rem 1.1rem; }
.banner.b-ok   { border-left-color: #16a34a; background: #f0fdf4; }
.banner.b-off  { border-left-color: #d97706; }
.banner.b-fail { border-left-color: #dc2626; background: #fef2f2; }
.banner.b-empty{ border-left-color: #94a3b8; }
.b-ic { width: 42px; height: 42px; border-radius: 11px; background: rgba(31,170,224,.12); color: #1faae0; display: grid; place-items: center; }
.b-ic svg { width: 20px; height: 20px; }
.b-main { flex: 1; min-width: 0; }
.b-title { font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 0.5rem; }
.b-sub { font-size: 11.5px; color: var(--tm); margin-top: 2px; display: flex; gap: 0.35rem; flex-wrap: wrap; }
.b-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.badge { font-size: 10.5px; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
.badge.b-ok { background: #dcfce7; color: #166534; } .badge.b-off { background: #fef3c7; color: #92400e; }
.badge.b-fail { background: #fee2e2; color: #991b1b; } .badge.b-empty { background: #f1f5f9; color: #475569; }

/* Range */
.range { display: flex; align-items: center; gap: 0.8rem; flex-wrap: wrap; background: #fff; border: 1px solid var(--gb); border-radius: 12px; padding: 0.7rem 1rem; }
.range label { font-size: 12.5px; color: var(--td); font-weight: 600; display: flex; align-items: center; gap: 0.4rem; }

/* Cards */
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem; }
.card { background: #fff; border: 1px solid var(--gb); border-radius: 12px; padding: 14px 16px; }
.card-v { font-size: 1.8rem; font-weight: 800; color: #0f172a; line-height: 1; }
.card-l { font-size: 12.5px; color: var(--tm); margin-top: 6px; font-weight: 600; }
.card-s { font-size: 11px; color: #94a3b8; margin-top: 2px; }
.card.ok { border-left: 4px solid #16a34a; } .card.fail { border-left: 4px solid #dc2626; } .card.warn { border-left: 4px solid #d97706; }

/* Panels */
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem; }
@media (max-width: 800px) { .row2 { grid-template-columns: 1fr; } }
.panel { background: #fff; border: 1px solid var(--gb); border-radius: 14px; padding: 1rem 1.15rem; }
.panel h3 { margin: 0 0 0.7rem; font-size: 13.5px; color: var(--td); font-weight: 700; }
.panel-head { display: flex; align-items: center; justify-content: space-between; }
.panel-head h3 { margin: 0; }
.legend { font-size: 11px; color: var(--tm); } .lg { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin: 0 2px 0 6px; vertical-align: middle; }
.lg.green { background: #16a34a; } .lg.red { background: #dc2626; }

.kv { list-style: none; margin: 0; padding: 0; }
.kv li { display: flex; align-items: center; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--gl); font-size: 13px; }
.kv li:last-child { border-bottom: 0; }
.k { color: var(--tm); display: flex; align-items: center; gap: 7px; }
.k::before { content: ''; width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
.dot-navy::before { background: #1e3a5f; } .dot-green::before { background: #16a34a; } .dot-amber::before { background: #d97706; } .dot-red::before { background: #dc2626; }
.green { color: #16a34a; } .amber { color: #d97706; } .red { color: #dc2626; }

.ok-note { color: #166534; font-size: 12.5px; margin: 0; }
.warn-list { list-style: none; margin: 0; padding: 0; }
.warn-list > li { font-size: 12.5px; padding: 6px 0; border-bottom: 1px solid var(--gl); color: #92400e; }
.warn-list > li:last-child { border-bottom: 0; }
.sublist { margin: 6px 0 4px; padding-left: 18px; color: var(--td); max-height: 160px; overflow: auto; }
.sublist li { font-size: 12px; padding: 1px 0; }
.link { background: none; border: 0; color: #1faae0; font-weight: 600; font-size: 12px; cursor: pointer; padding: 0 4px; }
.link:disabled { opacity: 0.5; cursor: default; }

.chips { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.chip { display: flex; align-items: baseline; gap: 6px; background: #f8fafc; border: 1px solid var(--gb); border-radius: 9px; padding: 7px 11px; }
.chip.zero { opacity: 0.5; }
.chip-v { font-size: 16px; font-weight: 800; color: #0f172a; } .chip-l { font-size: 11.5px; color: var(--tm); }

.trend { display: flex; align-items: flex-end; gap: 0.6rem; height: 96px; padding-top: 6px; }
.t-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px; }
.t-bars { display: flex; align-items: flex-end; gap: 2px; height: 72px; }
.t-bar { width: 9px; border-radius: 2px 2px 0 0; min-height: 1px; } .t-bar.green { background: #16a34a; } .t-bar.red { background: #dc2626; }
.t-lbl { font-size: 10px; color: #94a3b8; }

/* Toolbar + table */
.toolbar { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; margin-bottom: 0.8rem; }
.inp { padding: 7px 9px; border: 1px solid var(--gb); border-radius: 8px; font-size: 12.5px; color: #000; background: #fff; }
.inp.grow { flex: 1; min-width: 180px; }
.btn { padding: 7px 12px; border: 1px solid var(--gb); border-radius: 8px; font-size: 12.5px; font-weight: 600; background: #fff; color: #0f172a; cursor: pointer; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn.primary { background: #0e3a66; color: #fff; border-color: #0e3a66; }

.tbl { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.tbl th { text-align: left; padding: 8px 10px; border-bottom: 2px solid var(--gb); color: var(--tm); font-weight: 600; }
.tbl td { padding: 8px 10px; border-bottom: 1px solid var(--gl); }
.rw td { cursor: pointer; } .rw:hover { background: #f8fafc; }
.chev { color: #94a3b8; text-align: center; width: 28px; }
.dot { padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.dot.ok { background: #dcfce7; color: #166534; } .dot.fail { background: #fee2e2; color: #991b1b; }
.detail td { background: #f8fafc; cursor: default; }
.payloads { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 700px) { .payloads { grid-template-columns: 1fr; } }
.pl-title { font-weight: 700; font-size: 11.5px; color: var(--tm); margin-bottom: 4px; }
.payloads pre { background: #0f172a; color: #e2e8f0; padding: 10px; border-radius: 8px; overflow: auto; max-height: 320px; font-size: 11px; }
.err { color: #991b1b; background: #fee2e2; padding: 8px 10px; border-radius: 7px; margin-bottom: 10px; font-size: 12.5px; }
.pager { display: flex; align-items: center; gap: 0.75rem; margin-top: 0.8rem; }

/* Toast */
.toast { position: fixed; bottom: 22px; right: 22px; z-index: 60; padding: 11px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; color: #fff; box-shadow: 0 10px 30px rgba(0,0,0,.18); }
.tk-ok { background: #16a34a; } .tk-err { background: #dc2626; }
.t-fade-enter-active, .t-fade-leave-active { transition: opacity .25s, transform .25s; }
.t-fade-enter-from, .t-fade-leave-to { opacity: 0; transform: translateY(8px); }
</style>
