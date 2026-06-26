<script setup>
/**
 * Survei Kepuasan (#3) — agregasi tanggapan Google Form (lewat Sheet, anyone-with-link),
 * disinkron harian oleh cron. Tombol "Sinkron sekarang" untuk tarik manual (marketing.write).
 */
import { ref, computed, onMounted } from 'vue'
import { marketingApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'
import LineChart from '@/components/common/charts/LineChart.vue'
import BarChart from '@/components/common/charts/BarChart.vue'
import DoughnutChart from '@/components/common/charts/DoughnutChart.vue'

const auth = useAuthStore()
const canWrite = computed(() => auth.can('marketing.write'))

const loading = ref(false)
const syncing = ref(false)
const report = ref({
  configured: false, sheet_url: '',
  kpi: { total_responden: 0, avg_score: null, aspek_dinilai: 0 },
  aspects: [], distribution: [0, 0, 0, 0, 0], trend: [], columns: [], recent: [],
})
const msg = ref('')

// Konfigurasi URL Sheet (editable dari UI bila punya marketing.write).
const showConfig = ref(false)
const sheetUrlInput = ref('')
const savingCfg = ref(false)

async function load() {
  loading.value = true
  try {
    const res = await marketingApi.survei({})
    report.value = res.data?.data || report.value
    sheetUrlInput.value = report.value.sheet_url || ''
    // Buka panel konfigurasi otomatis bila belum dikonfigurasi.
    if (!report.value.configured) showConfig.value = true
  } catch {
    msg.value = 'Gagal memuat data survei.'
  } finally {
    loading.value = false
  }
}

async function saveConfig() {
  savingCfg.value = true
  msg.value = ''
  try {
    await marketingApi.surveiConfig({ sheet_url: sheetUrlInput.value || null })
    msg.value = 'URL Sheet disimpan.'
    await load()
    // Setelah set URL valid, langsung tarik datanya.
    if (report.value.configured) await syncNow()
    else showConfig.value = true
  } catch (e) {
    msg.value = e?.response?.data?.message || 'Gagal menyimpan URL (pastikan format URL valid).'
  } finally {
    savingCfg.value = false
  }
}

async function syncNow() {
  if (syncing.value) return
  syncing.value = true
  msg.value = ''
  try {
    const res = await marketingApi.surveiSync()
    const r = res.data?.data || {}
    msg.value = r.ok ? `Sinkron selesai: ${r.fetched} baris, ${r.inserted} baru.` : (r.message || 'Sheet belum dapat diakses.')
    await load()
  } catch (e) {
    msg.value = e?.response?.data?.message || 'Gagal sinkron.'
  } finally {
    syncing.value = false
  }
}

const trendData = computed(() => ({
  labels: report.value.trend.map(t => t.tgl),
  datasets: [{ label: 'Skor rata-rata', data: report.value.trend.map(t => t.avg_score), borderColor: '#1faae0', backgroundColor: 'rgba(31,170,224,.15)', fill: true, tension: 0.3 }],
}))
const trendOptions = { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, max: 5, ticks: { stepSize: 1 } } } }

// Rata-rata per aspek pelayanan (bar horizontal, skala 0–5).
const aspectData = computed(() => ({
  labels: report.value.aspects.map(a => a.label),
  datasets: [{
    label: 'Rata-rata',
    data: report.value.aspects.map(a => a.avg),
    backgroundColor: report.value.aspects.map(a => a.avg >= 4 ? '#16a34a' : a.avg >= 3 ? '#f59e0b' : '#dc2626'),
    borderRadius: 3,
  }],
}))
const aspectOptions = {
  indexAxis: 'y',
  plugins: { legend: { display: false }, tooltip: { callbacks: { title: (c) => report.value.aspects[c[0].dataIndex]?.full || '' } } },
  scales: { x: { beginAtZero: true, max: 5, ticks: { stepSize: 1 } } },
}

// Distribusi skor 1–5.
const DIST_COLORS = ['#dc2626', '#ea580c', '#f59e0b', '#84cc16', '#16a34a']
const distData = computed(() => ({
  labels: ['1 ★', '2 ★', '3 ★', '4 ★', '5 ★'],
  datasets: [{ data: report.value.distribution, backgroundColor: DIST_COLORS, borderWidth: 0 }],
}))
const hasDist = computed(() => (report.value.distribution || []).some(n => n > 0))

// Kolom tabel dari backend (sudah dibersihkan dari kolom kosong/junk).
const cols = computed(() => report.value.columns || [])

onMounted(load)
</script>

<template>
  <div>
    <div class="bar">
      <div>
        <h3 class="title">Survei Kepuasan Pasien</h3>
        <p class="sub">Tanggapan ditarik otomatis tiap hari dari Google Form (via Sheet tanggapan).</p>
      </div>
      <div class="bar-actions">
        <button v-if="canWrite" class="btn-soft" @click="showConfig = !showConfig">⚙ Konfigurasi</button>
        <button v-if="canWrite" class="btn-soft accent" @click="syncNow" :disabled="syncing || !report.configured">
          {{ syncing ? 'Menyinkron…' : 'Sinkron sekarang' }}
        </button>
      </div>
    </div>

    <!-- Panel konfigurasi URL Sheet (editable di UI) -->
    <div v-if="showConfig" class="cfg-card">
      <label class="cfg-label">URL Google Sheet tanggapan survei</label>
      <div class="cfg-row">
        <input
          type="url"
          v-model="sheetUrlInput"
          :disabled="!canWrite"
          placeholder="https://docs.google.com/spreadsheets/d/…/edit"
          class="cfg-input"
        />
        <button v-if="canWrite" class="btn-soft accent" @click="saveConfig" :disabled="savingCfg">
          {{ savingCfg ? 'Menyimpan…' : 'Simpan & Sinkron' }}
        </button>
      </div>
      <p class="cfg-hint">
        Buka Google Form → tab <strong>Responses</strong> → <strong>Link to Sheets</strong>. Lalu buka Sheet itu →
        Share → <strong>"Anyone with the link → Viewer"</strong>. Tempel URL Sheet-nya di sini (bukan link Form <code>/edit</code>).
      </p>
    </div>

    <p v-if="!report.configured && !showConfig" class="note">
      ⚠️ URL Sheet survei belum diatur. Klik <strong>⚙ Konfigurasi</strong> untuk menempel link-nya.
    </p>
    <p v-if="msg" class="note info">{{ msg }}</p>

    <div class="kpi-row">
      <div class="kpi"><span class="kpi-val">{{ report.kpi.avg_score ?? '—' }}<small>/5</small></span><span class="kpi-lbl">Skor Rata-rata</span></div>
      <div class="kpi"><span class="kpi-val">{{ report.kpi.total_responden }}</span><span class="kpi-lbl">Total Responden</span></div>
      <div class="kpi"><span class="kpi-val">{{ report.kpi.aspek_dinilai || 0 }}</span><span class="kpi-lbl">Aspek Dinilai</span></div>
    </div>

    <!-- Dashboard: aspek + distribusi -->
    <div class="grid-2" v-if="report.aspects.length || hasDist">
      <div class="card">
        <h3>Rata-rata per Aspek Pelayanan</h3>
        <BarChart v-if="report.aspects.length" :data="aspectData" :options="aspectOptions" :height="`${Math.max(220, report.aspects.length * 42)}px`" />
        <p v-else class="empty">Tidak ada aspek bernilai numerik.</p>
      </div>
      <div class="card">
        <h3>Distribusi Skor</h3>
        <DoughnutChart v-if="hasDist" :data="distData" height="300px" />
        <p v-else class="empty">Belum ada skor.</p>
      </div>
    </div>

    <div class="card" v-if="report.trend.length > 1">
      <h3>Tren Skor Harian</h3>
      <LineChart :data="trendData" :options="trendOptions" height="240px" />
    </div>

    <div class="card">
      <h3>Tanggapan Terbaru</h3>
      <div class="table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th>Waktu</th><th>Nama</th><th class="num">Skor</th>
              <th v-for="c in cols" :key="c.key" :title="c.key">{{ c.label }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!report.recent.length"><td :colspan="3 + cols.length" class="empty">Belum ada tanggapan.</td></tr>
            <tr v-for="r in report.recent" :key="r.id">
              <td>{{ r.submitted_at || '-' }}</td>
              <td class="strong">{{ r.nama || '-' }}</td>
              <td class="num"><span class="score-pill" :class="{ hi: r.score >= 4, mid: r.score === 3, lo: r.score <= 2 && r.score != null }">{{ r.score ?? '-' }}</span></td>
              <td v-for="c in cols" :key="c.key">{{ (r.payload && r.payload[c.key]) || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bar { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 14px; }
.bar-actions { display: flex; gap: 8px; flex-shrink: 0; }
.title { font-size: 1rem; font-weight: 700; color: var(--gd); margin: 0; }
.sub { font-size: 0.82rem; color: var(--tu); margin: 3px 0 0; }
.btn-soft { padding: 7px 14px; border: 1px solid var(--gb); border-radius: 9px; background: var(--bc); font-size: 0.85rem; font-weight: 600; color: var(--tm); cursor: pointer; white-space: nowrap; }
.btn-soft.accent { background: var(--gd); border-color: var(--gd); color: #fff; }
.btn-soft:disabled { opacity: .55; cursor: not-allowed; }

.cfg-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
.cfg-label { display: block; font-size: 0.8rem; font-weight: 700; color: var(--gd); margin-bottom: 6px; }
.cfg-row { display: flex; gap: 8px; align-items: center; }
.cfg-input { flex: 1; border: 1px solid var(--gb); border-radius: 8px; padding: 8px 11px; font-size: 0.85rem; color: var(--td); background: var(--bc); }
.cfg-input:focus { outline: none; border-color: var(--ga); box-shadow: 0 0 0 2px rgba(31,170,224,.15); }
.cfg-input:disabled { background: var(--bs); }
.cfg-hint { font-size: 0.78rem; color: var(--tu); margin: 9px 0 0; line-height: 1.5; }
.cfg-hint code { background: rgba(0,0,0,.06); padding: 1px 5px; border-radius: 4px; }

.note { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 10px; padding: 10px 14px; font-size: 0.82rem; margin-bottom: 14px; }
.note.info { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
.note code { background: rgba(0,0,0,.06); padding: 1px 5px; border-radius: 4px; }

.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px; }
.kpi { background: var(--bc); border: 1px solid var(--gb); border-radius: 14px; padding: 16px; display: flex; flex-direction: column; }
.kpi-val { font-size: 1.6rem; font-weight: 800; color: var(--gd); font-family: var(--font-display); }
.kpi-val small { font-size: 0.9rem; color: var(--tu); font-weight: 600; }
.kpi-lbl { font-size: 0.78rem; color: var(--tu); font-weight: 600; margin-top: 3px; }

.grid-2 { display: grid; grid-template-columns: 1.4fr 1fr; gap: 16px; }
@media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }

.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 14px; padding: 16px; margin-bottom: 16px; }
.card h3 { font-size: 0.95rem; font-weight: 700; color: var(--gd); margin: 0 0 12px; }

.score-pill { display: inline-block; min-width: 26px; padding: 1px 7px; border-radius: 999px; font-weight: 700; font-size: 0.78rem; background: var(--bs); color: var(--tm); }
.score-pill.hi { background: #dcfce7; color: #166534; }
.score-pill.mid { background: #fef9c3; color: #854d0e; }
.score-pill.lo { background: #fee2e2; color: #b91c1c; }
.table-wrap { border: 1px solid var(--gb); border-radius: 12px; overflow: auto; }
.po-table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
.po-table thead th { background: var(--bs); text-align: left; padding: 9px 12px; font-weight: 700; color: var(--gd); border-bottom: 1px solid var(--gb); font-size: 0.76rem; white-space: nowrap; }
.po-table tbody td { padding: 8px 12px; border-bottom: 1px solid var(--bi); color: var(--td); }
.po-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.po-table thead th.num { text-align: right; }
.po-table .strong { font-weight: 600; }
.empty { text-align: center; color: var(--th); padding: 24px 12px; }
</style>
