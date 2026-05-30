<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useVisitStore } from '@/stores/visitStore'

const visitStore = useVisitStore()

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

// ─── VISIT COUNTS (for KPI + Distribusi) ─────────────────────────────────────
const allVisits     = computed(() => visitStore.visits ?? [])
const totalToday    = computed(() => allVisits.value.length    || 47)
const totalBpjs     = computed(() => allVisits.value.filter(v => v.guarantor_type === 'BPJS').length  || 31)
const totalUmum     = computed(() => allVisits.value.filter(v => v.guarantor_type === 'UMUM').length  || 12)
const totalAsuransi = computed(() => allVisits.value.filter(v => !['BPJS','UMUM'].includes(v.guarantor_type)).length || 4)

// ─── DISTRIBUSI PENJAMIN ─────────────────────────────────────────────────────
const distribusiFilter = ref('hari')
const distribusiRaw = {
  hari:   { bpjs: null, umum: null, asn: null },
  minggu: { bpjs: 187,  umum: 73,   asn: 21   },
  bulan:  { bpjs: 812,  umum: 318,  asn: 94   },
  tahun:  { bpjs: 9840, umum: 3920, asn: 1180 },
}
const distBpjs    = computed(() => distribusiFilter.value === 'hari' ? totalBpjs.value     : distribusiRaw[distribusiFilter.value].bpjs)
const distUmum    = computed(() => distribusiFilter.value === 'hari' ? totalUmum.value     : distribusiRaw[distribusiFilter.value].umum)
const distAsn     = computed(() => distribusiFilter.value === 'hari' ? totalAsuransi.value : distribusiRaw[distribusiFilter.value].asn)
const distTotal   = computed(() => distBpjs.value + distUmum.value + distAsn.value || 1)
const distBpjsPct = computed(() => Math.round((distBpjs.value / distTotal.value) * 100))
const distUmumPct = computed(() => Math.round((distUmum.value / distTotal.value) * 100))
const distAsnPct  = computed(() => 100 - distBpjsPct.value - distUmumPct.value)
const distDonut   = computed(() => {
  const b = distBpjsPct.value, u = distUmumPct.value
  return `conic-gradient(#3b82f6 0% ${b}%, var(--lm) ${b}% ${b+u}%, var(--pt) ${b+u}% 100%)`
})

// ─── FORMAT HELPERS ───────────────────────────────────────────────────────────
function fmtRp(v)    { return v >= 1000000 ? 'Rp ' + (v/1000000).toFixed(2) + ' jt' : 'Rp ' + v.toLocaleString('id-ID') }
function fmtRpShort(v) { return (v/1000000).toFixed(1) + 'jt' }
function fmtRpBig(v) {
  if (v >= 1000000000) return 'Rp ' + (v/1000000000).toFixed(2) + ' M'
  if (v >= 1000000)    return 'Rp ' + (v/1000000).toFixed(2) + ' jt'
  return 'Rp ' + v.toLocaleString('id-ID')
}

// ─── KPI (static estimates) ───────────────────────────────────────────────────
const totalRevenue = ref(5120000)
const farmasiStats = ref({ totalRx: 18, waiting: 3, prepared: 4, done: 11 })

// ─── 7-DAY TREND ─────────────────────────────────────────────────────────────
const visitTrend = ref([
  { day:'Sen', total:42 }, { day:'Sel', total:38 }, { day:'Rab', total:51 },
  { day:'Kam', total:45 }, { day:'Jum', total:39 }, { day:'Sab', total:33 },
  { day:'Hari ini', total:47 },
])
const revenueTrend = ref([
  { day:'Sen', amount:4850000 }, { day:'Sel', amount:4120000 }, { day:'Rab', amount:5680000 },
  { day:'Kam', amount:4950000 }, { day:'Jum', amount:4230000 }, { day:'Sab', amount:3540000 },
  { day:'Hari ini', amount:5120000 },
])
const maxVisit   = computed(() => Math.max(...visitTrend.value.map(d => d.total)))
const maxRevenue = computed(() => Math.max(...revenueTrend.value.map(d => d.amount)))
function barX(i)          { return i * 44 + 7 }
function visitBarH(total)  { return (total / maxVisit.value) * 68 }
function visitBarY(total)  { return 78 - visitBarH(total) }
function revBarH(amount)   { return (amount / maxRevenue.value) * 68 }
function revBarY(amount)   { return 78 - revBarH(amount) }
const visitLinePoints = computed(() => visitTrend.value.map((d,i) => `${barX(i)+15},${visitBarY(d.total)}`).join(' '))
const revLinePoints   = computed(() => revenueTrend.value.map((d,i) => `${barX(i)+15},${revBarY(d.amount)}`).join(' '))

// ─── TOP DIAGNOSES ────────────────────────────────────────────────────────────
const diagnoses = ref([
  { code:'H25.9', name:'Katarak',        count:14, color:'#3b82f6' },
  { code:'H40.1', name:'Glaukoma',       count:11, color:'#dc2626' },
  { code:'H52.1', name:'Miopia',         count:9,  color:'#15803d' },
  { code:'H11.0', name:'Pterigium',      count:7,  color:'#b45309' },
  { code:'H04.1', name:'Dry Eye',        count:5,  color:'#7e22ce' },
  { code:'H10.0', name:'Konjungtivitis', count:3,  color:'#0891b2' },
])
const maxDx = computed(() => diagnoses.value[0].count)

// ─── PENUNJANG ────────────────────────────────────────────────────────────────
const penunjang = ref([
  { type:'OCT',      icon:'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8zM12 9a3 3 0 100 6 3 3 0 000-6z', color:'#3b82f6', bg:'var(--ib)', req:2, prg:1, done:4 },
  { type:'USG',      icon:'M22 12h-4l-3 9L9 3l-3 9H2',                                                    color:'#7e22ce', bg:'var(--pb)', req:1, prg:1, done:2 },
  { type:'Biometri', icon:'M12 2a10 10 0 100 20A10 10 0 0012 2zM2 12h20M12 2a15.3 15.3 0 010 20',         color:'#b45309', bg:'var(--wb)', req:1, prg:0, done:3 },
])

// ─── FARMASI STOK ─────────────────────────────────────────────────────────────
const criticalStock = ref([
  { name:'Bevacizumab 1.25mg',   stok:3,  min:5,  max:15, unit:'vial', status:'critical' },
  { name:'Moxifloxacin 0.5% ED', stok:6,  min:8,  max:25, unit:'btl',  status:'low'      },
  { name:'Latanoprost 0.005% ED',stok:8,  min:10, max:20, unit:'btl',  status:'low'      },
  { name:'Timolol 0.5% ED',      stok:15, min:12, max:30, unit:'btl',  status:'ok'       },
  { name:'Tropikamid 1% ED',     stok:12, min:8,  max:25, unit:'btl',  status:'ok'       },
])

// ─── JAM TERSIBUK ─────────────────────────────────────────────────────────────
const hourBusy   = [1, 2, 4, 8, 15, 22, 18, 14, 10, 7, 5, 8]
const hourLabels = ['08','09','10','11','12','13','14','15','16','17','18','19']
const maxHour    = 22

// ─── LAPORAN KEUANGAN ─────────────────────────────────────────────────────────
const keuanganFilter = ref('hari')
const keuanganData = {
  hari:  { pendapatan:5120000,    transaksi:47,
    breakdown:[ {label:'BPJS/JKN',val:2890000,pct:56,color:'#3b82f6'},{label:'Umum',val:1650000,pct:32,color:'var(--lm)'},{label:'ASN/Asuransi',val:580000,pct:11,color:'#7e22ce'} ],
    kategori: [ {label:'Konsultasi',val:1850000,pct:36},{label:'Tindakan',val:1920000,pct:38},{label:'Obat',val:810000,pct:16},{label:'Penunjang',val:540000,pct:11} ],
  },
  minggu:{ pendapatan:32480000,   transaksi:298,
    breakdown:[ {label:'BPJS/JKN',val:18190000,pct:56,color:'#3b82f6'},{label:'Umum',val:10390000,pct:32,color:'var(--lm)'},{label:'ASN/Asuransi',val:3900000,pct:12,color:'#7e22ce'} ],
    kategori: [ {label:'Konsultasi',val:11690000,pct:36},{label:'Tindakan',val:12340000,pct:38},{label:'Obat',val:5200000,pct:16},{label:'Penunjang',val:3250000,pct:10} ],
  },
  bulan: { pendapatan:143600000,  transaksi:1284,
    breakdown:[ {label:'BPJS/JKN',val:80420000,pct:56,color:'#3b82f6'},{label:'Umum',val:45950000,pct:32,color:'var(--lm)'},{label:'ASN/Asuransi',val:17230000,pct:12,color:'#7e22ce'} ],
    kategori: [ {label:'Konsultasi',val:51700000,pct:36},{label:'Tindakan',val:54570000,pct:38},{label:'Obat',val:22980000,pct:16},{label:'Penunjang',val:14350000,pct:10} ],
  },
  tahun: { pendapatan:1724000000, transaksi:15408,
    breakdown:[ {label:'BPJS/JKN',val:965440000,pct:56,color:'#3b82f6'},{label:'Umum',val:551680000,pct:32,color:'var(--lm)'},{label:'ASN/Asuransi',val:206880000,pct:12,color:'#7e22ce'} ],
    kategori: [ {label:'Konsultasi',val:620640000,pct:36},{label:'Tindakan',val:655120000,pct:38},{label:'Obat',val:275840000,pct:16},{label:'Penunjang',val:172400000,pct:10} ],
  },
}
const activeKeu = computed(() => keuanganData[keuanganFilter.value])

// ─── BPJS STATUS ──────────────────────────────────────────────────────────────
const bpjsServices = ref([
  { name:'VClaim',            status:'online',      resp:'142ms' },
  { name:'Antrean Online',    status:'online',      resp:'210ms' },
  { name:'LUPIS',             status:'online',      resp:'389ms' },
  { name:'Satu Sehat (FHIR)', status:'maintenance', resp:'—'     },
])
const todaySep     = computed(() => totalBpjs.value + 28)
const bpjsCacheAge = ref('2m lalu')

// ─── LIFECYCLE ────────────────────────────────────────────────────────────────
onMounted(() => {
  liveTimer = setInterval(tick, 5000)
  setTimeout(() => { animReady.value = true }, 100)
})
onUnmounted(() => clearInterval(liveTimer))
</script>

<template>
  <div class="dashboard">

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

    <!-- ─── KPI ROW ─── -->
    <div class="kpi-row">
      <div class="kpi-card" style="border-top-color:var(--ga)">
        <div class="kpi-icon" style="background:#ecfdf5">
          <svg viewBox="0 0 24 24" stroke="#15803d"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        </div>
        <div class="kpi-body">
          <div class="kpi-val">{{ totalToday }}</div>
          <div class="kpi-lbl">Total Kunjungan</div>
          <div class="kpi-sub">Hari ini · {{ dateStr.split(',')[0] }}</div>
        </div>
      </div>
      <div class="kpi-card" style="border-top-color:#3b82f6">
        <div class="kpi-icon" style="background:#eff6ff">
          <svg viewBox="0 0 24 24" stroke="#1d4ed8"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <div class="kpi-body">
          <div class="kpi-val" style="color:#1d4ed8">{{ totalBpjs }}</div>
          <div class="kpi-lbl">Pasien BPJS</div>
          <div class="kpi-sub">{{ Math.round(totalBpjs / (totalToday||1) * 100) }}% dari hari ini</div>
        </div>
      </div>
      <div class="kpi-card" style="border-top-color:var(--lm)">
        <div class="kpi-icon" style="background:#f7fde8">
          <svg viewBox="0 0 24 24" stroke="var(--ld)"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        </div>
        <div class="kpi-body">
          <div class="kpi-val" style="color:var(--ld)">{{ totalUmum }}</div>
          <div class="kpi-lbl">Pasien Umum</div>
          <div class="kpi-sub">{{ Math.round(totalUmum / (totalToday||1) * 100) }}% dari hari ini</div>
        </div>
      </div>
      <div class="kpi-card" style="border-top-color:var(--pt)">
        <div class="kpi-icon" style="background:#fdf4ff">
          <svg viewBox="0 0 24 24" stroke="#7e22ce"><path d="M3 3h18v18H3zM3 9h18M9 21V9"/></svg>
        </div>
        <div class="kpi-body">
          <div class="kpi-val" style="color:var(--pt)">{{ totalAsuransi }}</div>
          <div class="kpi-lbl">Asuransi / Lain</div>
          <div class="kpi-sub">Perusahaan, Sosial, dll</div>
        </div>
      </div>
      <div class="kpi-card" style="border-top-color:var(--wt)">
        <div class="kpi-icon" style="background:#fffbeb">
          <svg viewBox="0 0 24 24" stroke="#b45309"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        </div>
        <div class="kpi-body">
          <div class="kpi-val" style="color:var(--wt)">{{ fmtRp(totalRevenue) }}</div>
          <div class="kpi-lbl">Pendapatan Hari Ini</div>
          <div class="kpi-sub trend-up">↑ Est. {{ fmtRpShort(totalRevenue) }} hingga {{ lastUpdated.slice(0,5) }}</div>
        </div>
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
                <div class="donut-center-val">{{ distTotal }}</div>
                <div class="donut-center-lbl">pasien</div>
              </div>
            </div>
          </div>
          <div class="donut-legend">
            <div class="dl-row"><span class="dl-dot" style="background:#3b82f6"></span><span class="dl-name">BPJS/JKN</span><span class="dl-val">{{ distBpjs }} ({{ distBpjsPct }}%)</span></div>
            <div class="dl-row"><span class="dl-dot" style="background:var(--lm)"></span><span class="dl-name">Umum</span><span class="dl-val">{{ distUmum }} ({{ distUmumPct }}%)</span></div>
            <div class="dl-row"><span class="dl-dot" style="background:var(--pt)"></span><span class="dl-name">Asuransi/Lain</span><span class="dl-val">{{ distAsn }} ({{ distAsnPct }}%)</span></div>
          </div>
        </div>
        <div class="divider-light"></div>
        <div class="cb">
          <div class="section-label">Jam Tersibuk (08.00–19.00)</div>
          <div class="sparkline">
            <div v-for="(h, i) in hourBusy" :key="i" class="spark-col">
              <div class="spark-bar" :class="h===maxHour ? 'peak' : h>=14 ? 'hi' : ''" :style="{ height: Math.max(4, (h/maxHour)*44)+'px' }"></div>
              <div class="spark-lbl">{{ hourLabels[i] }}</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="ch">
          <div class="cht"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Laporan Keuangan</div>
          <div class="d-filter-tabs">
            <button v-for="f in [{k:'hari',l:'Hari Ini'},{k:'minggu',l:'Minggu'},{k:'bulan',l:'Bulan'},{k:'tahun',l:'Tahun'}]" :key="f.k"
              :class="['d-ftab', keuanganFilter===f.k && 'd-ftab-a']"
              @click="keuanganFilter = f.k">{{ f.l }}</button>
          </div>
        </div>
        <div class="cb">
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

    <!-- ─── ROW: DIAGNOSIS + PENUNJANG + FARMASI ─── -->
    <div class="row-3col">
      <div class="card">
        <div class="ch"><div class="cht"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Top Diagnosis Hari Ini</div></div>
        <div class="cb">
          <div v-for="dx in diagnoses" :key="dx.code" class="dx-row">
            <div class="dx-head">
              <span class="dx-code" :style="{ color: dx.color }">{{ dx.code }}</span>
              <span class="dx-name">{{ dx.name }}</span>
              <span class="dx-count">{{ dx.count }}</span>
            </div>
            <div class="dx-bar-track"><div class="dx-bar-fill" :style="{ width: animReady ? (dx.count/maxDx*100)+'%' : '0%', background: dx.color }"></div></div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="ch"><div class="cht"><svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>Status Pemeriksaan Penunjang</div></div>
        <div class="cb">
          <div v-for="p in penunjang" :key="p.type" class="pj-card">
            <div class="pj-header">
              <div class="pj-icon" :style="{ background: p.bg }"><svg viewBox="0 0 24 24" :stroke="p.color"><path :d="p.icon"/></svg></div>
              <div class="pj-type">{{ p.type }}</div>
              <div class="pj-total" :style="{ color: p.color }">{{ p.req+p.prg+p.done }}</div>
            </div>
            <div class="pj-seg-track">
              <div class="pj-seg req" :style="{ width: ((p.req/(p.req+p.prg+p.done||1))*100)+'%' }"></div>
              <div class="pj-seg prg" :style="{ width: ((p.prg/(p.req+p.prg+p.done||1))*100)+'%' }"></div>
              <div class="pj-seg done" :style="{ width: ((p.done/(p.req+p.prg+p.done||1))*100)+'%' }"></div>
            </div>
            <div class="pj-legend">
              <span class="pjl req">{{ p.req }} menunggu</span>
              <span class="pjl prg">{{ p.prg }} proses</span>
              <span class="pjl done">{{ p.done }} selesai</span>
            </div>
          </div>
        </div>
      </div>

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
          <svg viewBox="0 0 308 100" class="trend-svg">
            <defs>
              <linearGradient id="bgV" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--ga)" stop-opacity="1"/><stop offset="100%" stop-color="var(--gl)" stop-opacity=".4"/></linearGradient>
              <linearGradient id="bgH" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--lm)" stop-opacity="1"/><stop offset="100%" stop-color="var(--gl)" stop-opacity=".3"/></linearGradient>
            </defs>
            <g v-for="(d,i) in visitTrend" :key="d.day">
              <rect :x="barX(i)" :y="visitBarY(d.total)" :width="30" :height="visitBarH(d.total)" rx="3"
                :fill="i===6?'url(#bgV)':i===2?'url(#bgH)':'var(--gb)'"/>
              <text :x="barX(i)+15" y="93" text-anchor="middle" class="svgt-lbl">{{ d.day==='Hari ini'?'●':d.day }}</text>
              <text :x="barX(i)+15" :y="visitBarY(d.total)-3" text-anchor="middle" class="svgt-val">{{ d.total }}</text>
            </g>
            <polyline :points="visitLinePoints" class="trend-line"/>
            <circle v-for="(d,i) in visitTrend" :key="'c'+i" :cx="barX(i)+15" :cy="visitBarY(d.total)"
              r="2.5" :fill="i===6?'var(--ga)':'var(--gb)'" :stroke="i===6?'var(--gd)':'var(--gb)'" stroke-width="1"/>
          </svg>
          <div class="chart-footer"><span>Puncak Rab (51) · Rata-rata: {{ Math.round(visitTrend.reduce((a,b)=>a+b.total,0)/visitTrend.length) }}/hari</span></div>
        </div>
      </div>
      <div class="card">
        <div class="ch"><div class="cht"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Tren Pendapatan 7 Hari</div></div>
        <div class="cb chart-area">
          <svg viewBox="0 0 308 100" class="trend-svg">
            <defs>
              <linearGradient id="bgR" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--wt)" stop-opacity="1"/><stop offset="100%" stop-color="var(--wb)" stop-opacity=".4"/></linearGradient>
            </defs>
            <g v-for="(d,i) in revenueTrend" :key="d.day">
              <rect :x="barX(i)" :y="revBarY(d.amount)" :width="30" :height="revBarH(d.amount)" rx="3"
                :fill="i===6?'url(#bgR)':'var(--gb)'"/>
              <text :x="barX(i)+15" y="93" text-anchor="middle" class="svgt-lbl">{{ d.day==='Hari ini'?'●':d.day }}</text>
              <text :x="barX(i)+15" :y="revBarY(d.amount)-3" text-anchor="middle" class="svgt-val">{{ (d.amount/1000000).toFixed(1) }}jt</text>
            </g>
            <polyline :points="revLinePoints" class="trend-line rev"/>
          </svg>
          <div class="chart-footer"><span>Total 7 hari: Rp {{ (revenueTrend.reduce((a,b)=>a+b.amount,0)/1000000).toFixed(2) }} jt</span></div>
        </div>
      </div>
    </div>

    <!-- ─── ROW: BPJS STATUS ─── -->
    <div class="row-full">
      <div class="card">
        <div class="ch"><div class="cht"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Integrasi BPJS &amp; Sistem</div></div>
        <div class="cb">
          <div v-for="s in bpjsServices" :key="s.name" class="svc-row">
            <span class="svc-name">{{ s.name }}</span>
            <div class="svc-right">
              <span class="svc-resp">{{ s.resp }}</span>
              <span :class="['svc-dot', s.status==='online'?'online':'maint']"></span>
              <span :class="['svc-label', s.status==='online'?'online':'maint']">{{ s.status==='online'?'Online':'Maintenance' }}</span>
            </div>
          </div>
          <div class="divider-light"></div>
          <div class="kv-grid">
            <div class="kv-row"><span class="kv-k">SEP Terbit Hari Ini</span><span class="kv-v success">{{ todaySep }}</span></div>
            <div class="kv-row"><span class="kv-k">Cache VClaim</span><span class="kv-v">{{ bpjsCacheAge }}</span></div>
            <div class="kv-row"><span class="kv-k">Pasien BPJS Aktif</span><span class="kv-v">{{ totalBpjs }}</span></div>
            <div class="kv-row"><span class="kv-k">Klaim Pending</span><span class="kv-v warn">2 verifikasi</span></div>
          </div>
          <div class="divider-light"></div>
          <div class="section-label">Statistik Pasien Hari Ini</div>
          <div class="mini-stat-row">
            <div class="mini-stat"><div class="ms-val">{{ farmasiStats.done }}<span>/{{ farmasiStats.totalRx }}</span></div><div class="ms-lbl">Resep Selesai</div></div>
            <div class="mini-stat"><div class="ms-val">{{ farmasiStats.waiting+farmasiStats.prepared }}<span></span></div><div class="ms-lbl">Resep Aktif</div></div>
            <div class="mini-stat"><div class="ms-val">{{ totalBpjs }}<span></span></div><div class="ms-lbl">Pasien BPJS</div></div>
            <div class="mini-stat"><div class="ms-val">{{ totalToday }}<span></span></div><div class="ms-lbl">Total Kunjungan</div></div>
          </div>
        </div>
      </div>
    </div>

  </div>

</template>

<style scoped>
.dashboard { display: flex; flex-direction: column; gap: 1rem; }

/* ─── WELCOME BAR ─── */
.welcome-bar { display:flex; align-items:center; justify-content:space-between; background:linear-gradient(135deg,var(--gd),var(--gm)); border-radius:12px; padding:.85rem 1.25rem; gap:1rem; }
.wb-title { font-family:'DM Serif Display',serif; font-size:18px; color:#fff; line-height:1.1; }
.wb-date  { font-size:11px; color:rgba(255,255,255,.55); margin-top:3px; }
.wb-right { display:flex; align-items:center; gap:.75rem; }
.wb-updated { font-size:11px; color:rgba(255,255,255,.5); font-variant-numeric:tabular-nums; }
.live-pill { display:inline-flex; align-items:center; gap:5px; background:rgba(56,189,248,.2); border:1px solid rgba(56,189,248,.4); color:var(--lm); font-size:9px; font-weight:700; padding:3px 9px; border-radius:20px; letter-spacing:.08em; }
.live-dot  { width:6px; height:6px; border-radius:50%; background:var(--lm); animation:blink 1.5s infinite; flex-shrink:0; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.25} }

/* ─── KPI ROW ─── */
.kpi-row { display:grid; grid-template-columns:repeat(5,1fr); gap:.75rem; }
.kpi-card { background:var(--bc); border:1px solid var(--gb); border-radius:12px; padding:.9rem 1rem; display:flex; align-items:center; gap:10px; border-top-width:3px; border-top-style:solid; }
.kpi-icon { width:40px; height:40px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.kpi-icon svg { width:20px; height:20px; fill:none; stroke-width:2; stroke-linecap:round; }
.kpi-body { min-width:0; flex:1; }
.kpi-val  { font-size:22px; font-weight:700; color:var(--td); line-height:1; }
.kpi-lbl  { font-size:10px; color:var(--tu); margin-top:2px; }
.kpi-sub  { font-size:10px; color:var(--th); margin-top:2px; }
.trend-up { color:var(--st) !important; }

/* ─── GRID ROWS ─── */
.row-2col  { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.row-3col  { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; }
.row-keu   { display:grid; grid-template-columns:2fr 3fr; gap:1rem; }
.row-full  { width:100%; }

/* ─── CARD BASE ─── */
.card { background:var(--bc); border:1px solid var(--gb); border-radius:12px; overflow:hidden; border-top:3px solid transparent; }
.ch { padding:.6rem .9rem; border-bottom:1px solid var(--gb); display:flex; align-items:center; justify-content:space-between; gap:.4rem; }
.cht { font-size:12px; font-weight:600; color:var(--td); display:flex; align-items:center; gap:5px; }
.cht svg { width:12px; height:12px; fill:none; stroke:var(--ga); stroke-width:2; stroke-linecap:round; }
.cb { padding:.75rem .9rem; }
.ch-badge { font-size:9px; font-weight:700; padding:2px 8px; border-radius:20px; }
.ch-badge.warn { background:var(--eb); color:var(--et); border:1px solid var(--ebd); }
.divider-light { height:1px; background:var(--gb); margin:.5rem .9rem; }
.section-label { font-size:9.5px; font-weight:600; color:var(--tu); text-transform:uppercase; letter-spacing:.06em; margin-bottom:.4rem; }
.d-filter-tabs { display:flex; gap:3px; }
.d-ftab { padding:2px 8px; font-size:9.5px; font-weight:600; border:1px solid var(--gb); border-radius:6px; background:var(--bs); color:var(--tu); cursor:pointer; transition:all .15s; }
.d-ftab:hover { border-color:var(--ga); color:var(--gm); }
.d-ftab-a { background:var(--ga); color:#fff; border-color:var(--ga); }

/* ─── DONUT ─── */
.donut-section { display:flex; align-items:center; gap:1.25rem; }
.donut-wrap { flex-shrink:0; }
.donut { width:110px; height:110px; border-radius:50%; position:relative; }
.donut-hole { position:absolute; inset:22px; border-radius:50%; background:var(--bc); display:flex; flex-direction:column; align-items:center; justify-content:center; }
.donut-center-val { font-size:20px; font-weight:700; color:var(--td); line-height:1; }
.donut-center-lbl { font-size:9px; color:var(--tu); }
.donut-legend { flex:1; display:flex; flex-direction:column; gap:.4rem; }
.dl-row { display:flex; align-items:center; gap:.4rem; font-size:11.5px; }
.dl-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
.dl-name { flex:1; color:var(--tm); }
.dl-val { font-weight:600; color:var(--td); font-variant-numeric:tabular-nums; }

/* ─── SPARKLINE ─── */
.sparkline { display:grid; grid-template-columns:repeat(12,1fr); gap:2px; align-items:flex-end; height:52px; }
.spark-col { display:flex; flex-direction:column; align-items:center; justify-content:flex-end; gap:2px; }
.spark-bar { width:100%; background:var(--gb); border-radius:2px 2px 0 0; min-height:4px; transition:height .4s ease; }
.spark-bar.hi   { background:var(--lm); }
.spark-bar.peak { background:var(--et); }
.spark-lbl { font-size:7.5px; color:var(--th); }

/* ─── DIAGNOSIS ─── */
.dx-row { margin-bottom:.45rem; }
.dx-head { display:flex; align-items:center; gap:.4rem; margin-bottom:3px; }
.dx-code  { font-size:9.5px; font-weight:700; min-width:42px; }
.dx-name  { font-size:12px; color:var(--td); flex:1; }
.dx-count { font-size:11.5px; font-weight:700; color:var(--td); font-variant-numeric:tabular-nums; }
.dx-bar-track { height:6px; background:var(--bs); border-radius:3px; overflow:hidden; }
.dx-bar-fill  { height:100%; border-radius:3px; transition:width .7s cubic-bezier(.22,1,.36,1); }

/* ─── PENUNJANG ─── */
.pj-card { background:var(--bs); border:1px solid var(--gb); border-radius:9px; padding:.55rem .7rem; margin-bottom:.45rem; }
.pj-card:last-child { margin-bottom:0; }
.pj-header { display:flex; align-items:center; gap:.5rem; margin-bottom:.4rem; }
.pj-icon { width:28px; height:28px; border-radius:7px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.pj-icon svg { width:14px; height:14px; fill:none; stroke-width:2; stroke-linecap:round; }
.pj-type  { flex:1; font-size:12.5px; font-weight:600; color:var(--td); }
.pj-total { font-size:16px; font-weight:700; }
.pj-seg-track { height:8px; background:var(--gb); border-radius:4px; overflow:hidden; display:flex; margin-bottom:4px; }
.pj-seg { height:100%; transition:width .6s ease; }
.pj-seg.req  { background:var(--wt); }
.pj-seg.prg  { background:var(--it); }
.pj-seg.done { background:var(--st); }
.pj-legend { display:flex; gap:.5rem; }
.pjl { font-size:9px; font-weight:600; }
.pjl.req  { color:var(--wt); }
.pjl.prg  { color:var(--it); }
.pjl.done { color:var(--st); }

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
.trend-svg  { width:100%; overflow:visible; }
.svgt-lbl   { font-size:7.5px; fill:var(--tu); font-family:'DM Sans',sans-serif; }
.svgt-val   { font-size:7px; fill:var(--td); font-family:'DM Sans',sans-serif; font-weight:600; }
.trend-line { fill:none; stroke:var(--ga); stroke-width:1.5; opacity:.5; }
.trend-line.rev { stroke:var(--wt); }
.chart-footer { font-size:10px; color:var(--tu); margin-top:.35rem; }

/* ─── LAPORAN KEUANGAN ─── */
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
.keu-cat-pct { font-size:10px; font-weight:700; color:var(--gm); }
.keu-cat-bar-wrap { width:100%; height:40px; background:var(--bs); border-radius:4px; display:flex; align-items:flex-end; overflow:hidden; }
.keu-cat-bar { width:100%; background:linear-gradient(to top,var(--ga),var(--lm)); border-radius:4px; transition:height .6s ease; }
.keu-cat-lbl { font-size:9.5px; font-weight:600; color:var(--tu); text-align:center; }
.keu-cat-val { font-size:9px; color:var(--th); text-align:center; }

/* ─── BPJS STATUS ─── */
.svc-row   { display:flex; align-items:center; justify-content:space-between; padding:.35rem 0; border-bottom:1px solid rgba(0,0,0,.04); }
.svc-row:last-child { border-bottom:none; }
.svc-name  { font-size:12px; color:var(--tm); }
.svc-right { display:flex; align-items:center; gap:.4rem; }
.svc-resp  { font-size:9.5px; color:var(--th); font-variant-numeric:tabular-nums; }
.svc-dot   { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.svc-dot.online { background:var(--st); animation:blink 2s infinite; }
.svc-dot.maint  { background:var(--wt); }
.svc-label { font-size:10.5px; font-weight:600; }
.svc-label.online { color:var(--st); }
.svc-label.maint  { color:var(--wt); }
.kv-grid { display:flex; flex-direction:column; gap:.35rem; margin-bottom:.1rem; }
.kv-row  { display:flex; align-items:center; justify-content:space-between; font-size:11.5px; }
.kv-k    { color:var(--tu); }
.kv-v    { font-weight:600; color:var(--td); }
.kv-v.success { color:var(--st); }
.kv-v.warn    { color:var(--wt); }
.mini-stat-row { display:grid; grid-template-columns:repeat(4,1fr); gap:.5rem; }
.mini-stat { background:var(--bs); border:1px solid var(--gb); border-radius:8px; padding:.5rem; text-align:center; }
.ms-val { font-size:16px; font-weight:700; color:var(--td); line-height:1; }
.ms-val span { font-size:10px; font-weight:500; color:var(--tu); }
.ms-lbl { font-size:9px; color:var(--tu); margin-top:2px; }

</style>
