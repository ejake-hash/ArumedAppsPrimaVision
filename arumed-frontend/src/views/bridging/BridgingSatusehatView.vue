<script setup>
/**
 * BridgingSatusehatView — dashboard monitoring Satu Sehat (Fase 6).
 * 4 stat-card resource + status koneksi + kesiapan data + tren + Sync Manual + batch/Retry.
 * Semua angka dari /integrasi/satusehat/dashboard (baca log lokal). Sync Manual & Retry live.
 */
import { ref, reactive, computed, onMounted } from 'vue'
import { integrasiApi } from '@/services/api'

const loading = ref(true)
const syncing = ref(false)
const retrying = ref(null)
const data = ref(null)
const today = new Date().toISOString().slice(0, 10)
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
    flash(true, `Sync selesai: ${l?.status ?? ''} (terkirim ${l?.total_sent ?? 0}, gagal ${l?.total_failed ?? 0})`)
    await load()
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
    await load()
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Retry gagal')
  } finally {
    retrying.value = null
  }
}

const conn = computed(() => data.value?.connection ?? {})
const connOk = computed(() => conn.value.is_enabled && conn.value.last_test_status === 'SUCCESS')
const cards = computed(() => {
  const c = data.value?.cards ?? {}
  return [
    { key: 'Encounter',          label: 'Kunjungan',   ...(c.Encounter ?? {}) },
    { key: 'Condition',          label: 'Diagnosis',   ...(c.Condition ?? {}) },
    { key: 'MedicationRequest',  label: 'Peresepan',   ...(c.MedicationRequest ?? {}) },
    { key: 'MedicationDispense', label: 'Obat Pulang', ...(c.MedicationDispense ?? {}) },
  ]
})
const visits = computed(() => data.value?.visits ?? {})
const readiness = computed(() => data.value?.readiness ?? {})
const trend = computed(() => data.value?.trend ?? [])
const batches = computed(() => data.value?.batches ?? [])
const maxTrend = computed(() => Math.max(1, ...trend.value.map((t) => (t.success || 0) + (t.failed || 0))))

const readinessWarnings = computed(() => {
  const r = readiness.value
  const w = []
  if (r.doctors_without_nik) w.push({ txt: `${r.doctors_without_nik} dokter belum punya NIK`, link: '/data-pengguna' })
  if (r.medications_without_kfa) w.push({ txt: `${r.medications_without_kfa} obat belum punya kode KFA`, link: '/inventori-farmasi' })
  if (r.patients_without_ihs) w.push({ txt: `${r.patients_without_ihs} pasien belum punya IHS (akan resolve saat sync)`, link: null })
  return w
})

const statusClass = (s) => ({ SUCCESS: 's-ok', PARTIAL: 's-warn', FAILED: 's-fail', RUNNING: 's-run' }[s] || 's-idle')

onMounted(load)
</script>

<template>
  <div class="ss">
    <!-- Banner koneksi -->
    <section class="banner" :class="connOk ? 'b-ok' : 'b-off'">
      <div class="b-left">
        <span class="b-dot" />
        <div>
          <strong>{{ connOk ? 'Terhubung ke Satu Sehat' : 'Belum terhubung / belum dites' }}</strong>
          <div class="b-meta">
            <span class="badge-env">{{ (conn.env || 'sandbox').toUpperCase() }}</span>
            <span v-if="conn.organization_id">Org: <code>{{ conn.organization_id }}</code></span>
            <span v-if="conn.last_tested_at">Test terakhir: {{ conn.last_test_status }} &middot; {{ new Date(conn.last_tested_at).toLocaleString('id-ID') }}</span>
          </div>
        </div>
      </div>
      <button class="btn primary" :disabled="syncing" @click="syncManual">{{ syncing ? 'Menyinkron…' : 'Sync Manual' }}</button>
    </section>

    <p v-if="loading" class="muted">Memuat…</p>

    <template v-else>
      <!-- Date range -->
      <div class="range">
        <label>Dari <input type="date" v-model="range.from" @change="load" /></label>
        <label>Sampai <input type="date" v-model="range.to" @change="load" /></label>
        <span class="muted">Angka kartu mengikuti rentang ini.</span>
      </div>

      <!-- 4 stat-card -->
      <div class="cards">
        <div v-for="c in cards" :key="c.key" class="card">
          <div class="c-label">{{ c.label }}</div>
          <div class="c-num">{{ c.success || 0 }}</div>
          <div class="c-sub">
            <span class="ok">&#10003; {{ c.success || 0 }} terkirim</span>
            <span v-if="c.failed" class="fail">&#10007; {{ c.failed }} gagal</span>
          </div>
        </div>
      </div>

      <div class="row2">
        <!-- Ringkas kunjungan -->
        <section class="panel">
          <h3>Kunjungan (rentang)</h3>
          <ul class="kv">
            <li><span>Total SELESAI</span><b>{{ visits.total || 0 }}</b></li>
            <li><span>Sudah terkirim</span><b class="ok">{{ visits.synced || 0 }}</b></li>
            <li><span>Belum terkirim</span><b class="warn">{{ visits.pending || 0 }}</b></li>
            <li><span>Gagal</span><b class="fail">{{ visits.failed || 0 }}</b></li>
          </ul>
        </section>

        <!-- Kesiapan data -->
        <section class="panel">
          <h3>Kesiapan Data</h3>
          <p v-if="!readinessWarnings.length" class="ok-note">&#10003; Semua data siap dikirim.</p>
          <ul v-else class="warn-list">
            <li v-for="(w, i) in readinessWarnings" :key="i">
              &#9888; {{ w.txt }}
              <RouterLink v-if="w.link" :to="w.link" class="link">Atur</RouterLink>
            </li>
          </ul>
        </section>
      </div>

      <!-- Tren 7 hari -->
      <section class="panel">
        <h3>Tren Sync 7 Hari</h3>
        <div class="trend">
          <div v-for="(t, i) in trend" :key="i" class="t-col">
            <div class="t-bars">
              <div class="t-bar ok" :style="{ height: ((t.success || 0) / maxTrend * 70) + 'px' }" :title="t.success + ' sukses'" />
              <div class="t-bar fail" :style="{ height: ((t.failed || 0) / maxTrend * 70) + 'px' }" :title="t.failed + ' gagal'" />
            </div>
            <div class="t-date">{{ t.date.slice(5) }}</div>
          </div>
        </div>
      </section>

      <!-- Riwayat batch -->
      <section class="panel">
        <h3>Riwayat Batch Sync</h3>
        <p v-if="!batches.length" class="muted">Belum ada batch sync.</p>
        <table v-else class="tbl">
          <thead><tr><th>Tanggal</th><th>Tipe</th><th>Status</th><th>Terkirim</th><th>Gagal</th><th>Retry</th><th></th></tr></thead>
          <tbody>
            <tr v-for="b in batches" :key="b.id">
              <td>{{ b.sync_date }}</td>
              <td>{{ b.sync_type }}</td>
              <td><span class="st" :class="statusClass(b.status)">{{ b.status }}</span></td>
              <td>{{ b.total_sent }}</td>
              <td>{{ b.total_failed }}</td>
              <td>{{ b.retry_count }}</td>
              <td>
                <button v-if="b.status === 'PARTIAL' || b.status === 'FAILED'" class="btn sm" :disabled="retrying === b.id" @click="retry(b.id)">
                  {{ retrying === b.id ? '…' : 'Retry' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>

    <transition name="fade">
      <div v-if="toast.show" class="toast" :class="toast.ok ? 't-ok' : 't-fail'">{{ toast.msg }}</div>
    </transition>
  </div>
</template>

<style scoped>
.ss { display: flex; flex-direction: column; gap: 1.1rem; position: relative; }
.muted { color: var(--tm); font-size: 13px; }

.banner { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 0.9rem 1.1rem; border-radius: 12px; border: 1px solid var(--gb); }
.b-ok  { background: #f0fdf4; border-color: #bbf7d0; }
.b-off { background: #fef2f2; border-color: #fecaca; }
.b-left { display: flex; align-items: center; gap: 0.7rem; }
.b-dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; }
.b-ok .b-dot { background: #16a34a; } .b-off .b-dot { background: #dc2626; }
.b-left strong { color: #000; font-size: 14px; }
.b-meta { display: flex; gap: 0.8rem; flex-wrap: wrap; font-size: 11.5px; color: var(--tm); margin-top: 3px; }
.badge-env { background: #1763d4; color: #fff; padding: 1px 7px; border-radius: 10px; font-weight: 600; font-size: 10px; }

.range { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; font-size: 12.5px; color: var(--tm); }
.range input { padding: 5px 8px; border: 1px solid var(--gb); border-radius: 6px; color: #000; margin-left: 4px; }

.cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.9rem; }
@media (max-width: 800px) { .cards { grid-template-columns: repeat(2, 1fr); } }
.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 0.9rem 1rem; }
.c-label { font-size: 12px; color: var(--tm); font-weight: 600; }
.c-num { font-size: 32px; font-weight: 700; color: #000; line-height: 1.1; margin: 4px 0; }
.c-sub { display: flex; gap: 0.7rem; font-size: 11.5px; }
.c-sub .ok { color: #166534; } .c-sub .fail { color: #991b1b; }

.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem; }
@media (max-width: 800px) { .row2 { grid-template-columns: 1fr; } }
.panel { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 0.9rem 1.1rem; }
.panel h3 { margin: 0 0 0.7rem; font-size: 14px; color: var(--td); }
.kv { list-style: none; margin: 0; padding: 0; }
.kv li { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid var(--bs); font-size: 13px; color: #000; }
.kv b.ok { color: #166534; } .kv b.warn { color: #92400e; } .kv b.fail { color: #991b1b; }
.ok-note { color: #166534; font-size: 13px; margin: 0; }
.warn-list { list-style: none; margin: 0; padding: 0; }
.warn-list li { font-size: 12.5px; color: #92400e; padding: 4px 0; }
.link { color: #1763d4; font-weight: 600; margin-left: 6px; }

.trend { display: flex; gap: 0.5rem; align-items: flex-end; height: 96px; padding-top: 4px; }
.t-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px; }
.t-bars { display: flex; gap: 2px; align-items: flex-end; height: 72px; }
.t-bar { width: 9px; border-radius: 2px 2px 0 0; min-height: 2px; }
.t-bar.ok { background: #16a34a; } .t-bar.fail { background: #dc2626; }
.t-date { font-size: 9.5px; color: var(--tm); }

.tbl { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.tbl th { text-align: left; padding: 6px 8px; background: var(--bs); color: var(--tm); font-size: 10.5px; text-transform: uppercase; }
.tbl td { padding: 6px 8px; border-top: 1px solid var(--gb); color: #000; }
.st { padding: 2px 8px; border-radius: 10px; font-size: 10.5px; font-weight: 600; }
.s-ok { background: #dcfce7; color: #166534; } .s-warn { background: #fef3c7; color: #92400e; }
.s-fail { background: #fee2e2; color: #991b1b; } .s-run { background: #e0e7ff; color: #3730a3; } .s-idle { background: #f1f5f9; color: #64748b; }

.btn { padding: 7px 13px; border: 1px solid var(--gb); border-radius: 7px; font-size: 12.5px; font-weight: 600; background: #fff; color: #000; cursor: pointer; }
.btn:disabled { opacity: 0.55; cursor: not-allowed; }
.btn.primary { background: #1763d4; color: #fff; border-color: #1763d4; }
.btn.sm { padding: 4px 10px; font-size: 11.5px; }

.toast { position: fixed; bottom: 24px; right: 24px; padding: 10px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; color: #fff; box-shadow: 0 8px 24px rgba(15,23,42,0.18); z-index: 100; }
.t-ok { background: #166534; } .t-fail { background: #991b1b; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
