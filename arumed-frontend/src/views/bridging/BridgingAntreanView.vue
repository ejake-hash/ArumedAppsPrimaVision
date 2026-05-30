<script setup>
/**
 * BridgingAntreanView — Antrean Online BPJS: status, dashboard waktu tunggu
 * (wajib lapor), dan validasi kode booking JKN Mobile.
 */
import { ref, reactive, onMounted } from 'vue'
import { integrasiApi } from '@/services/api'

const tab = ref('dashboard') // dashboard | booking
const tabs = [
  { key: 'dashboard', label: 'Dashboard Waktu Tunggu' },
  { key: 'booking',   label: 'Validasi Booking' },
]

const today = () => new Date().toISOString().slice(0, 10)

// ── Status Antrean (dari /integrasi/status) ─────────────────────────────────
const status = reactive({ loading: true, enabled: false, hasCred: false, lastTest: null, lastAt: null })
async function loadStatus() {
  status.loading = true
  try {
    const res = await integrasiApi.status()
    const a = (res.data?.data ?? []).find((s) => s.system_name === 'ANTREAN')
    if (a) {
      status.enabled = a.is_enabled
      status.hasCred = a.has_credentials
      status.lastTest = a.last_test_status
      status.lastAt = a.last_tested_at
    }
  } finally {
    status.loading = false
  }
}

function makePanel() { return reactive({ loading: false, error: '', data: null }) }
async function run(panel, fn) {
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

// ── Dashboard ───────────────────────────────────────────────────────────────
const dash = reactive({ jenis: 'tanggal', tanggal: today(), bulan: String(new Date().getMonth() + 1).padStart(2, '0'), tahun: String(new Date().getFullYear()), waktu: 'rs', ...makePanel() })
function loadDashboard() {
  const p = dash.jenis === 'bulan'
    ? { bulan: dash.bulan, tahun: dash.tahun, waktu: dash.waktu }
    : { tanggal: dash.tanggal, waktu: dash.waktu }
  run(dash, () => integrasiApi.antreanDashboard(dash.jenis, p))
}

// ── Validasi Booking ────────────────────────────────────────────────────────
const booking = reactive({ code: '', tgl: today(), ...makePanel() })
function validateBooking() {
  run(booking, () => integrasiApi.validateBooking({ booking_code: booking.code.trim(), tgl_periksa: booking.tgl }))
}

function pretty(v) { try { return JSON.stringify(v, null, 2) } catch { return String(v) } }

onMounted(loadStatus)
</script>

<template>
  <div class="an">
    <!-- Status ringkas -->
    <div class="status-bar" :class="status.enabled ? (status.lastTest === 'SUCCESS' ? 's-ok' : 's-on') : 's-off'">
      <template v-if="status.loading">Memuat status…</template>
      <template v-else-if="!status.hasCred">Antrean belum dikonfigurasi — isi credential di <RouterLink to="/bridging/konfigurasi">Konfigurasi &amp; Status</RouterLink>.</template>
      <template v-else-if="!status.enabled">Antrean <b>nonaktif</b>. Aktifkan di Konfigurasi &amp; Status untuk mulai lapor antrean ke BPJS.</template>
      <template v-else>
        Antrean <b>aktif</b><template v-if="status.lastTest"> · test terakhir: {{ status.lastTest }}</template>.
      </template>
    </div>

    <div class="seg">
      <button v-for="t in tabs" :key="t.key" :class="{ active: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
    </div>

    <!-- DASHBOARD WAKTU TUNGGU -->
    <section v-if="tab === 'dashboard'" class="panel">
      <p class="hint">Data waktu tunggu yang dilaporkan ke BPJS (penilaian keaktifan faskes).</p>
      <div class="form-row">
        <select v-model="dash.jenis" class="inp">
          <option value="tanggal">Per Tanggal</option>
          <option value="bulan">Per Bulan</option>
        </select>
        <input v-if="dash.jenis === 'tanggal'" v-model="dash.tanggal" type="date" class="inp" />
        <template v-else>
          <select v-model="dash.bulan" class="inp">
            <option v-for="m in 12" :key="m" :value="String(m).padStart(2, '0')">{{ String(m).padStart(2, '0') }}</option>
          </select>
          <input v-model="dash.tahun" class="inp w-90" placeholder="Tahun" />
        </template>
        <select v-model="dash.waktu" class="inp">
          <option value="rs">Waktu RS</option>
          <option value="server">Waktu Server BPJS</option>
        </select>
        <button class="btn primary" :disabled="dash.loading" @click="loadDashboard">{{ dash.loading ? 'Memuat…' : 'Tampilkan' }}</button>
      </div>

      <p v-if="dash.error" class="banner err">{{ dash.error }}</p>
      <pre v-else-if="dash.data" class="json">{{ pretty(dash.data) }}</pre>
    </section>

    <!-- VALIDASI BOOKING -->
    <section v-else class="panel">
      <p class="hint">Cek kode booking dari aplikasi Mobile JKN sebelum pasien dilayani.</p>
      <div class="form-row">
        <input v-model="booking.code" class="inp grow" placeholder="Kode booking JKN Mobile" @keyup.enter="validateBooking" />
        <input v-model="booking.tgl" type="date" class="inp" />
        <button class="btn primary" :disabled="booking.loading || !booking.code" @click="validateBooking">{{ booking.loading ? 'Mengecek…' : 'Validasi' }}</button>
      </div>

      <p v-if="booking.error" class="banner err">{{ booking.error }}</p>
      <pre v-else-if="booking.data" class="json">{{ pretty(booking.data) }}</pre>
    </section>
  </div>
</template>

<style scoped>
.an { display: flex; flex-direction: column; gap: 1rem; }

.status-bar { padding: 10px 14px; border-radius: 8px; font-size: 13px; }
.status-bar a { color: #1763d4; font-weight: 600; }
.s-off { background: #fef3c7; color: #92400e; }
.s-on  { background: #e0e7ff; color: #3730a3; }
.s-ok  { background: #dcfce7; color: #166534; }

.seg { display: inline-flex; border: 1px solid var(--gb); border-radius: 8px; overflow: hidden; align-self: flex-start; }
.seg button { padding: 8px 16px; font-size: 12.5px; font-weight: 600; border: none; background: #fff; color: var(--tm); cursor: pointer; border-right: 1px solid var(--gb); }
.seg button:last-child { border-right: none; }
.seg button.active { background: #1763d4; color: #fff; }

.panel { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 1.1rem; display: flex; flex-direction: column; gap: 0.9rem; }
.hint { font-size: 12px; color: var(--tm); margin: 0; }

.form-row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
.inp { padding: 8px 10px; border: 1px solid var(--gb); border-radius: 7px; font-size: 13px; color: #000; background: #fff; }
.inp.grow { flex: 1; min-width: 200px; }
.inp.w-90 { width: 90px; }
.inp:focus { outline: none; border-color: #1763d4; }
.btn { padding: 8px 16px; border: 1px solid var(--gb); border-radius: 7px; font-size: 12.5px; font-weight: 600; background: #fff; color: #000; cursor: pointer; }
.btn:disabled { opacity: 0.55; cursor: not-allowed; }
.btn.primary { background: #1763d4; color: #fff; border-color: #1763d4; }

.banner { padding: 10px 12px; border-radius: 8px; font-size: 13px; margin: 0; background: var(--bs); color: var(--td); }
.banner.err { background: #fef3c7; color: #92400e; }

.json { margin: 0; padding: 12px; background: #0f172a; color: #e2e8f0; border-radius: 8px; font-size: 11.5px; line-height: 1.5; max-height: 460px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
</style>
