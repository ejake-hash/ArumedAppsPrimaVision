<script setup>
/**
 * Dashboard analitik marketing: kunjungan per penjamin (#2) + top wilayah pasien (#6).
 * Read-only; filter periode sendiri (default awal bulan → hari ini).
 */
import { ref, computed, onMounted } from 'vue'
import { marketingApi, masterApi } from '@/services/api'
import DoughnutChart from '@/components/common/charts/DoughnutChart.vue'
import BarChart from '@/components/common/charts/BarChart.vue'

function isoToday() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function isoMonthStart() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
}

const from = ref(isoMonthStart())
const to = ref(isoToday())
const loading = ref(false)

// Mode pengelompokan: 'jenis' (default — semua asuransi jadi satu "Asuransi") atau
// 'penjamin' (breakdown detail per insurer). Filter penjamin opsional memfokuskan satu.
const groupBy = ref('jenis')
const penjaminId = ref('')
const penjaminList = ref([])
const openFmt = ref(false)

const penjaminRows = ref([])
const totals = ref({ total_kunjungan: 0, total_pasien: 0, penjamin_unik: 0 })
const colLabel = computed(() => (groupBy.value === 'penjamin' ? 'Penjamin' : 'Jenis Penjamin'))

const wilayahLevel = ref('kota')
const wilayahRows = ref([])

const PALETTE = ['#1faae0', '#6d28d9', '#0369a1', '#be185d', '#c2410c', '#0f766e', '#b45309', '#4f46e5', '#0891b2', '#9333ea']

function dashParams() {
  const p = { from: from.value, to: to.value, group_by: groupBy.value }
  if (penjaminId.value) p.insurer_id = penjaminId.value
  return p
}

async function load() {
  loading.value = true
  try {
    const [d, w] = await Promise.all([
      marketingApi.dashboardPenjamin(dashParams()),
      marketingApi.topWilayah({ from: from.value, to: to.value, level: wilayahLevel.value, limit: 15 }),
    ])
    penjaminRows.value = d.data?.data?.rows || []
    totals.value = d.data?.data?.totals || { total_kunjungan: 0, total_pasien: 0, penjamin_unik: 0 }
    wilayahRows.value = w.data?.data?.rows || []
  } catch {
    penjaminRows.value = []
    wilayahRows.value = []
  } finally {
    loading.value = false
  }
}

async function loadPenjamin() {
  try {
    const { data } = await masterApi.penjamin.list({ per_page: 200 })
    const r = data.data?.data ?? data.data ?? []
    penjaminList.value = Array.isArray(r) ? r : (r.data ?? [])
  } catch { penjaminList.value = [] }
}

function setGroupBy(g) {
  if (groupBy.value === g) return
  groupBy.value = g
  load()
}

function triggerDownload(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url; a.download = filename
  document.body.appendChild(a); a.click(); document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

async function exportData(format) {
  openFmt.value = false
  try {
    const res = await marketingApi.dashboardPenjaminExport(dashParams(), format === 'xlsx' ? 'xlsx' : undefined)
    triggerDownload(res.data, `dashboard-penjamin-${groupBy.value}.${format}`)
  } catch { /* silent */ }
}

async function loadWilayah() {
  try {
    const w = await marketingApi.topWilayah({ from: from.value, to: to.value, level: wilayahLevel.value, limit: 15 })
    wilayahRows.value = w.data?.data?.rows || []
  } catch {
    wilayahRows.value = []
  }
}

// Doughnut: pangsa kunjungan per penjamin (top 8 + "Lainnya").
const donutData = computed(() => {
  const rows = penjaminRows.value
  const top = rows.slice(0, 8)
  const restSum = rows.slice(8).reduce((s, r) => s + r.total_kunjungan, 0)
  const labels = top.map(r => r.penjamin)
  const data = top.map(r => r.total_kunjungan)
  if (restSum > 0) { labels.push('Lainnya'); data.push(restSum) }
  return {
    labels,
    datasets: [{ data, backgroundColor: labels.map((_, i) => PALETTE[i % PALETTE.length]), borderWidth: 0 }],
  }
})

// Bar bertumpuk RJ/RI/Bedah per penjamin (top 10).
const barData = computed(() => {
  const rows = penjaminRows.value.slice(0, 10)
  return {
    labels: rows.map(r => r.penjamin),
    datasets: [
      { label: 'Rawat Jalan', data: rows.map(r => r.rj), backgroundColor: '#1faae0', borderRadius: 3, stack: 'k' },
      { label: 'Rawat Inap', data: rows.map(r => r.ri), backgroundColor: '#6d28d9', borderRadius: 3, stack: 'k' },
      { label: 'Bedah', data: rows.map(r => r.bedah), backgroundColor: '#c2410c', borderRadius: 3, stack: 'k' },
    ],
  }
})
const barOptions = {
  plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
  scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } },
}

const wilayahData = computed(() => ({
  labels: wilayahRows.value.map(r => r.wilayah),
  datasets: [{ label: 'Kunjungan', data: wilayahRows.value.map(r => r.total_kunjungan), backgroundColor: '#1faae0', borderRadius: 3 }],
}))
const wilayahOptions = { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }

onMounted(() => { load(); loadPenjamin() })
</script>

<template>
  <div>
    <!-- Filter periode + mode + penjamin + export -->
    <div class="filter-bar" @click="openFmt = false">
      <label>Dari <input type="date" v-model="from" /></label>
      <label>Sampai <input type="date" v-model="to" /></label>
      <div class="seg">
        <button :class="{ on: groupBy === 'jenis' }" @click="setGroupBy('jenis')" title="Semua asuransi terhitung sebagai satu 'Asuransi'">Per Jenis</button>
        <button :class="{ on: groupBy === 'penjamin' }" @click="setGroupBy('penjamin')" title="Breakdown detail per penjamin">Per Penjamin</button>
      </div>
      <label>Penjamin
        <select v-model="penjaminId" @change="load" class="sel">
          <option value="">Semua</option>
          <option v-for="p in penjaminList" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </label>
      <button class="btn-soft accent" @click="load" :disabled="loading">Terapkan</button>
      <div class="fmt-wrap" @click.stop>
        <button class="btn-soft" @click="openFmt = !openFmt" :disabled="loading || !penjaminRows.length">Export ▾</button>
        <div v-if="openFmt" class="fmt-menu">
          <button @click="exportData('csv')">CSV (.csv)</button>
          <button @click="exportData('xlsx')">Excel (.xlsx)</button>
        </div>
      </div>
      <span class="count" v-if="!loading">{{ totals.penjamin_unik }} {{ groupBy === 'penjamin' ? 'penjamin' : 'jenis' }} · {{ totals.total_kunjungan }} kunjungan · {{ totals.total_pasien }} pasien</span>
    </div>

    <!-- KPI -->
    <div class="kpi-row">
      <div class="kpi"><span class="kpi-val">{{ totals.total_kunjungan }}</span><span class="kpi-lbl">Total Kunjungan</span></div>
      <div class="kpi"><span class="kpi-val">{{ totals.total_pasien }}</span><span class="kpi-lbl">Total Pasien</span></div>
      <div class="kpi"><span class="kpi-val">{{ totals.penjamin_unik }}</span><span class="kpi-lbl">Penjamin Unik</span></div>
    </div>

    <!-- Chart penjamin -->
    <div class="grid-2">
      <div class="card">
        <h3>Pangsa Kunjungan per Penjamin</h3>
        <DoughnutChart v-if="penjaminRows.length" :data="donutData" height="300px" />
        <p v-else class="empty">Tidak ada data pada periode ini.</p>
      </div>
      <div class="card">
        <h3>Kunjungan per Penjamin (RJ/RI/Bedah)</h3>
        <BarChart v-if="penjaminRows.length" :data="barData" :options="barOptions" height="300px" />
        <p v-else class="empty">Tidak ada data pada periode ini.</p>
      </div>
    </div>

    <!-- Tabel penjamin -->
    <div class="card">
      <h3>Rincian {{ groupBy === 'penjamin' ? 'per Penjamin' : 'per Jenis Penjamin' }}</h3>
      <div class="table-wrap">
        <table class="po-table">
          <thead>
            <tr><th>{{ colLabel }}</th><th class="num">Kunjungan</th><th class="num">Pasien</th><th class="num">RJ</th><th class="num">RI</th><th class="num">Bedah</th></tr>
          </thead>
          <tbody>
            <tr v-if="!penjaminRows.length"><td colspan="6" class="empty">Tidak ada data.</td></tr>
            <tr v-for="r in penjaminRows" :key="r.penjamin">
              <td class="strong">{{ r.penjamin }}</td>
              <td class="num">{{ r.total_kunjungan }}</td>
              <td class="num">{{ r.total_pasien }}</td>
              <td class="num">{{ r.rj }}</td>
              <td class="num">{{ r.ri }}</td>
              <td class="num">{{ r.bedah }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Top wilayah -->
    <div class="card">
      <div class="card-head">
        <h3>Top Wilayah Asal Pasien</h3>
        <div class="seg">
          <button :class="{ on: wilayahLevel === 'kota' }" @click="wilayahLevel = 'kota'; loadWilayah()">Kota/Kab</button>
          <button :class="{ on: wilayahLevel === 'kecamatan' }" @click="wilayahLevel = 'kecamatan'; loadWilayah()">Kecamatan</button>
        </div>
      </div>
      <div class="grid-2">
        <BarChart v-if="wilayahRows.length" :data="wilayahData" :options="wilayahOptions" height="360px" />
        <p v-else class="empty">Tidak ada data wilayah.</p>
        <div class="table-wrap">
          <table class="po-table">
            <thead><tr><th>No</th><th>Wilayah</th><th class="num">Kunjungan</th><th class="num">Pasien</th></tr></thead>
            <tbody>
              <tr v-if="!wilayahRows.length"><td colspan="4" class="empty">Tidak ada data.</td></tr>
              <tr v-for="(r, i) in wilayahRows" :key="r.wilayah">
                <td>{{ i + 1 }}</td>
                <td class="strong">{{ r.wilayah }}</td>
                <td class="num">{{ r.total_kunjungan }}</td>
                <td class="num">{{ r.total_pasien }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.filter-bar { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; flex-wrap: wrap; }
.filter-bar label { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; color: var(--tm); font-weight: 600; }
.filter-bar input[type="date"] { border: 1px solid var(--gb); border-radius: 8px; padding: 6px 9px; font-size: 0.85rem; color: var(--td); background: var(--bc); }
.count { font-size: 0.82rem; color: var(--tu); font-weight: 600; }
.btn-soft { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1px solid var(--gb); border-radius: 9px; background: var(--bc); font-size: 0.85rem; font-weight: 600; color: var(--tm); cursor: pointer; }
.btn-soft.accent { background: var(--gd); border-color: var(--gd); color: #fff; }
.btn-soft:disabled { opacity: .55; cursor: not-allowed; }

.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px; }
.kpi { background: var(--bc); border: 1px solid var(--gb); border-radius: 14px; padding: 16px; display: flex; flex-direction: column; }
.kpi-val { font-size: 1.6rem; font-weight: 800; color: var(--gd); font-family: var(--font-display); }
.kpi-lbl { font-size: 0.78rem; color: var(--tu); font-weight: 600; margin-top: 3px; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }

.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 14px; padding: 16px; margin-bottom: 16px; }
.card h3 { font-size: 0.95rem; font-weight: 700; color: var(--gd); margin: 0 0 12px; }
.card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.card-head h3 { margin: 0; }

.seg { display: inline-flex; border: 1px solid var(--gb); border-radius: 8px; overflow: hidden; }
.seg button { padding: 6px 12px; background: var(--bc); border: none; font-size: 0.8rem; font-weight: 600; color: var(--tm); cursor: pointer; }
.seg button.on { background: var(--gd); color: #fff; }

.sel { border: 1px solid var(--gb); border-radius: 8px; padding: 6px 9px; font-size: 0.85rem; color: var(--td); background: var(--bc); max-width: 200px; }
.sel:focus { outline: none; border-color: var(--ga); box-shadow: 0 0 0 2px rgba(31,170,224,.15); }

.fmt-wrap { position: relative; }
.fmt-menu { position: absolute; right: 0; top: calc(100% + 6px); z-index: 20; background: var(--bc); border: 1px solid var(--gb); border-radius: 10px; box-shadow: 0 8px 24px rgba(15,23,42,.12); min-width: 150px; overflow: hidden; }
.fmt-menu button { display: block; width: 100%; text-align: left; padding: 9px 14px; background: none; border: none; font-size: 0.85rem; color: var(--tm); cursor: pointer; }
.fmt-menu button:hover { background: var(--bs); }

.table-wrap { border: 1px solid var(--gb); border-radius: 12px; overflow: auto; }
.po-table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
.po-table thead th { background: var(--bs); text-align: left; padding: 9px 12px; font-weight: 700; color: var(--gd); border-bottom: 1px solid var(--gb); font-size: 0.76rem; }
.po-table tbody td { padding: 8px 12px; border-bottom: 1px solid var(--bi); color: var(--td); }
.po-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.po-table thead th.num { text-align: right; }
.po-table .strong { font-weight: 600; }
.empty { text-align: center; color: var(--th); padding: 24px 12px; }
</style>
