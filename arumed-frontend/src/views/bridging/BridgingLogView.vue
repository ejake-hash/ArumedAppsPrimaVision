<script setup>
/**
 * BridgingLogView — audit log semua call BPJS (VClaim & Antrean).
 * Read-only. Filter sumber + sukses/gagal + tanggal. Klik baris → lihat payload.
 */
import { ref, reactive, onMounted, watch } from 'vue'
import { integrasiApi } from '@/services/api'

const source  = ref('vclaim')           // vclaim | antrean
const filters = reactive({ is_success: '', tanggal: '', action: '' })
const loading = ref(false)
const rows    = ref([])
const meta    = reactive({ current: 1, last: 1, total: 0 })
const expanded = ref(null)

async function load(page = 1) {
  loading.value = true
  expanded.value = null
  try {
    const params = { per_page: 20, page }
    if (filters.action) params.action = filters.action
    if (source.value === 'vclaim') {
      if (filters.is_success !== '') params.is_success = filters.is_success
      if (filters.tanggal) params.tanggal = filters.tanggal
    }
    const res = source.value === 'vclaim'
      ? await integrasiApi.vclaimLog(params)
      : await integrasiApi.antreanLog(params)

    const p = res.data?.data ?? {}
    rows.value     = p.data ?? []
    meta.current   = p.current_page ?? 1
    meta.last      = p.last_page ?? 1
    meta.total     = p.total ?? 0
  } catch (e) {
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

watch(source, () => { filters.action = ''; load(1) })
onMounted(() => load(1))
</script>

<template>
  <div class="log">
    <div class="toolbar">
      <div class="seg">
        <button :class="{ active: source === 'vclaim' }" @click="source = 'vclaim'">VClaim</button>
        <button :class="{ active: source === 'antrean' }" @click="source = 'antrean'">Antrean</button>
      </div>

      <input v-model="filters.action" type="text" placeholder="Filter action…" class="inp" @keyup.enter="load(1)" />
      <template v-if="source === 'vclaim'">
        <select v-model="filters.is_success" class="inp" @change="load(1)">
          <option value="">Semua hasil</option>
          <option value="1">Sukses</option>
          <option value="0">Gagal</option>
        </select>
        <input v-model="filters.tanggal" type="date" class="inp" @change="load(1)" />
      </template>
      <button class="btn" @click="load(1)">Terapkan</button>
    </div>

    <p v-if="loading" class="muted">Memuat…</p>
    <p v-else-if="!rows.length" class="muted">Belum ada log {{ source === 'vclaim' ? 'VClaim' : 'Antrean' }}.</p>

    <table v-else class="tbl">
      <thead>
        <tr>
          <th>Waktu</th>
          <th>Action</th>
          <th v-if="source === 'vclaim'">Pasien</th>
          <th>HTTP</th>
          <th>Hasil</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <template v-for="row in rows" :key="row.id">
          <tr class="rw" @click="expanded = expanded === row.id ? null : row.id">
            <td>{{ fmtTime(row.created_at) }}</td>
            <td><code>{{ row.action }}</code></td>
            <td v-if="source === 'vclaim'">{{ row.visit?.patient?.name ?? '—' }}</td>
            <td>{{ row.http_status ?? '—' }}</td>
            <td><span class="dot" :class="row.is_success ? 'ok' : 'fail'">{{ row.is_success ? 'Sukses' : 'Gagal' }}</span></td>
            <td class="chev">{{ expanded === row.id ? '▾' : '▸' }}</td>
          </tr>
          <tr v-if="expanded === row.id" class="detail">
            <td :colspan="source === 'vclaim' ? 6 : 5">
              <div class="payloads">
                <div>
                  <div class="pl-title">Request</div>
                  <pre>{{ pretty(row.request_payload) }}</pre>
                </div>
                <div>
                  <div class="pl-title">Response</div>
                  <pre>{{ pretty(row.response_payload) }}</pre>
                </div>
              </div>
              <div v-if="row.error_message" class="err">⚠ {{ row.error_message }}</div>
            </td>
          </tr>
        </template>
      </tbody>
    </table>

    <div v-if="meta.last > 1" class="pager">
      <button class="btn" :disabled="meta.current <= 1" @click="load(meta.current - 1)">‹ Sebelumnya</button>
      <span class="muted">Hal {{ meta.current }} / {{ meta.last }} · {{ meta.total }} log</span>
      <button class="btn" :disabled="meta.current >= meta.last" @click="load(meta.current + 1)">Berikutnya ›</button>
    </div>
  </div>
</template>

<style scoped>
.log { display: flex; flex-direction: column; gap: 0.9rem; }
.muted { color: var(--tm); font-size: 13px; }

.toolbar { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
.seg { display: inline-flex; border: 1px solid var(--gb); border-radius: 8px; overflow: hidden; }
.seg button { padding: 7px 14px; font-size: 12.5px; font-weight: 600; border: none; background: #fff; color: var(--tm); cursor: pointer; }
.seg button.active { background: #1763d4; color: #fff; }

.inp { padding: 7px 9px; border: 1px solid var(--gb); border-radius: 7px; font-size: 12.5px; color: #000; background: #fff; }
.btn { padding: 7px 12px; border: 1px solid var(--gb); border-radius: 7px; font-size: 12.5px; font-weight: 600; background: #fff; color: #000; cursor: pointer; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.tbl { width: 100%; border-collapse: collapse; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; font-size: 13px; }
.tbl th { text-align: left; padding: 9px 12px; background: var(--bs); color: var(--tm); font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.tbl td { padding: 9px 12px; border-top: 1px solid var(--gb); color: var(--td); }
.tbl code { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; color: var(--td); }
.rw { cursor: pointer; }
.rw:hover { background: var(--bs); }
.chev { color: var(--tm); width: 24px; }

.dot { font-size: 11.5px; font-weight: 600; padding: 2px 8px; border-radius: 12px; }
.dot.ok { background: #dcfce7; color: #166534; }
.dot.fail { background: #fee2e2; color: #991b1b; }

.detail td { background: #fafbfc; }
.payloads { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
@media (max-width: 800px) { .payloads { grid-template-columns: 1fr; } }
.pl-title { font-size: 11px; font-weight: 600; color: var(--tm); margin-bottom: 3px; text-transform: uppercase; }
.payloads pre { margin: 0; padding: 8px; background: #0f172a; color: #e2e8f0; border-radius: 6px; font-size: 11px; line-height: 1.4; max-height: 280px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
.err { margin-top: 0.5rem; font-size: 12px; color: #991b1b; }

.pager { display: flex; gap: 0.8rem; align-items: center; justify-content: center; }
</style>
