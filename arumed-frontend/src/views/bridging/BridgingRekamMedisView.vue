<script setup>
/**
 * BridgingRekamMedisView — dashboard WS Rekam Medis BPJS (push RM → i-Care).
 * Kartu statistik (terkirim/gagal + kunjungan BPJS belum terkirim) + tabel log
 * pengiriman. Read-only; klik baris → lihat Bundle FHIR & respons BPJS.
 */
import { ref, reactive, onMounted } from 'vue'
import { integrasiApi } from '@/services/api'

const stats   = reactive({ sent_total: 0, failed_total: 0, today_sent: 0, today_failed: 0, visits_sent: 0, visits_unsent: 0 })
const filters = reactive({ status: '', tanggal: '' })
const loading = ref(false)
const rows    = ref([])
const meta    = reactive({ current: 1, last: 1, total: 0 })
const expanded = ref(null)

async function loadStats() {
  try {
    const res = await integrasiApi.rmDashboard()
    Object.assign(stats, res.data?.data ?? {})
  } catch { /* abaikan */ }
}

async function loadLog(page = 1) {
  loading.value = true
  expanded.value = null
  try {
    const params = { per_page: 20, page }
    if (filters.status) params.status = filters.status
    if (filters.tanggal) params.tanggal = filters.tanggal
    const res = await integrasiApi.rmLog(params)
    const p = res.data?.data ?? {}
    rows.value   = p.data ?? []
    meta.current = p.current_page ?? 1
    meta.last    = p.last_page ?? 1
    meta.total   = p.total ?? 0
  } catch {
    rows.value = []
  } finally {
    loading.value = false
  }
}

function fmtTime(t) {
  if (!t) return '—'
  return new Date(t).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}
function pretty(v) {
  if (v == null) return '—'
  try { return JSON.stringify(typeof v === 'string' ? JSON.parse(v) : v, null, 2) }
  catch { return String(v) }
}

onMounted(() => { loadStats(); loadLog(1) })
</script>

<template>
  <div class="rm">
    <!-- KARTU STATISTIK -->
    <div class="cards">
      <div class="card ok">
        <div class="card-v">{{ stats.sent_total }}</div>
        <div class="card-l">RM Terkirim</div>
        <div class="card-s">{{ stats.today_sent }} hari ini</div>
      </div>
      <div class="card fail">
        <div class="card-v">{{ stats.failed_total }}</div>
        <div class="card-l">Gagal Kirim</div>
        <div class="card-s">{{ stats.today_failed }} hari ini</div>
      </div>
      <div class="card">
        <div class="card-v">{{ stats.visits_sent }}</div>
        <div class="card-l">Kunjungan Terkirim</div>
      </div>
      <div class="card warn">
        <div class="card-v">{{ stats.visits_unsent }}</div>
        <div class="card-l">Belum Terkirim</div>
        <div class="card-s">kunjungan BPJS ber-SEP</div>
      </div>
    </div>

    <!-- FILTER -->
    <div class="toolbar">
      <select v-model="filters.status" class="inp" @change="loadLog(1)">
        <option value="">Semua status</option>
        <option value="SUCCESS">Sukses</option>
        <option value="FAILED">Gagal</option>
      </select>
      <input v-model="filters.tanggal" type="date" class="inp" @change="loadLog(1)" />
      <button class="btn" @click="loadLog(1)">Terapkan</button>
      <button class="btn" @click="loadStats(); loadLog(meta.current)">↻ Segarkan</button>
    </div>

    <p v-if="loading" class="muted">Memuat…</p>
    <p v-else-if="!rows.length" class="muted">Belum ada pengiriman rekam medis ke BPJS.</p>

    <table v-else class="tbl">
      <thead>
        <tr><th>Waktu</th><th>No. SEP</th><th>Pasien</th><th>HTTP</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <template v-for="row in rows" :key="row.id">
          <tr class="rw" @click="expanded = expanded === row.id ? null : row.id">
            <td>{{ fmtTime(row.created_at) }}</td>
            <td><code>{{ row.no_sep ?? '—' }}</code></td>
            <td>{{ row.visit?.patient?.name ?? '—' }}</td>
            <td>{{ row.http_status ?? '—' }}</td>
            <td><span class="dot" :class="row.status === 'SUCCESS' ? 'ok' : 'fail'">{{ row.status === 'SUCCESS' ? 'Sukses' : 'Gagal' }}</span></td>
            <td class="chev">{{ expanded === row.id ? '▾' : '▸' }}</td>
          </tr>
          <tr v-if="expanded === row.id" class="detail">
            <td colspan="6">
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

    <div v-if="meta.last > 1" class="pager">
      <button class="btn" :disabled="meta.current <= 1" @click="loadLog(meta.current - 1)">‹ Sebelumnya</button>
      <span class="muted">Hal {{ meta.current }} / {{ meta.last }} · {{ meta.total }} log</span>
      <button class="btn" :disabled="meta.current >= meta.last" @click="loadLog(meta.current + 1)">Berikutnya ›</button>
    </div>
  </div>
</template>

<style scoped>
.rm { display: flex; flex-direction: column; gap: 1rem; }
.muted { color: var(--tm); font-size: 13px; }

.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; }
.card { background: #fff; border: 1px solid var(--gb); border-radius: 10px; padding: 14px 16px; }
.card-v { font-size: 1.7rem; font-weight: 800; color: #0f172a; line-height: 1; }
.card-l { font-size: 12.5px; color: var(--tm); margin-top: 6px; font-weight: 600; }
.card-s { font-size: 11px; color: #94a3b8; margin-top: 2px; }
.card.ok   { border-left: 4px solid #16a34a; }
.card.fail { border-left: 4px solid #dc2626; }
.card.warn { border-left: 4px solid #d97706; }

.toolbar { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
.inp { padding: 7px 9px; border: 1px solid var(--gb); border-radius: 7px; font-size: 12.5px; color: #000; background: #fff; }
.btn { padding: 7px 12px; border: 1px solid var(--gb); border-radius: 7px; font-size: 12.5px; font-weight: 600; background: #fff; color: #000; cursor: pointer; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.tbl { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.tbl th { text-align: left; padding: 8px 10px; border-bottom: 2px solid var(--gb); color: var(--tm); font-weight: 600; }
.tbl td { padding: 8px 10px; border-bottom: 1px solid var(--gl); }
.rw { cursor: pointer; }
.rw:hover { background: #f8fafc; }
.chev { color: #94a3b8; text-align: center; width: 28px; }
.dot { padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.dot.ok { background: #dcfce7; color: #166534; }
.dot.fail { background: #fee2e2; color: #991b1b; }
.detail td { background: #f8fafc; }
.payloads { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.pl-title { font-weight: 700; font-size: 11.5px; color: var(--tm); margin-bottom: 4px; }
.payloads pre { background: #0f172a; color: #e2e8f0; padding: 10px; border-radius: 8px; overflow: auto; max-height: 320px; font-size: 11px; }
.err { color: #991b1b; background: #fee2e2; padding: 8px 10px; border-radius: 7px; margin-bottom: 10px; font-size: 12.5px; }
.pager { display: flex; align-items: center; gap: 0.75rem; }
</style>
