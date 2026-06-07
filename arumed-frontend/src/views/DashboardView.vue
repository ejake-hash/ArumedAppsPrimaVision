<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { dashboardApi } from '@/services/api'

// ─── CLOCK ───────────────────────────────────────────────────────────────────
const lastUpdated = ref('')
const animReady   = ref(false)
let   liveTimer   = null

function tick() {
  const n = new Date()
  lastUpdated.value = [n.getHours(), n.getMinutes(), n.getSeconds()]
    .map((x) => String(x).padStart(2, '0')).join(':')
}
tick()

const days   = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu']
const months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']
const dateStr = computed(() => {
  const n = new Date()
  return `${days[n.getDay()]}, ${n.getDate()} ${months[n.getMonth()]} ${n.getFullYear()}`
})

// ─── DISTRIBUSI PENJAMIN (real — GET /dashboard/distribusi-penjamin) ─────────
const distribusiFilter = ref('hari')
const distData         = ref({ bpjs: 0, umum: 0, lain: 0, total: 0 })
const distLoading      = ref(false)

async function fetchDistribusi() {
  distLoading.value = true
  try {
    const { data } = await dashboardApi.distribusiPenjamin(distribusiFilter.value)
    const d = data.data ?? {}
    distData.value = { bpjs: d.bpjs ?? 0, umum: d.umum ?? 0, lain: d.lain ?? 0, total: d.total ?? 0 }
  } catch {
    distData.value = { bpjs: 0, umum: 0, lain: 0, total: 0 }
  } finally {
    distLoading.value = false
  }
}
watch(distribusiFilter, fetchDistribusi)

const distBpjs    = computed(() => distData.value.bpjs)
const distUmum    = computed(() => distData.value.umum)
const distAsn     = computed(() => distData.value.lain)
const distTotal   = computed(() => distData.value.total || 1)   // pembagi pct (min 1)
const distBpjsPct = computed(() => Math.round((distBpjs.value / distTotal.value) * 100))
const distUmumPct = computed(() => Math.round((distUmum.value / distTotal.value) * 100))
const distAsnPct  = computed(() => Math.max(0, 100 - distBpjsPct.value - distUmumPct.value))
const distDonut   = computed(() => {
  const b = distBpjsPct.value, u = distUmumPct.value
  // Navy (BPJS) → Cyan (Umum) → Slate (Asuransi/Lain) — selaras palet.
  return `conic-gradient(var(--gd) 0% ${b}%, var(--ga) ${b}% ${b+u}%, #94a3b8 ${b+u}% 100%)`
})

// ─── FORMAT HELPERS ───────────────────────────────────────────────────────────
function fmtRpBig(v) {
  if (v >= 1000000000) return 'Rp ' + (v/1000000000).toFixed(2) + ' M'
  if (v >= 1000000)    return 'Rp ' + (v/1000000).toFixed(2) + ' jt'
  return 'Rp ' + v.toLocaleString('id-ID')
}

// ─── 7-DAY TREND (real — kunjungan total & pendapatan kas) ───────────────────
const visitTrend   = ref([])   // [{ day, total }]
const revenueTrend = ref([])   // [{ day, amount }]
const dayAbbr      = ['Min','Sen','Sel','Rab','Kam','Jum','Sab']

// Label hari: entri terakhir = "Hari ini", lainnya = singkatan hari dari tanggal.
function trendDayLabel(dateStr, isLast) {
  if (isLast) return 'Hari ini'
  const d = new Date(dateStr)
  return isNaN(d) ? '' : dayAbbr[d.getDay()]
}

async function fetchVisitTrend() {
  try {
    const { data } = await dashboardApi.kunjunganChart()
    const arr = data.data ?? []
    visitTrend.value = arr.map((d, i) => ({ day: trendDayLabel(d.date, i === arr.length - 1), total: Number(d.total) || 0 }))
  } catch { visitTrend.value = [] }
}
async function fetchRevenueTrend() {
  try {
    const { data } = await dashboardApi.pendapatanChart()
    const arr = data.data ?? []
    revenueTrend.value = arr.map((d, i) => ({ day: trendDayLabel(d.date, i === arr.length - 1), amount: Number(d.amount) || 0 }))
  } catch { revenueTrend.value = [] }
}

const maxVisit   = computed(() => Math.max(1, ...visitTrend.value.map(d => d.total)))
const maxRevenue = computed(() => Math.max(1, ...revenueTrend.value.map(d => d.amount)))
const lastIdx    = computed(() => visitTrend.value.length - 1)
function barX(i)          { return i * 44 + 7 }
function visitBarH(total)  { return (total / maxVisit.value) * 68 }
function visitBarY(total)  { return 78 - visitBarH(total) }
function revBarH(amount)   { return (amount / maxRevenue.value) * 68 }
function revBarY(amount)   { return 78 - revBarH(amount) }
const visitLinePoints = computed(() => visitTrend.value.map((d,i) => `${barX(i)+15},${visitBarY(d.total)}`).join(' '))
const revLinePoints   = computed(() => revenueTrend.value.map((d,i) => `${barX(i)+15},${revBarY(d.amount)}`).join(' '))

// Ringkasan footer chart — dihitung dari data riil.
const visitPeak  = computed(() => visitTrend.value.reduce((a, b) => b.total > a.total ? b : a, { day: '—', total: 0 }))
const visitAvg   = computed(() => visitTrend.value.length ? Math.round(visitTrend.value.reduce((a, b) => a + b.total, 0) / visitTrend.value.length) : 0)
const revenueTotal = computed(() => revenueTrend.value.reduce((a, b) => a + b.amount, 0))

// ─── FARMASI STOK ─────────────────────────────────────────────────────────────
const criticalStock = ref([
  { name:'Bevacizumab 1.25mg',   stok:3,  min:5,  max:15, unit:'vial', status:'critical' },
  { name:'Moxifloxacin 0.5% ED', stok:6,  min:8,  max:25, unit:'btl',  status:'low'      },
  { name:'Latanoprost 0.005% ED',stok:8,  min:10, max:20, unit:'btl',  status:'low'      },
  { name:'Timolol 0.5% ED',      stok:15, min:12, max:30, unit:'btl',  status:'ok'       },
  { name:'Tropikamid 1% ED',     stok:12, min:8,  max:25, unit:'btl',  status:'ok'       },
])

// ─── JAM TERSIBUK (real — GET /dashboard/jam-tersibuk, rata-rata N hari) ─────
const BUSIEST_DAYS  = 30
const busiestHours  = ref([])   // [{ hour, label, avg }] jam 08..19
const busiestDays   = ref(0)    // jumlah hari operasional (pembagi rata-rata)
const maxHour       = computed(() => Math.max(1, ...busiestHours.value.map(h => h.avg)))

async function fetchJamTersibuk() {
  try {
    const { data } = await dashboardApi.jamTersibuk(BUSIEST_DAYS)
    const d = data.data ?? {}
    busiestHours.value = d.hours ?? []
    busiestDays.value  = d.operating_days ?? 0
  } catch {
    busiestHours.value = []
    busiestDays.value  = 0
  }
}

// ─── LAPORAN KEUANGAN ─────────────────────────────────────────────────────────
const keuanganFilter = ref('hari')
const keuanganData = {
  hari:  { pendapatan:5120000,    transaksi:47,
    breakdown:[ {label:'BPJS/JKN',val:2890000,pct:56,color:'var(--ga)'},{label:'Umum',val:1650000,pct:32,color:'var(--lm)'},{label:'ASN/Asuransi',val:580000,pct:11,color:'#7e22ce'} ],
    kategori: [ {label:'Konsultasi',val:1850000,pct:36},{label:'Tindakan',val:1920000,pct:38},{label:'Obat',val:810000,pct:16},{label:'Penunjang',val:540000,pct:11} ],
  },
  minggu:{ pendapatan:32480000,   transaksi:298,
    breakdown:[ {label:'BPJS/JKN',val:18190000,pct:56,color:'var(--ga)'},{label:'Umum',val:10390000,pct:32,color:'var(--lm)'},{label:'ASN/Asuransi',val:3900000,pct:12,color:'#7e22ce'} ],
    kategori: [ {label:'Konsultasi',val:11690000,pct:36},{label:'Tindakan',val:12340000,pct:38},{label:'Obat',val:5200000,pct:16},{label:'Penunjang',val:3250000,pct:10} ],
  },
  bulan: { pendapatan:143600000,  transaksi:1284,
    breakdown:[ {label:'BPJS/JKN',val:80420000,pct:56,color:'var(--ga)'},{label:'Umum',val:45950000,pct:32,color:'var(--lm)'},{label:'ASN/Asuransi',val:17230000,pct:12,color:'#7e22ce'} ],
    kategori: [ {label:'Konsultasi',val:51700000,pct:36},{label:'Tindakan',val:54570000,pct:38},{label:'Obat',val:22980000,pct:16},{label:'Penunjang',val:14350000,pct:10} ],
  },
  tahun: { pendapatan:1724000000, transaksi:15408,
    breakdown:[ {label:'BPJS/JKN',val:965440000,pct:56,color:'var(--ga)'},{label:'Umum',val:551680000,pct:32,color:'var(--lm)'},{label:'ASN/Asuransi',val:206880000,pct:12,color:'#7e22ce'} ],
    kategori: [ {label:'Konsultasi',val:620640000,pct:36},{label:'Tindakan',val:655120000,pct:38},{label:'Obat',val:275840000,pct:16},{label:'Penunjang',val:172400000,pct:10} ],
  },
}
const activeKeu = computed(() => keuanganData[keuanganFilter.value])

// ─── LIFECYCLE ────────────────────────────────────────────────────────────────
onMounted(() => {
  liveTimer = setInterval(tick, 5000)
  setTimeout(() => { animReady.value = true }, 100)
  fetchDistribusi()
  fetchJamTersibuk()
  fetchVisitTrend()
  fetchRevenueTrend()
})
onUnmounted(() => clearInterval(liveTimer))
</script>

<template>
  <div class="dashboard">

    <!-- Definisi gradien SVG bersama (dipakai kedua chart tren, selalu ada
         walau salah satu chart kosong) -->
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
      <defs>
        <linearGradient id="bgV" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--ga)"/><stop offset="100%" stop-color="var(--gl)"/></linearGradient>
        <linearGradient id="bgN" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--gd)"/><stop offset="100%" stop-color="var(--gm)"/></linearGradient>
      </defs>
    </svg>

    <!-- ─── WELCOME BAR ─── -->
    <div class="welcome-bar">
      <div class="wb-left">
        <div class="wb-title">Dashboard RUMAH SAKIT MATA PRIMA VISION</div>
        <div class="wb-date">{{ dateStr }}</div>
      </div>
      <div class="wb-right">
        <div class="live-pill"><span class="live-dot"></span>LIVE</div>
        <div class="wb-updated">Diperbarui pukul {{ lastUpdated }}</div>
      </div>
    </div>

    <!-- ─── ROW: DISTRIBUSI + LAPORAN KEUANGAN ─── -->
    <div class="row-keu">
      <div class="card">
        <div class="ch">
          <div class="cht"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Distribusi Penjamin</div>
          <div class="d-filter-tabs">
            <button v-for="f in [{k:'hari',l:'Hari Ini'},{k:'minggu',l:'Minggu'},{k:'bulan',l:'Bulan'},{k:'tahun',l:'Tahun'}]" :key="f.k"
              :class="['d-ftab', distribusiFilter===f.k && 'd-ftab-a']"
              @click="distribusiFilter = f.k">{{ f.l }}</button>
          </div>
        </div>
        <div class="cb donut-section">
          <div class="donut-wrap">
            <div class="donut" :style="{ background: distDonut }">
              <div class="donut-hole">
                <div class="donut-center-val">{{ distData.total }}</div>
                <div class="donut-center-lbl">kunjungan</div>
              </div>
            </div>
          </div>
          <div class="donut-legend">
            <div class="dl-row"><span class="dl-dot" style="background:var(--gd)"></span><span class="dl-name">BPJS/JKN</span><span class="dl-val">{{ distBpjs }} ({{ distBpjsPct }}%)</span></div>
            <div class="dl-row"><span class="dl-dot" style="background:var(--ga)"></span><span class="dl-name">Umum</span><span class="dl-val">{{ distUmum }} ({{ distUmumPct }}%)</span></div>
            <div class="dl-row"><span class="dl-dot" style="background:#94a3b8"></span><span class="dl-name">Asuransi/Lain</span><span class="dl-val">{{ distAsn }} ({{ distAsnPct }}%)</span></div>
          </div>
        </div>
        <div class="divider-light"></div>
        <div class="cb">
          <div class="section-label">Jam Tersibuk · rata-rata {{ busiestDays }} hari (08.00–19.00)</div>
          <div v-if="!busiestHours.length" class="sparkline-empty">Belum ada data kunjungan</div>
          <div v-else class="sparkline">
            <div v-for="h in busiestHours" :key="h.hour" class="spark-col">
              <div class="spark-bar"
                   :class="h.avg === maxHour ? 'peak' : h.avg >= maxHour * 0.6 ? 'hi' : ''"
                   :style="{ height: Math.max(4, (h.avg / maxHour) * 44) + 'px' }"
                   :title="`${h.label}.00 — rata-rata ${h.avg} kunjungan/hari`"></div>
              <div class="spark-lbl">{{ h.label }}</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="ch">
          <div class="cht"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Laporan Keuangan <span class="badge-soon-dash">Segera Hadir</span></div>
          <div class="d-filter-tabs">
            <button v-for="f in [{k:'hari',l:'Hari Ini'},{k:'minggu',l:'Minggu'},{k:'bulan',l:'Bulan'},{k:'tahun',l:'Tahun'}]" :key="f.k"
              :class="['d-ftab', keuanganFilter===f.k && 'd-ftab-a']" disabled
              @click="keuanganFilter = f.k">{{ f.l }}</button>
          </div>
        </div>
        <!-- Laporan keuangan belum di-wire ke database (angka di bawah masih contoh). -->
        <!-- Ditandai "Segera Hadir" + di-blur supaya tidak dianggap data riil saat go-live. -->
        <div class="cb keu-soon-wrap">
          <div class="keu-soon-overlay">
            <div class="keu-soon-box">
              <svg viewBox="0 0 24 24" width="22" height="22"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
              <div>Laporan keuangan akan tersedia setelah integrasi data transaksi selesai.</div>
            </div>
          </div>
          <div class="keu-summary">
            <div class="keu-kpi"><div class="keu-val">{{ fmtRpBig(activeKeu.pendapatan) }}</div><div class="keu-lbl">Total Pendapatan</div></div>
            <div class="keu-kpi"><div class="keu-val" style="color:var(--it)">{{ activeKeu.transaksi.toLocaleString('id-ID') }}</div><div class="keu-lbl">Transaksi</div></div>
          </div>
          <div class="section-label" style="margin-top:.6rem">Distribusi Penjamin</div>
          <div v-for="b in activeKeu.breakdown" :key="b.label" class="keu-bar-row">
            <div class="keu-bar-head">
              <span class="keu-bar-dot" :style="{ background: b.color }"></span>
              <span class="keu-bar-name">{{ b.label }}</span>
              <span class="keu-bar-val">{{ fmtRpBig(b.val) }}</span>
              <span class="keu-bar-pct" :style="{ color: b.color }">{{ b.pct }}%</span>
            </div>
            <div class="keu-bar-track"><div class="keu-bar-fill" :style="{ width: animReady ? b.pct+'%' : '0%', background: b.color }"></div></div>
          </div>
          <div class="divider-light" style="margin:.6rem 0"></div>
          <div class="section-label">Kategori Layanan</div>
          <div class="keu-cat-grid">
            <div v-for="k in activeKeu.kategori" :key="k.label" class="keu-cat">
              <div class="keu-cat-pct">{{ k.pct }}%</div>
              <div class="keu-cat-bar-wrap"><div class="keu-cat-bar" :style="{ height: animReady ? k.pct+'%' : '0%' }"></div></div>
              <div class="keu-cat-lbl">{{ k.label }}</div>
              <div class="keu-cat-val">{{ fmtRpBig(k.val) }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── ROW: STOK FARMASI ─── -->
    <div class="row-full">

      <div class="card">
        <div class="ch">
          <div class="cht"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>Stok Farmasi</div>
          <span class="ch-badge warn">{{ criticalStock.filter(s=>s.status!=='ok').length }} kritis</span>
        </div>
        <div class="cb">
          <div v-for="s in criticalStock" :key="s.name" class="stk-row">
            <div class="stk-head">
              <span class="stk-name">{{ s.name }}</span>
              <span :class="['stk-qty', s.status]">{{ s.stok }} {{ s.unit }}</span>
            </div>
            <div class="stk-track">
              <div class="stk-fill" :class="s.status" :style="{ width: (s.stok/s.max*100)+'%' }"></div>
              <div class="stk-min-line" :style="{ left: (s.min/s.max*100)+'%' }"></div>
            </div>
          </div>
          <div class="stk-footer">
            <span class="stk-legend critical">● Habis</span>
            <span class="stk-legend low">● Low</span>
            <span class="stk-legend ok">● Aman</span>
            <span class="stk-legend-line">│ Min</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── ROW: 7-DAY CHARTS ─── -->
    <div class="row-2col">
      <div class="card">
        <div class="ch"><div class="cht"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>Tren Kunjungan 7 Hari</div></div>
        <div class="cb chart-area">
          <div v-if="!visitTrend.length" class="chart-empty">Belum ada data kunjungan</div>
          <svg v-else viewBox="0 0 308 100" class="trend-svg">
            <g v-for="(d,i) in visitTrend" :key="d.day">
              <rect :x="barX(i)" :y="visitBarY(d.total)" :width="30" :height="visitBarH(d.total)" rx="3"
                :fill="i===lastIdx ? 'url(#bgV)' : 'url(#bgN)'"/>
              <text :x="barX(i)+15" y="93" text-anchor="middle" class="svgt-lbl">{{ d.day==='Hari ini'?'●':d.day }}</text>
              <text :x="barX(i)+15" :y="visitBarY(d.total)-3" text-anchor="middle" class="svgt-val">{{ d.total }}</text>
            </g>
            <polyline :points="visitLinePoints" class="trend-line"/>
            <circle v-for="(d,i) in visitTrend" :key="'c'+i" :cx="barX(i)+15" :cy="visitBarY(d.total)"
              r="2.5" :fill="i===lastIdx?'var(--ga)':'#fff'" :stroke="i===lastIdx?'var(--gd)':'var(--gm)'" stroke-width="1.5"/>
          </svg>
          <div class="chart-footer"><span>Puncak {{ visitPeak.day }} ({{ visitPeak.total }}) · Rata-rata {{ visitAvg }}/hari</span></div>
        </div>
      </div>
      <div class="card">
        <div class="ch"><div class="cht"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Tren Pendapatan 7 Hari</div></div>
        <div class="cb chart-area">
          <div v-if="!revenueTrend.length" class="chart-empty">Belum ada data pendapatan</div>
          <svg v-else viewBox="0 0 308 100" class="trend-svg">
            <g v-for="(d,i) in revenueTrend" :key="d.day">
              <rect :x="barX(i)" :y="revBarY(d.amount)" :width="30" :height="revBarH(d.amount)" rx="3"
                :fill="i===lastIdx ? 'url(#bgV)' : 'url(#bgN)'"/>
              <text :x="barX(i)+15" y="93" text-anchor="middle" class="svgt-lbl">{{ d.day==='Hari ini'?'●':d.day }}</text>
              <text :x="barX(i)+15" :y="revBarY(d.amount)-3" text-anchor="middle" class="svgt-val">{{ (d.amount/1000000).toFixed(1) }}jt</text>
            </g>
            <polyline :points="revLinePoints" class="trend-line rev"/>
          </svg>
          <div class="chart-footer"><span>Total 7 hari: {{ fmtRpBig(revenueTotal) }}</span></div>
        </div>
      </div>
    </div>

  </div>

</template>

<style scoped>
.dashboard { display: flex; flex-direction: column; gap: 1rem; }

/* ─── WELCOME BAR ─── */
.welcome-bar { display:flex; align-items:center; justify-content:space-between; gap:1rem;
  background:linear-gradient(120deg,var(--gd) 0%, var(--gm) 60%, #226aa6 100%);
  border-radius:14px; padding:1.1rem 1.5rem; position:relative; overflow:hidden;
  box-shadow:0 6px 22px rgba(14,58,102,.18); }
/* aksen lingkaran sky di sudut kanan, halus */
.welcome-bar::after { content:''; position:absolute; right:-40px; top:-60px; width:180px; height:180px;
  border-radius:50%; background:radial-gradient(circle, rgba(31,170,224,.35), transparent 70%); pointer-events:none; }
.wb-title { font-family:'Space Grotesk',sans-serif; font-size:20px; font-weight:700; color:#fff; line-height:1.15; letter-spacing:.01em; position:relative; }
.wb-date  { font-size:11.5px; color:rgba(255,255,255,.6); margin-top:4px; position:relative; }
.wb-right { display:flex; align-items:center; gap:.75rem; position:relative; }
.wb-updated { font-size:11px; color:rgba(255,255,255,.55); font-variant-numeric:tabular-nums; }
.live-pill { display:inline-flex; align-items:center; gap:5px; background:rgba(31,170,224,.22); border:1px solid rgba(31,170,224,.5); color:#7fd6f5; font-size:9px; font-weight:700; padding:4px 10px; border-radius:20px; letter-spacing:.08em; }
.live-dot  { width:6px; height:6px; border-radius:50%; background:var(--ga); animation:blink 1.5s infinite; flex-shrink:0; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.25} }

/* ─── GRID ROWS ─── */
.row-2col  { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.row-keu   { display:grid; grid-template-columns:2fr 3fr; gap:1rem; }
.row-full  { width:100%; }

/* ─── CARD BASE ─── */
.card { background:var(--bc); border:1px solid var(--gb); border-radius:14px; overflow:hidden;
  box-shadow:0 1px 2px rgba(14,58,102,.04), 0 4px 16px rgba(14,58,102,.05);
  transition:box-shadow .2s ease, transform .2s ease; }
.card:hover { box-shadow:0 2px 8px rgba(14,58,102,.08), 0 10px 30px rgba(14,58,102,.09); transform:translateY(-1px); }
.ch { padding:.7rem 1rem; border-bottom:1px solid var(--gb); display:flex; align-items:center; justify-content:space-between; gap:.4rem; }
.cht { font-size:12.5px; font-weight:700; color:var(--td); display:flex; align-items:center; gap:6px; }
.cht svg { width:14px; height:14px; fill:none; stroke:var(--ga); stroke-width:2; stroke-linecap:round; }
.cb { padding:.85rem 1rem; }
.ch-badge { font-size:9px; font-weight:700; padding:2px 8px; border-radius:20px; }
.ch-badge.warn { background:var(--eb); color:var(--et); border:1px solid var(--ebd); }
.divider-light { height:1px; background:var(--gb); margin:.5rem .9rem; }
.section-label { font-size:9.5px; font-weight:600; color:var(--tu); text-transform:uppercase; letter-spacing:.06em; margin-bottom:.4rem; }
.d-filter-tabs { display:flex; gap:3px; }
.d-ftab { padding:2px 8px; font-size:9.5px; font-weight:600; border:1px solid var(--gb); border-radius:6px; background:var(--bs); color:var(--tu); cursor:pointer; transition:all .15s; }
.d-ftab:hover { border-color:var(--ga); color: var(--td); }
.d-ftab-a { background:var(--ga); color:#fff; border-color:var(--ga); }

/* ─── DONUT ─── */
.donut-section { display:flex; align-items:center; gap:1.25rem; }
.donut-wrap { flex-shrink:0; }
.donut { width:128px; height:128px; border-radius:50%; position:relative; box-shadow:0 4px 14px rgba(14,58,102,.12); }
.donut-hole { position:absolute; inset:26px; border-radius:50%; background:var(--bc); display:flex; flex-direction:column; align-items:center; justify-content:center; box-shadow:inset 0 1px 3px rgba(14,58,102,.06); }
.donut-center-val { font-size:24px; font-weight:800; color:var(--gd); line-height:1; }
.donut-center-lbl { font-size:9px; color:var(--tu); margin-top:1px; }
.donut-legend { flex:1; display:flex; flex-direction:column; gap:.55rem; }
.dl-row { display:flex; align-items:center; gap:.5rem; font-size:12px; }
.dl-dot { width:10px; height:10px; border-radius:3px; flex-shrink:0; }
.dl-name { flex:1; color:var(--tm); }
.dl-val { font-weight:600; color:var(--td); font-variant-numeric:tabular-nums; }

/* ─── SPARKLINE ─── */
.sparkline-empty { height:52px; display:flex; align-items:center; justify-content:center; font-size:11px; color:var(--tu); }
.sparkline { display:grid; grid-template-columns:repeat(12,1fr); gap:2px; align-items:flex-end; height:52px; }
.spark-col { display:flex; flex-direction:column; align-items:center; justify-content:flex-end; gap:2px; }
.spark-bar { width:100%; background:#dbe6f0; border-radius:3px 3px 0 0; min-height:4px; transition:height .4s ease; }
.spark-bar.hi   { background:var(--gd); }
.spark-bar.peak { background:var(--ga); }
.spark-lbl { font-size:7.5px; color:var(--th); }

/* ─── STOCK ─── */
.stk-row { margin-bottom:.55rem; }
.stk-head { display:flex; justify-content:space-between; margin-bottom:3px; }
.stk-name { font-size:11px; color:var(--td); font-weight:500; }
.stk-qty  { font-size:10.5px; font-weight:700; }
.stk-qty.critical { color:var(--et); }
.stk-qty.low      { color:var(--wt); }
.stk-qty.ok       { color:var(--st); }
.stk-track    { height:7px; background:var(--bs); border-radius:4px; overflow:visible; position:relative; border:1px solid var(--gb); }
.stk-fill     { height:100%; border-radius:4px; transition:width .6s ease; }
.stk-fill.critical { background:var(--et); }
.stk-fill.low      { background:var(--wt); }
.stk-fill.ok       { background:var(--st); }
.stk-min-line { position:absolute; top:-3px; bottom:-3px; width:1.5px; background:rgba(0,0,0,.3); }
.stk-footer   { display:flex; gap:.75rem; margin-top:.4rem; flex-wrap:wrap; }
.stk-legend, .stk-legend-line { font-size:9px; font-weight:600; }
.stk-legend.critical { color:var(--et); }
.stk-legend.low      { color:var(--wt); }
.stk-legend.ok       { color:var(--st); }
.stk-legend-line     { color:var(--tm); }

/* ─── CHARTS ─── */
.chart-area { padding:.65rem .9rem .4rem; }
.chart-empty { height:100px; display:flex; align-items:center; justify-content:center; font-size:11px; color:var(--tu); }
.trend-svg  { width:100%; overflow:visible; }
.svgt-lbl   { font-size:7.5px; fill:var(--tu); font-family:'Inter',sans-serif; }
.svgt-val   { font-size:7px; fill:var(--td); font-family:'Inter',sans-serif; font-weight:600; }
.trend-line { fill:none; stroke:var(--ga); stroke-width:1.5; opacity:.55; }
.trend-line.rev { stroke:var(--gd); }
.chart-footer { font-size:10px; color:var(--tu); margin-top:.35rem; }

/* ─── LAPORAN KEUANGAN ─── */
/* Badge "Segera Hadir" pada judul kartu (palet kuning konsisten dgn AdmisiView). */
.badge-soon-dash {
  display:inline-flex; align-items:center; font-size:9px; font-weight:700;
  padding:2px 7px; border-radius:20px; letter-spacing:.04em; text-transform:uppercase;
  background:rgba(251,191,36,.16); color:#b45309; border:1px solid rgba(251,191,36,.4);
  margin-left:6px; vertical-align:middle;
}
/* Overlay "segera hadir" untuk Laporan Keuangan: body mock di-blur, tak bisa diklik. */
.keu-soon-wrap { position:relative; }
.keu-soon-wrap > :not(.keu-soon-overlay) { filter:blur(3px); opacity:.55; pointer-events:none; user-select:none; }
.keu-soon-overlay {
  position:absolute; inset:0; z-index:2; display:flex; align-items:center; justify-content:center;
  background:linear-gradient(180deg, rgba(255,255,255,.35), rgba(255,255,255,.65)); border-radius:8px;
}
.keu-soon-box {
  display:flex; align-items:center; gap:.55rem; max-width:80%; text-align:left;
  background:var(--bc); border:1px solid var(--gb); border-radius:10px; padding:.7rem .9rem;
  box-shadow:0 4px 14px rgba(0,0,0,.08); font-size:12.5px; color:var(--td); font-weight:600;
}
.keu-soon-box svg { flex:none; stroke:#b45309; fill:none; stroke-width:2; }
.keu-summary { display:flex; gap:1.5rem; margin-bottom:.4rem; }
.keu-kpi { display:flex; flex-direction:column; gap:2px; }
.keu-val  { font-size:20px; font-weight:700; color:var(--td); line-height:1; }
.keu-lbl  { font-size:9.5px; color:var(--tu); }
.keu-bar-row { margin-bottom:.45rem; }
.keu-bar-head { display:flex; align-items:center; gap:.4rem; margin-bottom:3px; }
.keu-bar-dot  { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.keu-bar-name { font-size:11.5px; color:var(--tm); flex:1; }
.keu-bar-val  { font-size:11px; font-weight:600; color:var(--td); }
.keu-bar-pct  { font-size:10px; font-weight:700; min-width:28px; text-align:right; }
.keu-bar-track { height:6px; background:var(--bs); border-radius:3px; overflow:hidden; }
.keu-bar-fill  { height:100%; border-radius:3px; transition:width .6s cubic-bezier(.22,1,.36,1); }
.keu-cat-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:.5rem; align-items:end; }
.keu-cat { display:flex; flex-direction:column; align-items:center; gap:3px; }
.keu-cat-pct { font-size:10px; font-weight:700; color: var(--td); }
.keu-cat-bar-wrap { width:100%; height:40px; background:var(--bs); border-radius:4px; display:flex; align-items:flex-end; overflow:hidden; }
.keu-cat-bar { width:100%; background:linear-gradient(to top,var(--ga),var(--lm)); border-radius:4px; transition:height .6s ease; }
.keu-cat-lbl { font-size:9.5px; font-weight:600; color:var(--tu); text-align:center; }
.keu-cat-val { font-size:9px; color:var(--th); text-align:center; }

</style>
