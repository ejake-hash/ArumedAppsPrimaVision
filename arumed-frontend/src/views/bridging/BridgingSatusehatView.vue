<script setup>
/**
 * BridgingSatusehatView — dashboard monitoring Satu Sehat (Fase 6).
 * 4 stat-card resource + status koneksi + kesiapan data + tren + Sync Manual + batch/Retry.
 * Semua angka dari /integrasi/satusehat/dashboard (baca log lokal). Sync Manual & Retry live.
 */
import { ref, reactive, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { integrasiApi } from '@/services/api'

const loading = ref(true)
const syncing = ref(false)
const retrying = ref(null)
const data = ref(null)
// Tanggal lokal (WIB), BUKAN toISOString() (UTC) — di WIB jam 00:00–07:00 UTC
// masih tanggal kemarin → rentang dashboard salah hari. sv-SE = YYYY-MM-DD.
const today = new Date().toLocaleDateString('sv-SE')
const range = reactive({ from: today, to: today })
const toast = reactive({ show: false, ok: true, msg: '' })

function flash(ok, msg) { toast.ok = ok; toast.msg = msg; toast.show = true; setTimeout(() => (toast.show = false), 3500) }

async function load() {
  loading.value = true
  try {
    const res = await integrasiApi.satusehatDashboard({ from: range.from, to: range.to })
    data.value = res.data?.data ?? null
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal memuat dashboard Satu Sehat')
  } finally {
    loading.value = false
  }
}

async function syncManual() {
  syncing.value = true
  try {
    const res = await integrasiApi.satusehatSyncManual()
    const l = res.data?.data
    // notes berisi warning KFA (obat tanpa kfa_code tak terkirim) — tampilkan agar
    // petugas tahu peresepan tak ikut, bukan terlihat "sukses penuh".
    const note = l?.notes ? ` — ${l.notes}` : ''
    flash(true, `Sync selesai: ${l?.status ?? ''} (terkirim ${l?.total_sent ?? 0}, gagal ${l?.total_failed ?? 0})${note}`)
    await Promise.all([load(), loadBatches(1)])
  } catch (e) {
    flash(false, (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message ?? 'Sync gagal'))
  } finally {
    syncing.value = false
  }
}

async function retry(logId) {
  retrying.value = logId
  try {
    const res = await integrasiApi.satusehatRetry(logId)
    const l = res.data?.data
    flash(true, `Retry selesai: ${l?.status ?? ''}`)
    await Promise.all([load(), loadBatches(bt.page)])
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Retry gagal')
  } finally {
    retrying.value = null
  }
}

const conn = computed(() => data.value?.connection ?? {})
const connOk = computed(() => conn.value.is_enabled && conn.value.last_test_status === 'SUCCESS')

// Meta per kartu resource: ikon + warna aksen (chip ikon) — selaras gaya dashboard.
const CARD_META = {
  Encounter:          { label: 'Kunjungan',   icon: 'visit', tone: 'navy' },
  Condition:          { label: 'Diagnosis',   icon: 'diag',  tone: 'cyan' },
  MedicationRequest:  { label: 'Peresepan',   icon: 'rx',    tone: 'green' },
  MedicationDispense: { label: 'Obat Pulang', icon: 'pill',  tone: 'amber' },
}
const cards = computed(() => {
  const c = data.value?.cards ?? {}
  return Object.entries(CARD_META).map(([key, meta]) => {
    const v = c[key] ?? {}
    const success = v.success || 0
    const failed = v.failed || 0
    const total = success + failed
    return { key, ...meta, success, failed, rate: total ? Math.round((success / total) * 100) : null }
  })
})
const visits = computed(() => data.value?.visits ?? {})
const readiness = computed(() => data.value?.readiness ?? {})
const trend = computed(() => data.value?.trend ?? [])
const maxTrend = computed(() => Math.max(1, ...trend.value.map((t) => (t.success || 0) + (t.failed || 0))))

// Tiap warning punya `kind` → tombol "Atur" buka modal detail in-place (daftar
// dokter/obat + aksi), BUKAN link generik (link lama /inventori-farmasi malah
// redirect ke request-unit yang butuh permission lain → kebuang ke dashboard).
const readinessWarnings = computed(() => {
  const r = readiness.value
  const w = []
  if (r.doctors_without_nik) w.push({ txt: `${r.doctors_without_nik} dokter belum punya NIK`, kind: 'dokter', label: 'Atur →' })
  if (r.medications_without_kfa) w.push({ txt: `${r.medications_without_kfa} obat belum punya kode KFA`, kind: 'obat', label: 'Atur →' })
  if (r.patients_without_ihs) w.push({
    txt: `${r.patients_without_ihs.toLocaleString('id-ID')} pasien belum punya IHS`
      + (r.patients_resolvable_ihs ? ` — ${r.patients_resolvable_ihs.toLocaleString('id-ID')} ber-NIK siap di-resolve` : ''),
    kind: 'ihs',
    label: 'Resolve →',
  })
  return w
})

// ── Modal "Atur" Kesiapan Data (dokter NIK / obat KFA / resolve IHS massal) ──
const ready = reactive({
  open: false,
  kind: null,        // 'dokter' | 'obat' | 'ihs'
  nik: {},           // draft NIK per employee id
  saving: null,      // employee id yang sedang disimpan
  ihsLimit: 200,
  ihsRunning: false,
  ihsResult: null,   // hasil run terakhir { processed, resolved, not_found, ... }
})

function openReady(kind) {
  ready.open = true
  ready.kind = kind
  ready.ihsResult = null
}

async function saveNik(emp) {
  const nik = (ready.nik[emp.id] || '').trim()
  if (!/^\d{16}$/.test(nik)) { flash(false, 'NIK harus 16 digit angka.'); return }
  ready.saving = emp.id
  try {
    await integrasiApi.setEmployeeNik(emp.id, { nik })
    flash(true, `NIK ${emp.name} tersimpan`)
    await load() // daftar dokter-tanpa-NIK menyusut
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal menyimpan NIK')
  } finally {
    ready.saving = null
  }
}

async function runResolveIhs() {
  ready.ihsRunning = true
  try {
    const res = await integrasiApi.satusehatResolveIhs({ limit: Number(ready.ihsLimit) })
    const r = res.data?.data ?? null
    ready.ihsResult = r
    flash(true, `Resolve IHS: ${r?.resolved ?? 0} berhasil, ${r?.not_found ?? 0} NIK tak ditemukan`
      + (r?.error ? ` — terhenti: ${r.error}` : ''))
    await load()
  } catch (e) {
    flash(false, (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message ?? 'Resolve IHS gagal'))
  } finally {
    ready.ihsRunning = false
  }
}

// ── Riwayat Batch Sync — paginasi server (GET /satusehat/sync-log) ───────────
const bt = reactive({ rows: [], page: 1, last_page: 1, total: 0, per_page: 10, loading: false })

async function loadBatches(page = 1) {
  bt.loading = true
  try {
    const res = await integrasiApi.satusehatSyncLog({ page, per_page: bt.per_page })
    const p = res.data?.data
    bt.rows = p?.data ?? []
    bt.page = p?.current_page ?? 1
    bt.last_page = p?.last_page ?? 1
    bt.total = p?.total ?? bt.rows.length
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal memuat riwayat batch')
  } finally {
    bt.loading = false
  }
}

const statusClass = (s) => ({ SUCCESS: 's-ok', PARTIAL: 's-warn', FAILED: 's-fail', RUNNING: 's-run' }[s] || 's-idle')

// ── Backfill kunjungan historis ──────────────────────────────────────────────
const backfill = reactive({
  open: false,
  from: '', to: '',        // rentang opsional (kosong = semua histori)
  limit: 200,              // jumlah diproses per jalan
  checking: false,
  running: false,
  preview: null,           // { eligible, pending_total, skipped_* }
})

function openBackfill() {
  backfill.open = true
  backfill.preview = null
  checkBackfill()
}

async function checkBackfill() {
  backfill.checking = true
  try {
    const res = await integrasiApi.satusehatBackfillPreview({
      from: backfill.from || undefined,
      to: backfill.to || undefined,
    })
    backfill.preview = res.data?.data ?? null
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal cek data backfill')
  } finally {
    backfill.checking = false
  }
}

async function runBackfill() {
  if (!backfill.preview?.eligible) { flash(false, 'Tidak ada kunjungan eligible untuk diproses.'); return }
  backfill.running = true
  try {
    const res = await integrasiApi.satusehatBackfill({
      limit: Number(backfill.limit),
      from: backfill.from || undefined,
      to: backfill.to || undefined,
    })
    const l = res.data?.data
    flash(true, `Backfill ${l?.status ?? ''}: terkirim ${l?.total_sent ?? 0}, gagal ${l?.total_failed ?? 0}. ${l?.notes ?? ''}`)
    await checkBackfill()  // refresh sisa eligible
    await Promise.all([load(), loadBatches(1)]) // refresh kartu & riwayat batch
  } catch (e) {
    flash(false, (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message ?? 'Backfill gagal'))
  } finally {
    backfill.running = false
  }
}

onMounted(() => { load(); loadBatches() })
</script>

<template>
  <div class="ss">
    <!-- Banner koneksi -->
    <section class="banner" :class="connOk ? 'b-ok' : 'b-off'">
      <div class="b-left">
        <span class="b-ring" :class="connOk ? 'r-ok' : 'r-off'">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
          </svg>
        </span>
        <div class="b-body">
          <strong>{{ connOk ? 'Terhubung ke Satu Sehat' : 'Belum terhubung / belum dites' }}</strong>
          <div class="b-meta">
            <span class="badge-env">{{ (conn.env || 'production').toUpperCase() }}</span>
            <span v-if="conn.organization_id" class="b-chip">Org <code>{{ conn.organization_id }}</code></span>
            <span v-if="conn.last_tested_at" class="b-chip">
              Test {{ conn.last_test_status }} &middot; {{ new Date(conn.last_tested_at).toLocaleString('id-ID') }}
            </span>
          </div>
        </div>
      </div>
      <div class="b-actions">
        <button class="btn ghost" :disabled="loading" @click="load" title="Muat ulang data">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :class="{ spin: loading }">
            <path d="M23 4v6h-6M1 20v-6h6" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
          </svg>
          Refresh
        </button>
        <button class="btn ghost" @click="openBackfill" title="Sync kunjungan historis (puluhan ribu pasien)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" /><path d="M3 3v5h5" /><circle cx="12" cy="12" r="1" />
          </svg>
          Backfill
        </button>
        <button class="btn primary" :disabled="syncing" @click="syncManual">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :class="{ spin: syncing }">
            <path d="M21 12a9 9 0 1 1-9-9" /><path d="M21 3v6h-6" />
          </svg>
          {{ syncing ? 'Menyinkron…' : 'Sync Manual' }}
        </button>
      </div>
    </section>

    <div v-if="loading" class="skeleton-wrap">
      <div v-for="n in 4" :key="n" class="sk-card" />
    </div>

    <template v-else>
      <!-- Date range -->
      <div class="range">
        <svg class="range-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" />
        </svg>
        <label>Dari <input type="date" v-model="range.from" @change="load" /></label>
        <label>Sampai <input type="date" v-model="range.to" @change="load" /></label>
        <span class="muted">Angka kartu mengikuti rentang ini.</span>
      </div>

      <!-- 4 stat-card -->
      <div class="cards">
        <div v-for="c in cards" :key="c.key" class="card" :class="'tone-' + c.tone">
          <div class="card-top">
            <span class="card-ic">
              <svg v-if="c.icon === 'visit'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
              <svg v-else-if="c.icon === 'diag'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2" /></svg>
              <svg v-else-if="c.icon === 'rx'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="9" y1="13" x2="15" y2="13" /><line x1="9" y1="17" x2="13" y2="17" /></svg>
              <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.5 20.5 3.5 13.5a5 5 0 0 1 7-7l7 7a5 5 0 0 1-7 7Z" /><line x1="8.5" y1="8.5" x2="15.5" y2="15.5" /></svg>
            </span>
            <span v-if="c.rate !== null" class="card-rate" :class="c.rate >= 90 ? 'r-good' : c.rate >= 60 ? 'r-mid' : 'r-low'">{{ c.rate }}%</span>
          </div>
          <div class="c-num">{{ c.success }}</div>
          <div class="c-label">{{ c.label }}</div>
          <div class="c-sub">
            <span class="ok">&#10003; {{ c.success }} terkirim</span>
            <span v-if="c.failed" class="fail">&#10007; {{ c.failed }} gagal</span>
          </div>
        </div>
      </div>

      <div class="row2">
        <!-- Ringkas kunjungan -->
        <section class="panel">
          <h3>Kunjungan (rentang)</h3>
          <ul class="kv">
            <li><span><i class="dot d-total" />Total SELESAI</span><b>{{ visits.total || 0 }}</b></li>
            <li><span><i class="dot d-ok" />Sudah terkirim</span><b class="ok">{{ visits.synced || 0 }}</b></li>
            <li><span><i class="dot d-warn" />Belum terkirim</span><b class="warn">{{ visits.pending || 0 }}</b></li>
            <li><span><i class="dot d-fail" />Gagal</span><b class="fail">{{ visits.failed || 0 }}</b></li>
          </ul>
        </section>

        <!-- Kesiapan data -->
        <section class="panel">
          <h3>Kesiapan Data</h3>
          <div v-if="!readinessWarnings.length" class="ready-ok">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" /></svg>
            <span>Semua data siap dikirim.</span>
          </div>
          <ul v-else class="warn-list">
            <li v-for="(w, i) in readinessWarnings" :key="i">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
              <span>{{ w.txt }}</span>
              <button class="link link-btn" @click="openReady(w.kind)">{{ w.label }}</button>
            </li>
          </ul>
        </section>
      </div>

      <!-- Tren 7 hari -->
      <section class="panel">
        <div class="panel-head">
          <h3>Tren Sync 7 Hari</h3>
          <div class="legend">
            <span><i class="dot d-ok" />Sukses</span>
            <span><i class="dot d-fail" />Gagal</span>
          </div>
        </div>
        <div class="trend">
          <div v-for="(t, i) in trend" :key="i" class="t-col">
            <div class="t-bars">
              <div class="t-bar ok" :style="{ height: ((t.success || 0) / maxTrend * 84) + 'px' }" :title="(t.success || 0) + ' sukses'" />
              <div class="t-bar fail" :style="{ height: ((t.failed || 0) / maxTrend * 84) + 'px' }" :title="(t.failed || 0) + ' gagal'" />
            </div>
            <div class="t-date">{{ (t.date || '').slice(5) }}</div>
          </div>
        </div>
      </section>

      <!-- Riwayat batch (paginasi server — tabel tidak memanjang ke bawah) -->
      <section class="panel">
        <div class="panel-head">
          <h3>Riwayat Batch Sync</h3>
          <span v-if="bt.total" class="muted">{{ bt.total.toLocaleString('id-ID') }} batch</span>
        </div>
        <div v-if="!bt.rows.length && !bt.loading" class="empty">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9" /><path d="M21 3v6h-6" /></svg>
          <p>Belum ada batch sync. Jalankan <b>Sync Manual</b> untuk mulai.</p>
        </div>
        <div v-else class="tbl-wrap" :class="{ 'tbl-loading': bt.loading }">
          <table class="tbl">
            <thead><tr><th>Tanggal</th><th>Tipe</th><th>Status</th><th class="num">Terkirim</th><th class="num">Gagal</th><th class="num">Retry</th><th>Catatan</th><th></th></tr></thead>
            <tbody>
              <tr v-for="b in bt.rows" :key="b.id">
                <td>{{ (b.sync_date || '').slice(0, 10) }}</td>
                <td><span class="type-chip">{{ b.sync_type }}</span></td>
                <td><span class="st" :class="statusClass(b.status)">{{ b.status }}</span></td>
                <td class="num"><b class="ok">{{ b.total_sent }}</b></td>
                <td class="num"><b :class="{ fail: b.total_failed }">{{ b.total_failed }}</b></td>
                <td class="num">{{ b.retry_count }}</td>
                <td class="t-note">{{ b.notes || '—' }}</td>
                <td>
                  <button v-if="b.status === 'PARTIAL' || b.status === 'FAILED'" class="btn sm" :disabled="retrying === b.id" @click="retry(b.id)">
                    {{ retrying === b.id ? '…' : 'Retry' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="bt.last_page > 1" class="pager">
            <button class="btn sm" :disabled="bt.page <= 1 || bt.loading" @click="loadBatches(bt.page - 1)">‹ Sebelumnya</button>
            <span class="muted">Hal {{ bt.page }} / {{ bt.last_page }}</span>
            <button class="btn sm" :disabled="bt.page >= bt.last_page || bt.loading" @click="loadBatches(bt.page + 1)">Berikutnya ›</button>
          </div>
        </div>
      </section>
    </template>

    <!-- Modal Backfill -->
    <Teleport to="body">
      <div v-if="backfill.open" class="bf-overlay" @click.self="backfill.open = false">
        <div class="bf-box">
          <div class="bf-head">
            <div>
              <h3>Backfill Kunjungan Historis</h3>
              <p>Kirim kunjungan lama yang belum tersinkron ke Satu Sehat.</p>
            </div>
            <button class="bf-close" @click="backfill.open = false">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
            </button>
          </div>

          <div class="bf-body">
            <div class="bf-note">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" /></svg>
              <span>Sesuai regulasi, hanya kunjungan <b>SELESAI</b> dengan <b>NIK pasien</b>, <b>NIK dokter</b>, dan <b>diagnosis</b> yang dikirim (IHS di-resolve otomatis). Resep ikut bila ber-KFA.</span>
            </div>

            <!-- Rentang opsional -->
            <div class="bf-range">
              <label>Dari <input type="date" v-model="backfill.from" @change="checkBackfill" /></label>
              <label>Sampai <input type="date" v-model="backfill.to" @change="checkBackfill" /></label>
              <span class="muted">Kosongkan = seluruh histori.</span>
            </div>

            <!-- Hasil cek -->
            <div class="bf-preview">
              <div v-if="backfill.checking" class="bf-checking">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="spin"><path d="M21 12a9 9 0 1 1-6.219-8.56" /></svg>
                Menghitung…
              </div>
              <template v-else-if="backfill.preview">
                <div class="bf-stats">
                  <div class="bf-stat big">
                    <span class="bf-num">{{ backfill.preview.eligible.toLocaleString('id-ID') }}</span>
                    <span class="bf-lbl">Eligible (siap dikirim)</span>
                  </div>
                  <div class="bf-stat">
                    <span class="bf-num muted-num">{{ (backfill.preview.skipped_no_patient_nik || 0).toLocaleString('id-ID') }}</span>
                    <span class="bf-lbl">Tanpa NIK pasien</span>
                  </div>
                  <div class="bf-stat">
                    <span class="bf-num muted-num">{{ (backfill.preview.skipped_no_diag_or_doctor || 0).toLocaleString('id-ID') }}</span>
                    <span class="bf-lbl">Tanpa diagnosis / NIK dokter</span>
                  </div>
                </div>

                <!-- Set jumlah -->
                <div class="bf-limit">
                  <label>Proses sebanyak</label>
                  <input type="number" v-model.number="backfill.limit" min="1" max="5000" />
                  <span class="muted">kunjungan per jalan (maks 5000). Sisanya dijalankan ulang.</span>
                </div>
                <div class="bf-quick">
                  <button v-for="n in [100, 500, 1000, 5000]" :key="n" class="chip" :class="{ on: backfill.limit === n }" @click="backfill.limit = n">{{ n.toLocaleString('id-ID') }}</button>
                </div>
              </template>
            </div>
          </div>

          <div class="bf-foot">
            <button class="btn ghost" :disabled="backfill.checking" @click="checkBackfill">Cek Ulang</button>
            <button class="btn primary" :disabled="backfill.running || backfill.checking || !backfill.preview?.eligible" @click="runBackfill">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :class="{ spin: backfill.running }"><path d="M21 12a9 9 0 1 1-9-9" /><path d="M21 3v6h-6" /></svg>
              {{ backfill.running ? 'Memproses…' : `Jalankan ${Math.min(backfill.limit || 0, backfill.preview?.eligible || 0).toLocaleString('id-ID')}` }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Modal Kesiapan Data (Atur dokter NIK / obat KFA / resolve IHS) -->
    <Teleport to="body">
      <div v-if="ready.open" class="bf-overlay" @click.self="ready.open = false">
        <div class="bf-box">
          <div class="bf-head">
            <div>
              <h3 v-if="ready.kind === 'dokter'">Dokter Belum Punya NIK</h3>
              <h3 v-else-if="ready.kind === 'obat'">Obat Belum Punya Kode KFA</h3>
              <h3 v-else>Resolve IHS Pasien Massal</h3>
              <p v-if="ready.kind === 'dokter'">Tanpa NIK, kunjungan dengan dokter ini <b>selalu gagal</b> terkirim ke Satu Sehat.</p>
              <p v-else-if="ready.kind === 'obat'">Obat tanpa KFA tetap aman — hanya baris resepnya yang tidak ikut terkirim.</p>
              <p v-else>Cari IHS pasien ber-NIK ke Kemenkes lalu simpan (otomatis ter-cache, juga di-resolve saat sync).</p>
            </div>
            <button class="bf-close" @click="ready.open = false">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
            </button>
          </div>

          <div class="bf-body">
            <!-- DOKTER: daftar + isi NIK langsung -->
            <template v-if="ready.kind === 'dokter'">
              <div v-if="!(readiness.doctors_without_nik_list || []).length" class="ready-ok">Semua dokter aktif sudah punya NIK. ✓</div>
              <ul v-else class="rd-list">
                <li v-for="emp in readiness.doctors_without_nik_list" :key="emp.id" class="rd-row">
                  <div class="rd-name">
                    <b>{{ emp.name }}</b>
                    <small>{{ emp.profession }}</small>
                  </div>
                  <input v-model="ready.nik[emp.id]" type="text" inputmode="numeric" maxlength="16" placeholder="NIK 16 digit" class="rd-input" />
                  <button class="btn sm primary" :disabled="ready.saving === emp.id" @click="saveNik(emp)">
                    {{ ready.saving === emp.id ? '…' : 'Simpan' }}
                  </button>
                </li>
              </ul>
            </template>

            <!-- OBAT: daftar + arahan ke Master Obat (route benar, bukan redirect request-unit) -->
            <template v-else-if="ready.kind === 'obat'">
              <div class="bf-note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" /></svg>
                <span>Isi kode lewat <RouterLink to="/inventori-farmasi/obat" class="link">Master Obat → tombol "Cari KFA"</RouterLink>, atau jalankan <code>php artisan satusehat:isi-kfa --apply</code> di server untuk isi otomatis massal.</span>
              </div>
              <ul class="rd-list rd-plain">
                <li v-for="m in readiness.medications_without_kfa_list" :key="m.id">{{ m.name }}</li>
              </ul>
              <p v-if="(readiness.medications_without_kfa || 0) > (readiness.medications_without_kfa_list || []).length" class="muted">
                Menampilkan {{ (readiness.medications_without_kfa_list || []).length }} dari {{ readiness.medications_without_kfa }} obat.
              </p>
            </template>

            <!-- IHS: pilih jumlah batch lalu jalankan -->
            <template v-else>
              <div class="bf-stats">
                <div class="bf-stat big">
                  <span class="bf-num">{{ (readiness.patients_resolvable_ihs || 0).toLocaleString('id-ID') }}</span>
                  <span class="bf-lbl">Ber-NIK, siap di-resolve</span>
                </div>
                <div class="bf-stat">
                  <span class="bf-num muted-num">{{ ((readiness.patients_without_ihs || 0) - (readiness.patients_resolvable_ihs || 0)).toLocaleString('id-ID') }}</span>
                  <span class="bf-lbl">Tanpa NIK (tak bisa)</span>
                </div>
              </div>
              <div class="bf-limit">
                <label>Proses sebanyak</label>
                <input type="number" v-model.number="ready.ihsLimit" min="1" max="1000" />
                <span class="muted">pasien per jalan (maks 1000). NIK tak ketemu dipindah ke belakang antrean.</span>
              </div>
              <div class="bf-quick">
                <button v-for="n in [100, 200, 500, 1000]" :key="n" class="chip" :class="{ on: ready.ihsLimit === n }" @click="ready.ihsLimit = n">{{ n.toLocaleString('id-ID') }}</button>
              </div>
              <div v-if="ready.ihsResult" class="bf-note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" /></svg>
                <span>
                  Diproses <b>{{ ready.ihsResult.processed }}</b> · berhasil <b>{{ ready.ihsResult.resolved }}</b> ·
                  NIK tak ditemukan <b>{{ ready.ihsResult.not_found }}</b> ·
                  sisa siap-resolve <b>{{ (ready.ihsResult.remaining_resolvable || 0).toLocaleString('id-ID') }}</b>
                  <b v-if="ready.ihsResult.resolved" class="delta-ok">(−{{ ready.ihsResult.resolved }})</b>
                  <template v-if="ready.ihsResult.error"><br />⚠ Terhenti: {{ ready.ihsResult.error }}</template>
                </span>
              </div>
              <!-- Bukti per-pasien: IHS yang didapat tersimpan di kolom pasien
                   (tampil juga di Profil Pasien Admisi). -->
              <template v-if="ready.ihsResult?.sample?.length">
                <ul class="rd-list rd-sample">
                  <li v-for="(s, i) in ready.ihsResult.sample" :key="i">
                    <span class="rd-s-name">{{ s.name }} <small>{{ s.nik }}</small></span>
                    <code v-if="s.ihs" class="ihs-ok">✓ {{ s.ihs }}</code>
                    <span v-else class="ihs-fail">✗ NIK tak ditemukan</span>
                  </li>
                </ul>
                <p v-if="ready.ihsResult.processed > ready.ihsResult.sample.length" class="muted">
                  Menampilkan {{ ready.ihsResult.sample.length }} pertama dari {{ ready.ihsResult.processed }} yang diproses.
                </p>
              </template>
            </template>
          </div>

          <div class="bf-foot">
            <button class="btn ghost" @click="ready.open = false">Tutup</button>
            <button v-if="ready.kind === 'ihs'" class="btn primary" :disabled="ready.ihsRunning || !(readiness.patients_resolvable_ihs || 0)" @click="runResolveIhs">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :class="{ spin: ready.ihsRunning }"><path d="M21 12a9 9 0 1 1-9-9" /><path d="M21 3v6h-6" /></svg>
              {{ ready.ihsRunning ? 'Memproses…' : `Resolve ${Math.min(ready.ihsLimit || 0, readiness.patients_resolvable_ihs || 0).toLocaleString('id-ID')} Pasien` }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <transition name="fade">
      <div v-if="toast.show" class="toast" :class="toast.ok ? 't-ok' : 't-fail'">{{ toast.msg }}</div>
    </transition>
  </div>
</template>

<style scoped>
/* Palet resmi Prima Vision: navy #0E3A66 + cyan #1FAAE0 */
.ss {
  --navy: #0E3A66; --cyan: #1FAAE0;
  --green: #1E9E63; --amber: #E8930C; --red: #E14942;
  display: flex; flex-direction: column; gap: 1.1rem; position: relative;
}
.muted { color: var(--tm); font-size: 12.5px; }

/* ── BANNER KONEKSI ─────────────────────────────────────────────── */
.banner {
  display: flex; justify-content: space-between; align-items: center; gap: 1rem;
  padding: 1.05rem 1.25rem; border-radius: 16px; border: 1px solid var(--gb);
}
.b-ok  { background: linear-gradient(105deg, #f0fbf6 0%, #ffffff 60%); border-color: #c4ecd8; }
.b-off { background: linear-gradient(105deg, #fff5f4 0%, #ffffff 60%); border-color: #f6d2cf; }
.b-left { display: flex; align-items: center; gap: 0.85rem; min-width: 0; }
.b-ring { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; flex-shrink: 0; }
.b-ring svg { width: 20px; height: 20px; }
.r-ok  { background: rgba(30,158,99,.12); color: var(--green); }
.r-off { background: rgba(225,73,66,.12); color: var(--red); }
.b-body { min-width: 0; }
.b-body strong { color: var(--td); font-size: 15px; display: block; }
.b-meta { display: flex; gap: 0.5rem; flex-wrap: wrap; font-size: 11.5px; color: var(--tm); margin-top: 6px; align-items: center; }
.badge-env { background: var(--navy); color: #fff; padding: 2px 9px; border-radius: 20px; font-weight: 700; font-size: 10px; letter-spacing: .04em; }
.b-chip { background: var(--bs); border: 1px solid var(--gb); padding: 2px 9px; border-radius: 20px; }
.b-chip code { font-family: 'JetBrains Mono', monospace; font-size: 10.5px; color: var(--td); }
.b-actions { display: flex; gap: 0.5rem; flex-shrink: 0; }

/* ── BUTTONS ─────────────────────────────────────────────────────── */
.btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 15px; border: 1px solid var(--gb); border-radius: 10px;
  font-size: 12.5px; font-weight: 600; background: #fff; color: var(--td);
  cursor: pointer; transition: all .15s; white-space: nowrap;
}
.btn svg { width: 15px; height: 15px; }
.btn:hover:not(:disabled) { border-color: var(--cyan); color: var(--navy); }
.btn:disabled { opacity: 0.55; cursor: not-allowed; }
.btn.primary { background: var(--navy); color: #fff; border-color: var(--navy); }
.btn.primary:hover:not(:disabled) { background: #0b2f53; border-color: #0b2f53; color: #fff; }
.btn.ghost { background: #fff; }
.btn.sm { padding: 5px 12px; font-size: 11.5px; border-radius: 8px; }
.spin { animation: spin .9s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── DATE RANGE ──────────────────────────────────────────────────── */
.range {
  display: flex; gap: 0.85rem; align-items: center; flex-wrap: wrap;
  font-size: 12.5px; color: var(--tm);
  background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 0.6rem 0.9rem;
}
.range-ic { width: 16px; height: 16px; color: var(--navy); }
.range label { display: inline-flex; align-items: center; gap: 6px; color: var(--td); font-weight: 500; }
.range input { padding: 6px 9px; border: 1px solid var(--gb); border-radius: 8px; color: var(--td); background: #fff; font-size: 12.5px; }
.range input:focus { outline: none; border-color: var(--cyan); }

/* ── STAT CARDS ──────────────────────────────────────────────────── */
.cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.9rem; }
@media (max-width: 900px) { .cards { grid-template-columns: repeat(2, 1fr); } }
.card {
  background: var(--bc); border: 1px solid var(--gb); border-radius: 16px;
  padding: 1.1rem 1.15rem; position: relative; overflow: hidden;
  transition: box-shadow .18s, transform .18s, border-color .18s;
}
.card:hover { box-shadow: 0 10px 28px rgba(14,58,102,.08); transform: translateY(-2px); border-color: #cdd9e6; }
.card-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.85rem; }
.card-ic { width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center; }
.card-ic svg { width: 19px; height: 19px; }
.tone-navy  .card-ic { background: rgba(14,58,102,.10);  color: var(--navy); }
.tone-cyan  .card-ic { background: rgba(31,170,224,.12); color: var(--cyan); }
.tone-green .card-ic { background: rgba(30,158,99,.12);  color: var(--green); }
.tone-amber .card-ic { background: rgba(232,147,12,.12); color: var(--amber); }
.card-rate { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 20px; }
.r-good { background: rgba(30,158,99,.12); color: var(--green); }
.r-mid  { background: rgba(232,147,12,.12); color: var(--amber); }
.r-low  { background: rgba(225,73,66,.12); color: var(--red); }
.c-num { font-size: 30px; font-weight: 800; color: var(--td); line-height: 1; letter-spacing: -0.02em; }
.c-label { font-size: 12.5px; color: var(--tm); font-weight: 600; margin: 5px 0 9px; }
.c-sub { display: flex; gap: 0.7rem; font-size: 11.5px; flex-wrap: wrap; }
.c-sub .ok { color: var(--green); font-weight: 600; } .c-sub .fail { color: var(--red); font-weight: 600; }

/* ── PANELS ──────────────────────────────────────────────────────── */
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem; }
@media (max-width: 800px) { .row2 { grid-template-columns: 1fr; } }
.panel { background: var(--bc); border: 1px solid var(--gb); border-radius: 16px; padding: 1.1rem 1.2rem; }
.panel h3 { margin: 0 0 0.85rem; font-size: 14px; color: var(--td); font-weight: 700; }
.panel-head { display: flex; align-items: center; justify-content: space-between; }
.panel-head h3 { margin: 0; }
.legend { display: flex; gap: 0.9rem; font-size: 11.5px; color: var(--tm); }
.legend span, .kv span { display: inline-flex; align-items: center; gap: 6px; }
.dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.d-total { background: var(--navy); } .d-ok { background: var(--green); }
.d-warn { background: var(--amber); } .d-fail { background: var(--red); }

.kv { list-style: none; margin: 0; padding: 0; }
.kv li { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--bs); font-size: 13px; color: var(--td); }
.kv li:last-child { border-bottom: none; }
.kv b { font-weight: 700; }
.kv b.ok { color: var(--green); } .kv b.warn { color: var(--amber); } .kv b.fail { color: var(--red); }

.ready-ok { display: flex; align-items: center; gap: 9px; color: var(--green); font-size: 13px; font-weight: 500; padding: 6px 0; }
.ready-ok svg { width: 20px; height: 20px; flex-shrink: 0; }
.warn-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.warn-list li { display: flex; align-items: center; gap: 9px; font-size: 12.5px; color: var(--td); background: #fff8ee; border: 1px solid #f6e2c0; border-radius: 10px; padding: 9px 11px; }
.warn-list svg { width: 16px; height: 16px; color: var(--amber); flex-shrink: 0; }
.warn-list span { flex: 1; }
.link { color: var(--cyan); font-weight: 700; white-space: nowrap; text-decoration: none; }
.link:hover { color: var(--navy); }
.link-btn { background: none; border: none; padding: 0; font-size: inherit; font-family: inherit; cursor: pointer; }

/* ── PAGER (Riwayat Batch Sync) ──────────────────────────────────── */
.pager { display: flex; align-items: center; justify-content: center; gap: 0.9rem; padding: 0.8rem 0 0.2rem; }
.tbl-loading { opacity: 0.55; pointer-events: none; }

/* ── MODAL KESIAPAN DATA ─────────────────────────────────────────── */
.rd-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; max-height: 46vh; overflow-y: auto; }
.rd-row { display: flex; align-items: center; gap: 0.7rem; background: #F6F8FA; border: 1px solid #DEE4EB; border-radius: 10px; padding: 9px 11px; }
.rd-name { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.rd-name b { font-size: 13px; color: #081F38; }
.rd-name small { font-size: 11px; color: #4C5A6B; }
.rd-input { width: 170px; padding: 7px 10px; border: 1.5px solid #DEE4EB; border-radius: 8px; font-size: 13px; font-family: 'JetBrains Mono', monospace; color: #081F38; }
.rd-input:focus { outline: none; border-color: #1FAAE0; }
.rd-plain li { font-size: 12.5px; color: #081F38; padding: 6px 10px; background: #F6F8FA; border: 1px solid #DEE4EB; border-radius: 8px; }
.rd-sample { max-height: 32vh; }
.rd-sample li { display: flex; align-items: center; justify-content: space-between; gap: 0.7rem; font-size: 12.5px; padding: 6px 10px; background: #F6F8FA; border: 1px solid #DEE4EB; border-radius: 8px; }
.rd-s-name { color: #081F38; font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rd-s-name small { color: #4C5A6B; font-weight: 400; font-family: 'JetBrains Mono', monospace; font-size: 10.5px; margin-left: 6px; }
.ihs-ok { color: #1E9E63; font-weight: 700; font-family: 'JetBrains Mono', monospace; font-size: 11.5px; white-space: nowrap; }
.ihs-fail { color: #E14942; font-weight: 600; font-size: 11.5px; white-space: nowrap; }
.delta-ok { color: #1E9E63; }
.bf-note code { font-family: 'JetBrains Mono', monospace; font-size: 11px; background: #fff; border: 1px solid #DEE4EB; border-radius: 5px; padding: 1px 5px; }

/* ── TREND ───────────────────────────────────────────────────────── */
.trend { display: flex; gap: 0.6rem; align-items: flex-end; height: 110px; padding-top: 6px; }
.t-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; }
.t-bars { display: flex; gap: 3px; align-items: flex-end; height: 86px; }
.t-bar { width: 10px; border-radius: 3px 3px 0 0; min-height: 3px; transition: height .3s ease; }
.t-bar.ok { background: var(--green); } .t-bar.fail { background: var(--red); }
.t-date { font-size: 10px; color: var(--tm); font-variant-numeric: tabular-nums; }

/* ── TABLE ───────────────────────────────────────────────────────── */
.tbl-wrap { overflow-x: auto; margin: 0 -0.3rem; }
.tbl { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.tbl th { text-align: left; padding: 8px 10px; background: var(--bs); color: var(--tm); font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; font-weight: 700; }
.tbl th:first-child { border-radius: 8px 0 0 8px; } .tbl th:last-child { border-radius: 0 8px 8px 0; }
.tbl th.num, .tbl td.num { text-align: right; }
.tbl td { padding: 9px 10px; border-bottom: 1px solid var(--bs); color: var(--td); }
.tbl tbody tr:last-child td { border-bottom: none; }
.tbl tbody tr:hover { background: var(--bs); }
.tbl td.num b.ok { color: var(--green); } .tbl td.num b.fail { color: var(--red); }
.type-chip { background: var(--bs); border: 1px solid var(--gb); padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; color: var(--td); }
.t-note { max-width: 340px; font-size: 11px; color: var(--amber); line-height: 1.4; }
.st { padding: 3px 10px; border-radius: 20px; font-size: 10.5px; font-weight: 700; }
.s-ok { background: rgba(30,158,99,.12); color: var(--green); } .s-warn { background: rgba(232,147,12,.14); color: var(--amber); }
.s-fail { background: rgba(225,73,66,.12); color: var(--red); } .s-run { background: rgba(31,170,224,.14); color: var(--cyan); } .s-idle { background: var(--bs); color: var(--tm); }

/* ── EMPTY / SKELETON ────────────────────────────────────────────── */
.empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 2rem 1rem; color: var(--tm); text-align: center; }
.empty svg { width: 34px; height: 34px; color: #c3ccd6; }
.empty p { margin: 0; font-size: 13px; }
.skeleton-wrap { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.9rem; }
@media (max-width: 900px) { .skeleton-wrap { grid-template-columns: repeat(2, 1fr); } }
.sk-card { height: 132px; border-radius: 16px; background: linear-gradient(100deg, var(--bs) 30%, #eef2f6 50%, var(--bs) 70%); background-size: 200% 100%; animation: shimmer 1.3s infinite; }
@keyframes shimmer { to { background-position: -200% 0; } }

/* ── TOAST ───────────────────────────────────────────────────────── */
.toast { position: fixed; bottom: 24px; right: 24px; padding: 11px 18px; border-radius: 12px; font-size: 13px; font-weight: 600; color: #fff; box-shadow: 0 10px 30px rgba(15,23,42,0.22); z-index: 100; max-width: 480px; }
.t-ok { background: var(--green); } .t-fail { background: var(--red); }
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s, transform 0.25s; }
.fade-enter-from, .fade-leave-to { opacity: 0; transform: translateY(8px); }

/* ── BACKFILL MODAL ──────────────────────────────────────────────── */
/* Modal di-Teleport ke <body> → DI LUAR .ss, jadi var palet (--navy dst.) tidak
   ter-resolve → tombol .btn.primary kehilangan background (teks putih di atas
   footer abu = "tombol hantu"). Definisikan ulang palet di overlay. */
.bf-overlay {
  --navy: #0E3A66; --cyan: #1FAAE0;
  --green: #1E9E63; --amber: #E8930C; --red: #E14942;
  position: fixed; inset: 0; background: rgba(8,31,56,.5); backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; z-index: 1200; padding: 1rem;
}
.bf-box { background: #fff; border-radius: 18px; width: min(560px, 96vw); max-height: 92vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 24px 60px rgba(8,31,56,.28); }
.bf-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; padding: 1.25rem 1.4rem 1rem; border-bottom: 1px solid #DEE4EB; }
.bf-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: #081F38; }
.bf-head p { margin: 4px 0 0; font-size: 12.5px; color: #4C5A6B; }
.bf-close { width: 32px; height: 32px; border-radius: 9px; border: 1px solid #DEE4EB; background: #fff; display: grid; place-items: center; cursor: pointer; color: #4C5A6B; flex-shrink: 0; transition: all .15s; }
.bf-close svg { width: 15px; height: 15px; }
.bf-close:hover { background: #fdeceb; color: #E14942; border-color: #f6d2cf; }
.bf-body { padding: 1.2rem 1.4rem; overflow-y: auto; display: flex; flex-direction: column; gap: 1.1rem; }

.bf-note { display: flex; gap: 10px; background: #eef7fc; border: 1px solid #c5e8f6; border-radius: 12px; padding: 11px 13px; font-size: 12.5px; color: #081F38; line-height: 1.5; }
.bf-note svg { width: 17px; height: 17px; color: #1FAAE0; flex-shrink: 0; margin-top: 1px; }
.bf-note b { color: #0E3A66; }

.bf-range { display: flex; gap: 0.9rem; align-items: center; flex-wrap: wrap; font-size: 12.5px; color: #4C5A6B; }
.bf-range label { display: inline-flex; align-items: center; gap: 6px; color: #081F38; font-weight: 500; }
.bf-range input { padding: 6px 9px; border: 1px solid #DEE4EB; border-radius: 8px; color: #081F38; font-size: 12.5px; }
.bf-range input:focus { outline: none; border-color: #1FAAE0; }

.bf-checking { display: flex; align-items: center; gap: 9px; color: #4C5A6B; font-size: 13px; padding: 1rem 0; }
.bf-checking svg { width: 17px; height: 17px; }
.bf-stats { display: grid; grid-template-columns: 1.3fr 1fr 1fr; gap: 0.7rem; }
.bf-stat { background: #F6F8FA; border: 1px solid #DEE4EB; border-radius: 12px; padding: 0.85rem 0.9rem; display: flex; flex-direction: column; gap: 3px; }
.bf-stat.big { background: linear-gradient(120deg, #0E3A66, #145089); border-color: #0E3A66; }
.bf-num { font-size: 24px; font-weight: 800; color: #081F38; line-height: 1; letter-spacing: -0.02em; }
.bf-stat.big .bf-num { color: #fff; }
.bf-num.muted-num { color: #4C5A6B; }
.bf-lbl { font-size: 11px; font-weight: 600; color: #4C5A6B; }
.bf-stat.big .bf-lbl { color: #cfe0f0; }

.bf-limit { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; font-size: 12.5px; color: #4C5A6B; }
.bf-limit label { font-weight: 600; color: #081F38; }
.bf-limit input { width: 110px; padding: 7px 10px; border: 1.5px solid #DEE4EB; border-radius: 8px; font-size: 14px; font-weight: 700; color: #081F38; }
.bf-limit input:focus { outline: none; border-color: #1FAAE0; }
.bf-quick { display: flex; gap: 6px; flex-wrap: wrap; }
.chip { padding: 5px 12px; border: 1px solid #DEE4EB; border-radius: 20px; background: #fff; font-size: 12px; font-weight: 600; color: #4C5A6B; cursor: pointer; transition: all .15s; }
.chip:hover { border-color: #1FAAE0; color: #0E3A66; }
.chip.on { background: #0E3A66; border-color: #0E3A66; color: #fff; }

.bf-foot { display: flex; justify-content: flex-end; gap: 0.6rem; padding: 1rem 1.4rem; border-top: 1px solid #DEE4EB; background: #F6F8FA; }
</style>
