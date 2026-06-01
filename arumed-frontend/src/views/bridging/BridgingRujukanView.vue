<script setup>
/**
 * BridgingRujukanView — daftar rujukan BPJS (VClaim) masuk & keluar.
 * Read-only. Toggle Masuk/Keluar + filter status + pager. Klik baris → detail + payload VClaim.
 * Pola mengikuti BridgingLogView.vue.
 */
import { ref, reactive, onMounted, watch } from 'vue'
import { integrasiApi } from '@/services/api'

const arah    = ref('masuk')            // masuk | keluar
const filters = reactive({ status: '' })
const loading = ref(false)
const rows    = ref([])
const meta    = reactive({ current: 1, last: 1, total: 0 })
const expanded = ref(null)

async function load(page = 1) {
  loading.value = true
  expanded.value = null
  try {
    const params = { per_page: 20, page }
    if (filters.status) params.status = filters.status

    const res = arah.value === 'masuk'
      ? await integrasiApi.rujukanMasuk(params)
      : await integrasiApi.rujukanKeluar(params)

    const p = res.data?.data ?? {}
    rows.value   = p.data ?? []
    meta.current = p.current_page ?? 1
    meta.last    = p.last_page ?? 1
    meta.total   = p.total ?? 0
  } catch (e) {
    rows.value = []
  } finally {
    loading.value = false
  }
}

function fmtDate(t) {
  if (!t) return '—'
  return new Date(t).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

function pretty(v) {
  if (v == null) return '—'
  try { return JSON.stringify(typeof v === 'string' ? JSON.parse(v) : v, null, 2) }
  catch { return String(v) }
}

function statusClass(s) {
  const ok = ['AKTIF', 'ACTIVE', 'BERHASIL', 'SELESAI', 'SUKSES']
  return ok.includes(String(s || '').toUpperCase()) ? 'ok' : 'neu'
}

watch(arah, () => { filters.status = ''; load(1) })
onMounted(() => load(1))
</script>

<template>
  <div class="log">
    <div class="toolbar">
      <div class="seg">
        <button :class="{ active: arah === 'masuk' }" @click="arah = 'masuk'">Rujukan Masuk</button>
        <button :class="{ active: arah === 'keluar' }" @click="arah = 'keluar'">Rujukan Keluar</button>
      </div>

      <input v-model="filters.status" type="text" placeholder="Filter status…" class="inp" @keyup.enter="load(1)" />
      <button class="btn" @click="load(1)">Terapkan</button>
    </div>

    <p v-if="loading" class="muted">Memuat…</p>
    <p v-else-if="!rows.length" class="muted">Belum ada rujukan {{ arah === 'masuk' ? 'masuk' : 'keluar' }}.</p>

    <!-- ── RUJUKAN MASUK ── -->
    <table v-else-if="arah === 'masuk'" class="tbl">
      <thead>
        <tr>
          <th>No. Rujukan</th>
          <th>Pasien</th>
          <th>FKTP Asal</th>
          <th>Diagnosa</th>
          <th>Kunjungan</th>
          <th>Tgl Rujukan</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <template v-for="row in rows" :key="row.id">
          <tr class="rw" @click="expanded = expanded === row.id ? null : row.id">
            <td><code>{{ row.no_rujukan ?? '—' }}</code></td>
            <td>{{ row.visit?.patient?.name ?? '—' }}</td>
            <td>{{ row.fktp_nama ?? row.fktp_kode ?? '—' }}</td>
            <td>{{ row.diagnosa_nama ?? row.diagnosa_rujukan ?? '—' }}</td>
            <td>{{ row.kunjungan_ke ?? '—' }} / sisa {{ row.sisa_kunjungan ?? '—' }}</td>
            <td>{{ fmtDate(row.tgl_rujukan) }}</td>
            <td><span class="dot" :class="statusClass(row.status)">{{ row.status ?? '—' }}</span></td>
            <td class="chev">{{ expanded === row.id ? '▾' : '▸' }}</td>
          </tr>
          <tr v-if="expanded === row.id" class="detail">
            <td colspan="8">
              <div class="meta-grid">
                <div><span class="k">Kode FKTP</span><span>{{ row.fktp_kode ?? '—' }}</span></div>
                <div><span class="k">Diagnosa (kode)</span><span>{{ row.diagnosa_rujukan ?? '—' }}</span></div>
                <div><span class="k">Maks Kunjungan</span><span>{{ row.max_kunjungan ?? '—' }}</span></div>
                <div><span class="k">Tgl Expired</span><span>{{ fmtDate(row.tgl_expired) }}</span></div>
              </div>
              <div class="pl-title">Respons VClaim</div>
              <pre>{{ pretty(row.vclaim_response) }}</pre>
            </td>
          </tr>
        </template>
      </tbody>
    </table>

    <!-- ── RUJUKAN KELUAR ── -->
    <table v-else class="tbl">
      <thead>
        <tr>
          <th>No. Rujukan</th>
          <th>Pasien</th>
          <th>Faskes Tujuan</th>
          <th>Poli</th>
          <th>Diagnosa</th>
          <th>Tgl Rujukan</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <template v-for="row in rows" :key="row.id">
          <tr class="rw" @click="expanded = expanded === row.id ? null : row.id">
            <td><code>{{ row.no_rujukan ?? '—' }}</code></td>
            <td>{{ row.visit?.patient?.name ?? '—' }}</td>
            <td>{{ row.faskes_tujuan_nama ?? row.faskes_tujuan_kode ?? '—' }}</td>
            <td>{{ row.poli_rujukan_nama ?? row.poli_rujukan ?? '—' }}</td>
            <td>{{ row.diagnosa_nama ?? row.diagnosa_rujukan ?? '—' }}</td>
            <td>{{ fmtDate(row.tgl_rujukan) }}</td>
            <td><span class="dot" :class="statusClass(row.status)">{{ row.status ?? '—' }}</span></td>
            <td class="chev">{{ expanded === row.id ? '▾' : '▸' }}</td>
          </tr>
          <tr v-if="expanded === row.id" class="detail">
            <td colspan="8">
              <div class="meta-grid">
                <div><span class="k">Tipe Rujukan</span><span>{{ row.tipe_rujukan ?? '—' }}</span></div>
                <div><span class="k">Jenis Pelayanan</span><span>{{ row.jns_pelayanan ?? '—' }}</span></div>
                <div><span class="k">Urgensi</span><span>{{ row.urgency ?? '—' }}</span></div>
                <div><span class="k">Tgl Expired</span><span>{{ fmtDate(row.tgl_expired) }}</span></div>
                <div class="wide"><span class="k">Catatan</span><span>{{ row.catatan_rujukan ?? '—' }}</span></div>
              </div>
              <div class="pl-title">Respons VClaim</div>
              <pre>{{ pretty(row.vclaim_response) }}</pre>
            </td>
          </tr>
        </template>
      </tbody>
    </table>

    <div v-if="meta.last > 1" class="pager">
      <button class="btn" :disabled="meta.current <= 1" @click="load(meta.current - 1)">‹ Sebelumnya</button>
      <span class="muted">Hal {{ meta.current }} / {{ meta.last }} · {{ meta.total }} rujukan</span>
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
.dot.neu { background: #e0e7ff; color: #3730a3; }

.detail td { background: #fafbfc; }
.meta-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.6rem 1rem; margin-bottom: 0.7rem; }
@media (max-width: 800px) { .meta-grid { grid-template-columns: 1fr 1fr; } }
.meta-grid .wide { grid-column: 1 / -1; }
.meta-grid > div { display: flex; flex-direction: column; gap: 2px; }
.meta-grid .k { font-size: 10.5px; font-weight: 600; color: var(--tm); text-transform: uppercase; letter-spacing: 0.03em; }
.meta-grid > div > span:last-child { font-size: 12.5px; color: var(--td); }

.pl-title { font-size: 11px; font-weight: 600; color: var(--tm); margin-bottom: 3px; text-transform: uppercase; }
.detail pre { margin: 0; padding: 8px; background: #0f172a; color: #e2e8f0; border-radius: 6px; font-size: 11px; line-height: 1.4; max-height: 280px; overflow: auto; white-space: pre-wrap; word-break: break-word; }

.pager { display: flex; gap: 0.8rem; align-items: center; justify-content: center; }
</style>
