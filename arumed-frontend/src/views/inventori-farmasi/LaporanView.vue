<script setup>
/**
 * LaporanView — Laporan Inventori Farmasi: Ringkasan + Pemesanan + Retur.
 *
 * Tujuan: tracking konsumsi Obat/BHP/IOL per unit (dikirim − retur) agar stok tak bocor.
 * Sumber data: request (qty_delivered) & retur (qty_returned) — bukan kwitansi pasien.
 */
import { ref, reactive, computed, onMounted } from 'vue'
import { inventoriLaporanApi } from '@/services/api'
import Pager from '@/components/common/Pager.vue'
import BarChart from '@/components/common/charts/BarChart.vue'
import LineChart from '@/components/common/charts/LineChart.vue'
import DoughnutChart from '@/components/common/charts/DoughnutChart.vue'

const STATIONS = ['ADMISI', 'TRIASE', 'REFRAKSIONIS', 'DOKTER', 'PENUNJANG', 'BEDAH', 'KASIR', 'FARMASI', 'RANAP', 'IGD']
const TYPES = [{ v: 'MEDICATION', l: 'Obat' }, { v: 'BHP', l: 'BHP' }, { v: 'IOL', l: 'IOL' }]
const CONDITIONS = [{ v: 'GOOD', l: 'Baik' }, { v: 'DAMAGED', l: 'Rusak' }, { v: 'EXPIRED', l: 'Kadaluarsa' }, { v: 'NEAR_EXPIRY', l: 'Hampir Exp' }]
const PER_PAGE = 50

function localYmd(d) {
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}
const now = new Date()
const period = reactive({
  from: localYmd(new Date(now.getFullYear(), now.getMonth(), 1)),
  to:   localYmd(now),
})

const tab = ref('ringkasan')   // 'ringkasan' | 'pemesanan' | 'retur'

// ─── Toast mini ───────────────────────────────────────────────────────────
const toastMsg = ref(''); const toastType = ref('i'); let toastTimer = null
function toast(type, msg) { toastType.value = type; toastMsg.value = msg; clearTimeout(toastTimer); toastTimer = setTimeout(() => toastMsg.value = '', 3000) }

// ─── RINGKASAN ─────────────────────────────────────────────────────────────
const emptySummary = () => ({
  kpi: { requested: 0, delivered: 0, returned: 0, returned_waste: 0, returned_good: 0, net_consumed: 0, active_units: 0 },
  by_type: [], by_station: [], trend: [], top_items: [],
})
const summary = ref(emptySummary())
const summaryLoading = ref(false)

async function loadSummary() {
  summaryLoading.value = true
  try {
    const res = await inventoriLaporanApi.summary({ from: period.from, to: period.to })
    summary.value = res.data?.data ?? emptySummary()
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal memuat ringkasan')
  } finally {
    summaryLoading.value = false
  }
}

const TYPE_COLORS = { Obat: '#2563eb', BHP: '#16a34a', IOL: '#9333ea' }
const donutData = computed(() => ({
  labels: summary.value.by_type.map((t) => t.type),
  datasets: [{ data: summary.value.by_type.map((t) => t.delivered), backgroundColor: summary.value.by_type.map((t) => TYPE_COLORS[t.type] ?? '#94a3b8'), borderWidth: 0 }],
}))
const stationData = computed(() => ({
  labels: summary.value.by_station.map((s) => s.station),
  datasets: [{ label: 'Konsumsi bersih', data: summary.value.by_station.map((s) => s.net), backgroundColor: '#0ea5e9', borderRadius: 4 }],
}))
const trendData = computed(() => ({
  labels: summary.value.trend.map((t) => t.bucket),
  datasets: [{ label: 'Dikirim', data: summary.value.trend.map((t) => t.qty), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.12)', fill: true, tension: 0.3, pointRadius: 2 }],
}))
const topData = computed(() => ({
  labels: summary.value.top_items.map((t) => t.label),
  datasets: [{ label: 'Qty dikirim', data: summary.value.top_items.map((t) => t.qty), backgroundColor: '#16a34a', borderRadius: 4 }],
}))
const topOptions = { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }

const hasSummary = computed(() => summary.value.by_station.length || summary.value.by_type.some((t) => t.delivered || t.returned))

// ─── PEMESANAN ─────────────────────────────────────────────────────────────
const pmFilter = reactive({ station: '', item_type: '', status: '', search: '' })
const pmRows = ref([]); const pmMeta = ref({ current_page: 1, last_page: 1, total: 0 }); const pmLoading = ref(false)
const pmParams = computed(() => ({ from: period.from, to: period.to, ...cleaned(pmFilter) }))

async function loadPemesanan(page = 1) {
  pmLoading.value = true
  try {
    const res = await inventoriLaporanApi.pemesanan({ ...pmParams.value, per_page: PER_PAGE, page })
    pmRows.value = res.data?.data ?? []
    pmMeta.value = res.data?.meta ?? { current_page: 1, last_page: 1, total: 0 }
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal memuat pemesanan')
  } finally {
    pmLoading.value = false
  }
}

// ─── RETUR ─────────────────────────────────────────────────────────────────
const rtFilter = reactive({ station: '', item_type: '', condition: '', search: '' })
const rtRows = ref([]); const rtMeta = ref({ current_page: 1, last_page: 1, total: 0 }); const rtLoading = ref(false)
const rtParams = computed(() => ({ from: period.from, to: period.to, ...cleaned(rtFilter) }))

async function loadRetur(page = 1) {
  rtLoading.value = true
  try {
    const res = await inventoriLaporanApi.retur({ ...rtParams.value, per_page: PER_PAGE, page })
    rtRows.value = res.data?.data ?? []
    rtMeta.value = res.data?.meta ?? { current_page: 1, last_page: 1, total: 0 }
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal memuat retur')
  } finally {
    rtLoading.value = false
  }
}

function cleaned(obj) { return Object.fromEntries(Object.entries(obj).filter(([, v]) => v !== '' && v != null)) }

// ─── Filter & tab orchestration ────────────────────────────────────────────
function applyFilter() {
  if (tab.value === 'ringkasan') loadSummary()
  else if (tab.value === 'pemesanan') loadPemesanan(1)
  else loadRetur(1)
}
function switchTab(t) {
  tab.value = t
  if (t === 'ringkasan' && !hasSummary.value) loadSummary()
  else if (t === 'pemesanan' && !pmRows.value.length) loadPemesanan(1)
  else if (t === 'retur' && !rtRows.value.length) loadRetur(1)
}

// ─── Export ────────────────────────────────────────────────────────────────
function triggerDownload(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url; a.download = filename
  document.body.appendChild(a); a.click(); document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
async function exportData(kind, format) {
  try {
    const fn = kind === 'pemesanan' ? inventoriLaporanApi.pemesananExport : inventoriLaporanApi.returExport
    const params = kind === 'pemesanan' ? pmParams.value : rtParams.value
    const res = await fn(params, format)
    triggerDownload(res.data, `laporan-${kind}-${period.from}_${period.to}.${format === 'xlsx' ? 'xlsx' : 'csv'}`)
  } catch (e) {
    toast('w', 'Gagal mengekspor')
  }
}

function fmtQty(n) { return Number(n).toLocaleString('id-ID') }
function condLabel(c) { return CONDITIONS.find((x) => x.v === c)?.l ?? (c || '—') }

onMounted(loadSummary)
</script>

<template>
  <div class="lap">
    <!-- HEADER + FILTER PERIODE -->
    <div class="lap-head">
      <div>
        <h2>Laporan Inventori</h2>
        <p class="lap-sub">Tracking konsumsi Obat/BHP/IOL per unit (dikirim − retur) untuk pantau kebocoran stok.</p>
      </div>
      <div class="lap-period">
        <label>Dari <input type="date" v-model="period.from" /></label>
        <label>Sampai <input type="date" v-model="period.to" /></label>
        <button class="btn-apply" @click="applyFilter">Terapkan</button>
      </div>
    </div>

    <!-- SUB-TABS -->
    <div class="lap-tabs">
      <button :class="['lap-tab', tab === 'ringkasan' ? 'a' : '']" @click="switchTab('ringkasan')">Ringkasan</button>
      <button :class="['lap-tab', tab === 'pemesanan' ? 'a' : '']" @click="switchTab('pemesanan')">Pemesanan</button>
      <button :class="['lap-tab', tab === 'retur' ? 'a' : '']" @click="switchTab('retur')">Retur</button>
    </div>

    <!-- ════════════ RINGKASAN ════════════ -->
    <div v-if="tab === 'ringkasan'">
      <div class="kpi-row">
        <div class="kpi"><div class="kpi-val">{{ fmtQty(summary.kpi.requested) }}</div><div class="kpi-lbl">Qty Diminta</div></div>
        <div class="kpi"><div class="kpi-val">{{ fmtQty(summary.kpi.delivered) }}</div><div class="kpi-lbl">Qty Dikirim</div></div>
        <div class="kpi"><div class="kpi-val">{{ fmtQty(summary.kpi.returned) }}</div><div class="kpi-lbl">Qty Retur</div></div>
        <div class="kpi waste"><div class="kpi-val">{{ fmtQty(summary.kpi.returned_waste) }}</div><div class="kpi-lbl">Retur Rusak/Exp</div></div>
        <div class="kpi accent"><div class="kpi-val">{{ fmtQty(summary.kpi.net_consumed) }}</div><div class="kpi-lbl">Konsumsi Bersih</div></div>
        <div class="kpi"><div class="kpi-val">{{ summary.kpi.active_units }}</div><div class="kpi-lbl">Unit Aktif</div></div>
      </div>

      <div v-if="summaryLoading" class="lap-state">Memuat…</div>
      <div v-else-if="!hasSummary" class="lap-state">Belum ada data pemesanan/retur pada periode ini.</div>
      <div v-else class="chart-grid">
        <div class="chart-card">
          <h3>Komposisi Dikirim per Jenis</h3>
          <DoughnutChart :data="donutData" height="240px" />
        </div>
        <div class="chart-card">
          <h3>Konsumsi Bersih per Unit</h3>
          <BarChart :data="stationData" height="240px" />
        </div>
        <div class="chart-card wide">
          <h3>Tren Pengiriman</h3>
          <LineChart :data="trendData" height="240px" />
        </div>
        <div class="chart-card wide">
          <h3>Top 10 Barang Dikirim</h3>
          <BarChart :data="topData" :options="topOptions" height="300px" />
        </div>
      </div>
    </div>

    <!-- ════════════ PEMESANAN ════════════ -->
    <div v-else-if="tab === 'pemesanan'">
      <div class="lap-toolbar">
        <select v-model="pmFilter.station"><option value="">Semua Unit</option><option v-for="s in STATIONS" :key="s" :value="s">{{ s }}</option></select>
        <select v-model="pmFilter.item_type"><option value="">Semua Jenis</option><option v-for="t in TYPES" :key="t.v" :value="t.v">{{ t.l }}</option></select>
        <input v-model="pmFilter.search" placeholder="Cari no. permintaan…" @keyup.enter="loadPemesanan(1)" />
        <button class="btn-apply" @click="loadPemesanan(1)">Terapkan</button>
        <span class="spacer" />
        <button class="btn-exp" @click="exportData('pemesanan','csv')">CSV</button>
        <button class="btn-exp" @click="exportData('pemesanan','xlsx')">Excel</button>
      </div>
      <div class="tbl-wrap">
        <table class="lap-table">
          <thead><tr>
            <th>No. Permintaan</th><th>Tanggal</th><th>Unit</th><th>Jenis</th><th>Kode</th><th>Barang</th>
            <th class="r">Diminta</th><th class="r">Dikirim</th><th>Status</th><th>Batch</th><th>Exp</th>
          </tr></thead>
          <tbody>
            <tr v-if="pmLoading"><td colspan="11" class="lap-state">Memuat…</td></tr>
            <tr v-else-if="!pmRows.length"><td colspan="11" class="lap-state">Tidak ada data.</td></tr>
            <tr v-for="(r, i) in pmRows" :key="i">
              <td><strong>{{ r.request_number }}</strong></td><td>{{ r.date }}</td><td>{{ r.station }}</td>
              <td>{{ r.type }}</td><td class="mono">{{ r.code || '—' }}</td><td>{{ r.name }}</td>
              <td class="r">{{ fmtQty(r.qty_requested) }}</td><td class="r">{{ fmtQty(r.qty_delivered) }}</td>
              <td><span class="badge" :class="'st-' + r.status.toLowerCase()">{{ r.status }}</span></td>
              <td class="mono">{{ r.batch_no || '—' }}</td><td>{{ r.expiry_date || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :page="pmMeta.current_page" :last-page="pmMeta.last_page" :total="pmMeta.total" @change="loadPemesanan" />
    </div>

    <!-- ════════════ RETUR ════════════ -->
    <div v-else>
      <div class="lap-toolbar">
        <select v-model="rtFilter.station"><option value="">Semua Unit</option><option v-for="s in STATIONS" :key="s" :value="s">{{ s }}</option></select>
        <select v-model="rtFilter.item_type"><option value="">Semua Jenis</option><option v-for="t in TYPES" :key="t.v" :value="t.v">{{ t.l }}</option></select>
        <select v-model="rtFilter.condition"><option value="">Semua Kondisi</option><option v-for="c in CONDITIONS" :key="c.v" :value="c.v">{{ c.l }}</option></select>
        <input v-model="rtFilter.search" placeholder="Cari no. retur…" @keyup.enter="loadRetur(1)" />
        <button class="btn-apply" @click="loadRetur(1)">Terapkan</button>
        <span class="spacer" />
        <button class="btn-exp" @click="exportData('retur','csv')">CSV</button>
        <button class="btn-exp" @click="exportData('retur','xlsx')">Excel</button>
      </div>
      <div class="tbl-wrap">
        <table class="lap-table">
          <thead><tr>
            <th>No. Retur</th><th>Tanggal</th><th>Unit</th><th>Jenis</th><th>Kode</th><th>Barang</th>
            <th class="r">Qty Retur</th><th>Kondisi</th><th>Alasan</th><th>Status</th><th>Batch</th>
          </tr></thead>
          <tbody>
            <tr v-if="rtLoading"><td colspan="11" class="lap-state">Memuat…</td></tr>
            <tr v-else-if="!rtRows.length"><td colspan="11" class="lap-state">Tidak ada data.</td></tr>
            <tr v-for="(r, i) in rtRows" :key="i" :class="{ 'row-waste': r.is_waste }">
              <td><strong>{{ r.return_number }}</strong></td><td>{{ r.date }}</td><td>{{ r.station }}</td>
              <td>{{ r.type }}</td><td class="mono">{{ r.code || '—' }}</td><td>{{ r.name }}</td>
              <td class="r">{{ fmtQty(r.qty_returned) }}</td>
              <td><span class="badge" :class="r.is_waste ? 'cond-waste' : 'cond-good'">{{ condLabel(r.condition) }}</span></td>
              <td>{{ r.reason || '—' }}</td>
              <td><span class="badge" :class="'st-' + r.status.toLowerCase()">{{ r.status }}</span></td>
              <td class="mono">{{ r.batch_no || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :page="rtMeta.current_page" :last-page="rtMeta.last_page" :total="rtMeta.total" @change="loadRetur" />
    </div>

    <Teleport to="body">
      <div v-if="toastMsg" class="lap-toast" :class="`t-${toastType}`">{{ toastMsg }}</div>
    </Teleport>
  </div>
</template>

<style scoped>
.lap { display: flex; flex-direction: column; gap: 1rem; }
.lap-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.lap-head h2 { font-family: 'Space Grotesk', serif; font-size: 20px; color: var(--td); margin: 0; }
.lap-sub { font-size: 12.5px; color: var(--tm); margin: 3px 0 0; }
.lap-period { display: flex; align-items: flex-end; gap: 8px; }
.lap-period label { font-size: 11px; color: var(--tu); font-weight: 600; display: flex; flex-direction: column; gap: 3px; }
.lap-period input { height: 32px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 8px; font-size: 12.5px; background: var(--bs); color: var(--td); }

.btn-apply { height: 32px; padding: 0 14px; background: var(--gd); color: #fff; border: none; border-radius: 7px; font-size: 12.5px; font-weight: 600; cursor: pointer; }
.btn-apply:hover { background: var(--gm); }

.lap-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); }
.lap-tab { padding: 8px 16px; font-size: 13px; font-weight: 500; color: var(--tu); background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -1px; cursor: pointer; }
.lap-tab:hover { color: var(--td); }
.lap-tab.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }

.kpi-row { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; }
@media (max-width: 1100px) { .kpi-row { grid-template-columns: repeat(3, 1fr); } }
.kpi { background: var(--bc); border: 1px solid var(--gb); border-radius: 10px; padding: 12px 14px; }
.kpi-val { font-size: 22px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.kpi-lbl { font-size: 11px; color: var(--tm); margin-top: 2px; }
.kpi.accent { background: var(--gl); border-color: var(--ga); }
.kpi.accent .kpi-val { color: var(--gd); }
.kpi.waste .kpi-val { color: #dc2626; }

.chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 14px; }
@media (max-width: 900px) { .chart-grid { grid-template-columns: 1fr; } }
.chart-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 14px 16px; }
.chart-card.wide { grid-column: 1 / -1; }
.chart-card h3 { font-size: 13px; color: var(--td); margin: 0 0 10px; font-weight: 600; }

.lap-toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.lap-toolbar select, .lap-toolbar input { height: 32px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 8px; font-size: 12.5px; background: var(--bs); color: var(--td); }
.lap-toolbar input { min-width: 180px; }
.spacer { flex: 1; }
.btn-exp { height: 32px; padding: 0 12px; background: var(--bc); color: var(--gd); border: 1.5px solid var(--gb); border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer; }
.btn-exp:hover { border-color: var(--ga); color: var(--ga); }

.tbl-wrap { overflow-x: auto; border: 1px solid var(--gb); border-radius: 10px; margin-top: 12px; }
.lap-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.lap-table th, .lap-table td { padding: 8px 10px; text-align: left; border-bottom: 1px solid var(--gb); white-space: nowrap; }
.lap-table th { background: var(--bs); font-weight: 600; color: var(--tm); font-size: 11px; text-transform: uppercase; letter-spacing: .03em; position: sticky; top: 0; }
.lap-table td.r, .lap-table th.r { text-align: right; font-variant-numeric: tabular-nums; }
.lap-table .mono { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; color: var(--tm); }
.lap-table tr:hover td { background: var(--bs); }
.row-waste td { background: #fef2f2 !important; }
.lap-state { text-align: center; padding: 26px; color: var(--tu); }

.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 700; }
.st-delivered { background: #ede9fe; color: #5b21b6; }
.st-closed, .st-received { background: var(--sb); color: var(--st); }
.st-approved { background: #dbeafe; color: #1e40af; }
.st-submitted { background: #fef3c7; color: #92400e; }
.st-rejected { background: var(--eb); color: var(--et); }
.cond-good { background: var(--sb); color: var(--st); }
.cond-waste { background: var(--eb); color: var(--et); }

.lap-toast { position: fixed; bottom: 22px; right: 22px; z-index: 2000; padding: 11px 16px; border-radius: 9px; font-size: 13px; font-weight: 500; color: #fff; box-shadow: 0 8px 24px rgba(0,0,0,.22); }
.t-w { background: #dc2626; } .t-s { background: #16a34a; } .t-i { background: #334155; }
</style>
