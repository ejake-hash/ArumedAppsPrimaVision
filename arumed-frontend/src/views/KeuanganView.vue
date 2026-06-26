<script setup>
import { ref, computed, reactive, onMounted } from 'vue'
import { keuanganApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import BarChart from '@/components/common/charts/BarChart.vue'
import DoughnutChart from '@/components/common/charts/DoughnutChart.vue'

const auth = useAuthStore()
const canWrite = computed(() => auth.can('keuangan.write'))
const canDelete = computed(() => auth.can('keuangan.delete'))

// ─── Tabs ────────────────────────────────────────────────────────────────────
const TABS = [
  { val: 'recap', label: 'Rekap Honor' },
  { val: 'laporan', label: 'Laporan' },
  { val: 'rules', label: 'Aturan Honor' },
]
const activeTab = ref('recap')

// ─── Toast ───────────────────────────────────────────────────────────────────
const toastMsg = ref('')
const toastType = ref('s')
let toastTimer = null
function toast(type, msg) {
  toastType.value = type
  toastMsg.value = msg
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toastMsg.value = '' }, 3200)
}

// ─── Util format ─────────────────────────────────────────────────────────────
function rp(v) {
  if (v === null || v === undefined || v === '') return '—'
  return 'Rp ' + Number(v).toLocaleString('id-ID')
}
function thisMonth() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

// ─── Options (dokter / paket / kategori) ─────────────────────────────────────
const options = ref({ doctors: [], packages: [], categories: [], rule_types: [], payer_groups: [], bases: [] })
async function loadOptions() {
  try {
    const res = await keuanganApi.options()
    options.value = res.data.data
  } catch (e) { /* non-fatal */ }
}

// ═══════════════════════════════════════════════════════════════════════════
// TAB 1 — REKAP HONOR
// ═══════════════════════════════════════════════════════════════════════════
const period = ref(thisMonth())
const filterDoctor = ref('')
const filterPayer = ref('')
const bpjsBasis = ref('finalized')
const recap = ref(null)
const loadingRecap = ref(false)
const openFmt = ref(false)

const recapParams = computed(() => {
  const p = { period: period.value, bpjs_basis: bpjsBasis.value }
  if (filterDoctor.value) p.employee_id = filterDoctor.value
  if (filterPayer.value) p.payer_group = filterPayer.value
  return p
})

async function loadRecap() {
  loadingRecap.value = true
  try {
    const res = await keuanganApi.recap(recapParams.value)
    recap.value = res.data.data
  } catch (e) {
    toast('e', e?.response?.data?.message || 'Gagal memuat rekap honor.')
  } finally {
    loadingRecap.value = false
  }
}

function triggerDownload(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
async function exportData(format = 'csv') {
  openFmt.value = false
  try {
    const res = await keuanganApi.csvExport(recapParams.value, format === 'xlsx' ? 'xlsx' : undefined)
    triggerDownload(res.data, `rekap-honor-${period.value}.${format}`)
  } catch (e) {
    toast('e', 'Gagal mengekspor data.')
  }
}

const payersWithData = (doc) =>
  ['UMUM', 'BPJS'].filter(pg => doc.payers[pg]?.categories?.length || doc.payers[pg]?.packages?.length)

// ═══════════════════════════════════════════════════════════════════════════
// TAB 2 — LAPORAN OBAT FARMASI (pemakaian + pendapatan)
// ═══════════════════════════════════════════════════════════════════════════
const CAT_LABEL = { rawat_jalan: 'Rawat Jalan', pasca_bedah: 'Pasca Bedah', obat_bebas: 'Obat Bebas' }
const CAT_COLOR = { rawat_jalan: '#0e7490', pasca_bedah: '#9333ea', obat_bebas: '#16a34a' }
const CAT_KEYS = ['rawat_jalan', 'pasca_bedah', 'obat_bebas']

const labPeriod = ref(thisMonth())
const labPayer = ref('')
const labCategory = ref('')
const report = ref(null)
const loadingReport = ref(false)
const openLabFmt = ref(false)

const labParams = computed(() => {
  const p = { period: labPeriod.value }
  if (labPayer.value) p.payer_group = labPayer.value
  if (labCategory.value) p.category = labCategory.value
  return p
})

async function loadReport() {
  loadingReport.value = true
  try {
    const res = await keuanganApi.laporanObat(labParams.value)
    report.value = res.data.data
  } catch (e) {
    toast('e', e?.response?.data?.message || 'Gagal memuat laporan obat.')
  } finally {
    loadingReport.value = false
  }
}

async function exportReport(format = 'csv') {
  openLabFmt.value = false
  try {
    const res = await keuanganApi.laporanObatExport(labParams.value, format === 'xlsx' ? 'xlsx' : undefined)
    triggerDownload(res.data, `laporan-obat-${labPeriod.value}.${format}`)
  } catch (e) {
    toast('e', 'Gagal mengekspor laporan.')
  }
}

const trendData = computed(() => ({
  labels: (report.value?.trend || []).map(t => t.label),
  datasets: CAT_KEYS.map(k => ({
    label: CAT_LABEL[k],
    data: (report.value?.trend || []).map(t => t[k]),
    backgroundColor: CAT_COLOR[k],
    borderRadius: 3,
    stack: 'obat',
  })),
}))
const trendOptions = {
  plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
  scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } },
}

const compositionData = computed(() => ({
  labels: CAT_KEYS.map(k => CAT_LABEL[k]),
  datasets: [{
    data: CAT_KEYS.map(k => report.value?.composition?.[k] ?? 0),
    backgroundColor: CAT_KEYS.map(k => CAT_COLOR[k]),
    borderWidth: 0,
  }],
}))

// ═══════════════════════════════════════════════════════════════════════════
// TAB 3 — ATURAN HONOR (fee rules)
// ═══════════════════════════════════════════════════════════════════════════
const rules = ref([])
const loadingRules = ref(false)
async function loadRules() {
  loadingRules.value = true
  try {
    const res = await keuanganApi.listRules({})
    rules.value = res.data.data
  } catch (e) {
    toast('e', 'Gagal memuat aturan honor.')
  } finally {
    loadingRules.value = false
  }
}

const RULE_TYPE_LABEL = {
  PERCENT_CATEGORY: 'Persen / Kategori (PKS)',
  PERCENT_PAYER: 'Persen / Penjamin',
  NOMINAL_PACKAGE: 'Nominal / Paket (Edaran)',
}

const showModal = ref(false)
const editingId = ref(null)
const form = reactive({
  employee_id: '', rule_type: 'PERCENT_CATEGORY', category: '', surgery_package_id: '',
  payer_group: '', percent: '', nominal: '', basis: 'NET', effective_from: '', effective_to: '',
  label: '', is_active: true,
})
const isNominal = computed(() => form.rule_type === 'NOMINAL_PACKAGE')

function openCreate() {
  editingId.value = null
  Object.assign(form, {
    employee_id: '', rule_type: 'PERCENT_CATEGORY', category: '', surgery_package_id: '',
    payer_group: '', percent: '', nominal: '', basis: 'NET',
    effective_from: `${period.value}-01`, effective_to: '', label: '', is_active: true,
  })
  showModal.value = true
}
function openEdit(r) {
  editingId.value = r.id
  Object.assign(form, {
    employee_id: r.employee_id || '', rule_type: r.rule_type, category: r.category || '',
    surgery_package_id: r.surgery_package_id || '', payer_group: r.payer_group || '',
    percent: r.percent ?? '', nominal: r.nominal ?? '', basis: r.basis || 'NET',
    effective_from: (r.effective_from || '').slice(0, 10), effective_to: (r.effective_to || '').slice(0, 10),
    label: r.label || '', is_active: !!r.is_active,
  })
  showModal.value = true
}

async function saveRule() {
  const body = {
    employee_id: form.employee_id || null,
    rule_type: form.rule_type,
    category: isNominal.value ? null : (form.category || null),
    surgery_package_id: isNominal.value ? (form.surgery_package_id || null) : null,
    payer_group: form.payer_group || null,
    percent: isNominal.value ? null : (form.percent === '' ? null : Number(form.percent)),
    nominal: isNominal.value ? (form.nominal === '' ? null : Number(form.nominal)) : null,
    basis: form.basis,
    effective_from: form.effective_from,
    effective_to: form.effective_to || null,
    label: form.label || null,
    is_active: form.is_active,
  }
  try {
    if (editingId.value) await keuanganApi.updateRule(editingId.value, body)
    else await keuanganApi.createRule(body)
    showModal.value = false
    toast('s', 'Aturan honor disimpan.')
    loadRules()
  } catch (e) {
    const errs = e?.response?.data?.errors
    const first = errs ? Object.values(errs)[0]?.[0] : null
    toast('e', first || e?.response?.data?.message || 'Gagal menyimpan aturan.')
  }
}

async function removeRule(r) {
  if (!confirm(`Hapus aturan honor "${r.label || RULE_TYPE_LABEL[r.rule_type]}"?`)) return
  try {
    await keuanganApi.deleteRule(r.id)
    toast('s', 'Aturan honor dihapus.')
    loadRules()
  } catch (e) {
    toast('e', 'Gagal menghapus aturan.')
  }
}

const ruleTarget = (r) =>
  r.rule_type === 'NOMINAL_PACKAGE'
    ? (r.surgery_package?.name || 'Semua paket')
    : (r.category || 'Semua kategori')

// ─── Init ────────────────────────────────────────────────────────────────────
onMounted(() => {
  loadOptions()
  loadRecap()
  loadReport()
  loadRules()
})
</script>

<template>
  <div class="ku-page" @click="openFmt = false; openLabFmt = false">
    <header class="ku-head">
      <div>
        <h1>Keuangan — Rekap Honor Dokter</h1>
        <p class="sub">Jasa medis dokter per periode dari tagihan terealisasi, dipisah BPJS vs Umum/Asuransi.</p>
      </div>
    </header>

    <div class="ku-tabs">
      <button v-for="t in TABS" :key="t.val" :class="['ku-tab', { on: activeTab === t.val }]" @click="activeTab = t.val">
        {{ t.label }}
      </button>
    </div>

    <!-- ══════════════════ TAB REKAP ══════════════════ -->
    <section v-show="activeTab === 'recap'" class="ku-card">
      <div class="ku-filters">
        <label class="fl">
          <span>Periode</span>
          <input type="month" v-model="period" />
        </label>
        <label class="fl">
          <span>Dokter</span>
          <select v-model="filterDoctor">
            <option value="">Semua dokter</option>
            <option v-for="d in options.doctors" :key="d.id" :value="d.id">{{ d.name }}</option>
          </select>
        </label>
        <label class="fl">
          <span>Penjamin</span>
          <select v-model="filterPayer">
            <option value="">BPJS &amp; Umum</option>
            <option value="UMUM">Umum/Asuransi</option>
            <option value="BPJS">BPJS</option>
          </select>
        </label>
        <label class="fl">
          <span>Basis BPJS</span>
          <select v-model="bpjsBasis">
            <option value="finalized">Finalisasi (bln pelayanan)</option>
            <option value="paid">Lunas (paid_at)</option>
          </select>
        </label>
        <button class="btn primary" :disabled="loadingRecap" @click="loadRecap">
          {{ loadingRecap ? 'Memuat…' : 'Tampilkan' }}
        </button>
        <div class="fmt-wrap" @click.stop>
          <button class="btn soft" @click="openFmt = !openFmt">Export ▾</button>
          <div v-if="openFmt" class="fmt-menu">
            <button @click="exportData('csv')">CSV (.csv)</button>
            <button @click="exportData('xlsx')">Excel (.xlsx)</button>
          </div>
        </div>
      </div>

      <div v-if="recap" class="ku-summary">
        <div class="sum-box umum"><span>Honor Umum/Asuransi</span><b>{{ rp(recap.grand_total.honor_umum) }}</b></div>
        <div class="sum-box bpjs"><span>Honor BPJS</span><b>{{ rp(recap.grand_total.honor_bpjs) }}</b></div>
        <div class="sum-box total"><span>Total Honor — {{ recap.period_label }}</span><b>{{ rp(recap.grand_total.honor) }}</b></div>
        <div v-if="recap.unmatched_count" class="sum-warn">
          ⚠ {{ recap.unmatched_count }} kategori tanpa aturan honor (honor = 0). Lengkapi di tab <b>Aturan Honor</b>.
        </div>
      </div>

      <div v-if="recap && !recap.doctors.length" class="ku-empty">
        Tidak ada layanan terealisasi pada periode ini.
      </div>

      <div v-for="doc in (recap?.doctors || [])" :key="doc.employee_id || 'none'" class="doc-card">
        <div class="doc-head">
          <h3>{{ doc.doctor_name }}</h3>
          <span class="doc-total">Total honor: <b>{{ rp(doc.total_honor) }}</b></span>
        </div>

        <div v-for="pg in payersWithData(doc)" :key="pg" class="payer-block">
          <div class="payer-tag" :class="pg.toLowerCase()">{{ pg === 'UMUM' ? 'Umum/Asuransi' : 'BPJS' }}</div>
          <table class="ku-table">
            <thead>
              <tr><th>Kategori</th><th class="r">Jml</th><th class="r">Tarif</th><th class="r">%/Nominal</th><th class="r">Honor</th><th>Aturan</th></tr>
            </thead>
            <tbody>
              <tr v-for="c in doc.payers[pg].categories" :key="c.category" :class="{ unmatched: c.rule_matched === false }">
                <td>{{ c.category }}</td>
                <td class="r">{{ c.count }}</td>
                <td class="r">{{ rp(c.amount_gross) }}</td>
                <td class="r">{{ c.percent !== null ? c.percent + '%' : '—' }}</td>
                <td class="r">{{ rp(c.honor) }}</td>
                <td class="muted">{{ c.rule_matched === false ? 'TANPA ATURAN' : (c.rule_label || '—') }}</td>
              </tr>
              <tr v-for="(pk, i) in doc.payers[pg].packages" :key="'pk' + i" class="pkg">
                <td>📦 Paket {{ pk.package_name }}</td>
                <td class="r">{{ pk.case_count }} kasus</td>
                <td class="r">—</td>
                <td class="r">{{ rp(pk.nominal) }}</td>
                <td class="r">{{ rp(pk.honor) }}</td>
                <td class="muted">{{ pk.rule_label || '—' }}</td>
              </tr>
              <tr class="sub">
                <td>Subtotal {{ pg === 'UMUM' ? 'Umum' : 'BPJS' }}</td>
                <td></td>
                <td class="r">{{ rp(doc.payers[pg].subtotal_amount) }}</td>
                <td></td>
                <td class="r">{{ rp(doc.payers[pg].subtotal_honor) }}</td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>

        <details v-if="doc.noninfo.length" class="noninfo">
          <summary>Non-honor (obat/BHP/IOL/lainnya) — {{ doc.noninfo.length }} item</summary>
          <ul>
            <li v-for="(n, i) in doc.noninfo" :key="i">{{ n.category }} — {{ rp(n.amount) }}</li>
          </ul>
        </details>
      </div>
    </section>

    <!-- ══════════════════ TAB LAPORAN ══════════════════ -->
    <section v-show="activeTab === 'laporan'" class="ku-card">
      <div class="ku-filters">
        <label class="fl">
          <span>Periode</span>
          <input type="month" v-model="labPeriod" />
        </label>
        <label class="fl">
          <span>Penjamin</span>
          <select v-model="labPayer">
            <option value="">BPJS &amp; Umum</option>
            <option value="UMUM">Umum/Asuransi</option>
            <option value="BPJS">BPJS</option>
          </select>
        </label>
        <label class="fl">
          <span>Kategori (tabel)</span>
          <select v-model="labCategory">
            <option value="">Semua kategori</option>
            <option value="rawat_jalan">Rawat Jalan</option>
            <option value="pasca_bedah">Pasca Bedah</option>
            <option value="obat_bebas">Obat Bebas</option>
          </select>
        </label>
        <button class="btn primary" :disabled="loadingReport" @click="loadReport">
          {{ loadingReport ? 'Memuat…' : 'Tampilkan' }}
        </button>
        <div class="fmt-wrap" @click.stop>
          <button class="btn soft" @click="openLabFmt = !openLabFmt">Export ▾</button>
          <div v-if="openLabFmt" class="fmt-menu">
            <button @click="exportReport('csv')">CSV (.csv)</button>
            <button @click="exportReport('xlsx')">Excel (.xlsx)</button>
          </div>
        </div>
      </div>

      <template v-if="report">
        <div class="ku-summary">
          <div class="sum-box total"><span>Total Pendapatan Obat — {{ report.period_label }}</span><b>{{ rp(report.kpi.total) }}</b></div>
          <div class="sum-box umum"><span>Rawat Jalan</span><b>{{ rp(report.kpi.rawat_jalan) }}</b></div>
          <div class="sum-box pascabedah"><span>Pasca Bedah</span><b>{{ rp(report.kpi.pasca_bedah) }}</b></div>
          <div class="sum-box bebas"><span>Obat Bebas</span><b>{{ rp(report.kpi.obat_bebas) }}</b></div>
        </div>

        <div class="chart-grid">
          <div class="chart-card wide">
            <h3>Tren Pendapatan Obat — 12 Bulan</h3>
            <BarChart :data="trendData" :options="trendOptions" height="280px" />
          </div>
          <div class="chart-card">
            <h3>Komposisi {{ report.period_label }}</h3>
            <DoughnutChart :data="compositionData" height="280px" />
          </div>
          <div class="chart-card">
            <h3>Ringkasan Kategori</h3>
            <ul class="cat-legend">
              <li v-for="k in ['rawat_jalan','pasca_bedah','obat_bebas']" :key="k">
                <span class="dot" :style="{ background: CAT_COLOR[k] }"></span>
                {{ CAT_LABEL[k] }}
                <b>{{ rp(report.kpi[k]) }}</b>
              </li>
            </ul>
          </div>
        </div>

        <h3 class="lap-tbl-title">Pemakaian Obat Farmasi ({{ report.kpi.item_count }} jenis)</h3>
        <table class="ku-table">
          <thead>
            <tr><th>Obat</th><th>Golongan</th><th>Kategori</th><th class="r">Qty Terpakai</th><th class="r">Transaksi</th><th class="r">Pendapatan</th></tr>
          </thead>
          <tbody>
            <tr v-if="!report.usage.length"><td colspan="6" class="muted ctr">Tidak ada pemakaian obat pada periode ini.</td></tr>
            <tr v-for="(u, i) in report.usage" :key="i">
              <td>{{ u.medication }}</td>
              <td class="muted">{{ u.golongan || '—' }}</td>
              <td><span class="cat-tag" :style="{ background: CAT_COLOR[u.category] + '22', color: CAT_COLOR[u.category] }">{{ CAT_LABEL[u.category] }}</span></td>
              <td class="r">{{ u.qty }}</td>
              <td class="r">{{ u.tx_count }}</td>
              <td class="r">{{ rp(u.revenue) }}</td>
            </tr>
          </tbody>
        </table>
      </template>
      <div v-else-if="loadingReport" class="ku-empty">Memuat laporan…</div>
    </section>

    <!-- ══════════════════ TAB ATURAN ══════════════════ -->
    <section v-show="activeTab === 'rules'" class="ku-card">
      <div class="rules-bar">
        <p class="hint">Aturan honor menentukan persentase (PKS) atau nominal tetap per paket (edaran). Dokter kosong = berlaku global; penjamin kosong = berlaku BPJS &amp; Umum.</p>
        <button v-if="canWrite" class="btn primary" @click="openCreate">+ Tambah Aturan</button>
      </div>

      <table class="ku-table">
        <thead>
          <tr><th>Dokter</th><th>Jenis</th><th>Kategori / Paket</th><th>Penjamin</th><th class="r">Nilai</th><th>Basis</th><th>Berlaku</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-if="!rules.length && !loadingRules"><td colspan="9" class="muted ctr">Belum ada aturan honor.</td></tr>
          <tr v-for="r in rules" :key="r.id">
            <td>{{ r.employee?.name || 'Global (semua dokter)' }}</td>
            <td>{{ RULE_TYPE_LABEL[r.rule_type] }}</td>
            <td>{{ ruleTarget(r) }}</td>
            <td>{{ r.payer_group || 'Semua' }}</td>
            <td class="r">{{ r.rule_type === 'NOMINAL_PACKAGE' ? rp(r.nominal) : (r.percent + '%') }}</td>
            <td>{{ r.rule_type === 'NOMINAL_PACKAGE' ? '—' : r.basis }}</td>
            <td>{{ (r.effective_from || '').slice(0, 10) }}<template v-if="r.effective_to"> → {{ (r.effective_to || '').slice(0, 10) }}</template></td>
            <td><span :class="['badge', r.is_active ? 'ok' : 'off']">{{ r.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
            <td class="acts">
              <button v-if="canWrite" class="lnk" @click="openEdit(r)">Edit</button>
              <button v-if="canDelete" class="lnk del" @click="removeRule(r)">Hapus</button>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- ══════════════════ MODAL ATURAN ══════════════════ -->
    <div v-if="showModal" class="modal-bg" @click.self="showModal = false">
      <div class="modal">
        <h3>{{ editingId ? 'Edit' : 'Tambah' }} Aturan Honor</h3>
        <div class="form-grid">
          <label class="fl">
            <span>Jenis Aturan</span>
            <select v-model="form.rule_type">
              <option v-for="t in options.rule_types" :key="t" :value="t">{{ RULE_TYPE_LABEL[t] }}</option>
            </select>
          </label>
          <label class="fl">
            <span>Dokter <small>(kosong = global)</small></span>
            <select v-model="form.employee_id">
              <option value="">Global (semua dokter)</option>
              <option v-for="d in options.doctors" :key="d.id" :value="d.id">{{ d.name }}</option>
            </select>
          </label>

          <label v-if="!isNominal" class="fl">
            <span>Kategori <small>(kosong = semua)</small></span>
            <select v-model="form.category">
              <option value="">Semua kategori</option>
              <option v-for="c in options.categories" :key="c" :value="c">{{ c }}</option>
            </select>
          </label>
          <label v-else class="fl">
            <span>Paket Bedah <small>(kosong = semua)</small></span>
            <select v-model="form.surgery_package_id">
              <option value="">Semua paket</option>
              <option v-for="p in options.packages" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </label>

          <label class="fl">
            <span>Penjamin <small>(kosong = keduanya)</small></span>
            <select v-model="form.payer_group">
              <option value="">BPJS &amp; Umum</option>
              <option value="UMUM">Umum/Asuransi</option>
              <option value="BPJS">BPJS</option>
            </select>
          </label>

          <label v-if="!isNominal" class="fl">
            <span>Persen Honor (%)</span>
            <input type="number" min="0" max="100" step="0.01" v-model="form.percent" placeholder="mis. 80" />
          </label>
          <label v-if="!isNominal" class="fl">
            <span>Basis</span>
            <select v-model="form.basis">
              <option value="NET">NET (setelah diskon)</option>
              <option value="GROSS">GROSS (total tarif)</option>
            </select>
          </label>
          <label v-if="isNominal" class="fl">
            <span>Nominal Honor / Kasus (Rp)</span>
            <input type="number" min="0" step="1000" v-model="form.nominal" placeholder="mis. 1500000" />
          </label>

          <label class="fl">
            <span>Berlaku Dari</span>
            <input type="date" v-model="form.effective_from" />
          </label>
          <label class="fl">
            <span>Berlaku Sampai <small>(opsional)</small></span>
            <input type="date" v-model="form.effective_to" />
          </label>
          <label class="fl wide">
            <span>Label / Catatan</span>
            <input type="text" v-model="form.label" placeholder="mis. PKS dr. X 2026 / Edaran 014" />
          </label>
          <label class="fl chk">
            <input type="checkbox" v-model="form.is_active" /> <span>Aktif</span>
          </label>
        </div>
        <div class="modal-acts">
          <button class="btn ghost" @click="showModal = false">Batal</button>
          <button class="btn primary" @click="saveRule">Simpan</button>
        </div>
      </div>
    </div>

    <transition name="toast">
      <div v-if="toastMsg" :class="['ku-toast', toastType]">{{ toastMsg }}</div>
    </transition>
  </div>
</template>

<style scoped>
.ku-page { padding: 18px 22px; max-width: 1180px; margin: 0 auto; }
.ku-head h1 { font-size: 1.35rem; margin: 0; }
.ku-head .sub { color: #64748b; margin: 4px 0 0; font-size: .85rem; }
.ku-tabs { display: flex; gap: 6px; margin: 16px 0 12px; border-bottom: 1px solid #e2e8f0; }
.ku-tab { background: none; border: none; padding: 9px 16px; cursor: pointer; color: #64748b; font-weight: 600; border-bottom: 2px solid transparent; }
.ku-tab.on { color: #0e7490; border-bottom-color: #0e7490; }
.ku-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; }

.ku-filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
.fl { display: flex; flex-direction: column; gap: 4px; font-size: .8rem; color: #475569; }
.fl span { font-weight: 600; }
.fl small { color: #94a3b8; font-weight: 400; }
.fl input, .fl select { padding: 7px 9px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: .85rem; min-width: 150px; }
.fl.wide { grid-column: span 2; }
.fl.chk { flex-direction: row; align-items: center; gap: 6px; }

.btn { padding: 8px 14px; border-radius: 8px; border: 1px solid transparent; cursor: pointer; font-weight: 600; font-size: .85rem; }
.btn.primary { background: #0e7490; color: #fff; }
.btn.soft { background: #ecfeff; color: #0e7490; border-color: #a5f3fc; }
.btn.ghost { background: #f1f5f9; color: #475569; }
.btn:disabled { opacity: .6; cursor: default; }
.fmt-wrap { position: relative; }
.fmt-menu { position: absolute; right: 0; top: 110%; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 10; overflow: hidden; }
.fmt-menu button { display: block; width: 100%; text-align: left; padding: 9px 16px; background: none; border: none; cursor: pointer; font-size: .85rem; white-space: nowrap; }
.fmt-menu button:hover { background: #f1f5f9; }

.ku-summary { display: flex; flex-wrap: wrap; gap: 12px; margin: 16px 0; }
.sum-box { flex: 1; min-width: 180px; padding: 12px 16px; border-radius: 10px; display: flex; flex-direction: column; gap: 4px; }
.sum-box span { font-size: .75rem; color: #64748b; }
.sum-box b { font-size: 1.15rem; }
.sum-box.umum { background: #f0fdfa; } .sum-box.bpjs { background: #eff6ff; } .sum-box.total { background: #fef3c7; }
.sum-box.pascabedah { background: #faf5ff; } .sum-box.bebas { background: #f0fdf4; }

.chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 16px 0; }
.chart-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; }
.chart-card.wide { grid-column: 1 / -1; }
.chart-card h3 { font-size: .82rem; color: #475569; margin: 0 0 10px; font-weight: 600; }
.cat-legend { list-style: none; margin: 8px 0 0; padding: 0; }
.cat-legend li { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: .85rem; color: #475569; }
.cat-legend li b { margin-left: auto; color: #0f172a; }
.cat-legend .dot { width: 11px; height: 11px; border-radius: 3px; flex: none; }
.cat-tag { display: inline-block; font-size: .72rem; font-weight: 700; padding: 2px 8px; border-radius: 6px; }
.lap-tbl-title { font-size: .9rem; color: #334155; margin: 18px 0 8px; }
@media (max-width: 900px) { .chart-grid { grid-template-columns: 1fr; } }
.sum-warn { flex-basis: 100%; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; padding: 8px 12px; border-radius: 8px; font-size: .82rem; }

.ku-empty { text-align: center; color: #94a3b8; padding: 30px; }
.doc-card { border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; margin-top: 14px; }
.doc-head { display: flex; justify-content: space-between; align-items: center; }
.doc-head h3 { margin: 0; font-size: 1rem; }
.doc-total b { color: #0e7490; }
.payer-block { margin-top: 10px; }
.payer-tag { display: inline-block; font-size: .7rem; font-weight: 700; padding: 2px 8px; border-radius: 6px; margin-bottom: 4px; }
.payer-tag.umum { background: #ccfbf1; color: #0f766e; } .payer-tag.bpjs { background: #dbeafe; color: #1d4ed8; }

.ku-table { width: 100%; border-collapse: collapse; font-size: .83rem; }
.ku-table th, .ku-table td { padding: 6px 9px; border-bottom: 1px solid #f1f5f9; text-align: left; }
.ku-table th { color: #64748b; font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; }
.ku-table .r { text-align: right; }
.ku-table .muted { color: #94a3b8; font-size: .78rem; }
.ku-table .ctr { text-align: center; }
.ku-table tr.unmatched td { background: #fff7ed; }
.ku-table tr.pkg td { background: #fafafa; }
.ku-table tr.sub td { font-weight: 700; background: #f8fafc; }
.noninfo { margin-top: 8px; font-size: .8rem; color: #64748b; }
.noninfo summary { cursor: pointer; }
.noninfo ul { margin: 6px 0 0; padding-left: 18px; }

.rules-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 12px; }
.rules-bar .hint { font-size: .8rem; color: #64748b; margin: 0; max-width: 760px; }
.badge { font-size: .72rem; padding: 2px 8px; border-radius: 6px; font-weight: 600; }
.badge.ok { background: #dcfce7; color: #166534; } .badge.off { background: #f1f5f9; color: #64748b; }
.acts { white-space: nowrap; }
.lnk { background: none; border: none; cursor: pointer; color: #0e7490; font-weight: 600; font-size: .8rem; padding: 2px 6px; }
.lnk.del { color: #dc2626; }

.modal-bg { position: fixed; inset: 0; background: rgba(15,23,42,.45); display: flex; align-items: center; justify-content: center; z-index: 50; padding: 16px; }
.modal { background: #fff; border-radius: 14px; padding: 20px; width: 640px; max-width: 100%; max-height: 90vh; overflow: auto; }
.modal h3 { margin: 0 0 14px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-grid .wide { grid-column: span 2; }
.modal-acts { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }

.ku-toast { position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%); padding: 10px 18px; border-radius: 9px; color: #fff; font-size: .85rem; z-index: 60; box-shadow: 0 8px 24px rgba(0,0,0,.2); }
.ku-toast.s { background: #059669; } .ku-toast.e { background: #dc2626; }
.toast-enter-active, .toast-leave-active { transition: opacity .25s, transform .25s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translate(-50%, 10px); }

@media (max-width: 720px) {
  .form-grid { grid-template-columns: 1fr; }
  .form-grid .wide { grid-column: span 1; }
}
</style>
