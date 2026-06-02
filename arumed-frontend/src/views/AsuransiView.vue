<script setup>
/**
 * AsuransiView — Modul Asuransi/TPA Non-BPJS.
 *
 * 3 Tab:
 *  - Verifikasi Pending: list visit hari ini status PENDING, klik → form eligibility.
 *  - Klaim Management   : list + filter, action submit/status/resubmit, log timeline.
 *  - Aging Report       : klaim outstanding + flag overdue per insurer.sla_days.
 *
 * Spec: Docs/ARUMED_INSURANCE_TPA_MODULE.md
 */
import { ref, onMounted, computed, watch } from 'vue'
import { useAsuransiStore } from '@/stores/asuransiStore'
import { masterApi } from '@/services/api'

const store = useAsuransiStore()
const penjaminList = ref([])

const tab = ref('verifikasi')

// ─── Verifikasi Form ────────────────────────────────────────────────────────
const verifModal = ref({ open: false, visitId: null, insurerId: null, mode: 'create', id: null })
const verifForm = ref(emptyVerif())

function emptyVerif() {
  return {
    status: 'VERIFIED',
    policy_number: '', member_name: '', member_card_number: '',
    plafon_amount: null,
    copayment_percent: 0,
    copayment_amount: 0,
    covered_amount: null,
    coverage_notes: '',
    exclusion_flags: [],
    issue_notes: '',
  }
}

function openVerifForm(row) {
  verifModal.value = { open: true, visitId: row.visit_id, insurerId: row.insurer_id, mode: 'create', id: null }
  verifForm.value = emptyVerif()
  // Muat rincian tagihan pasien (read-only) supaya admin tahu total sebelum tentukan cover.
  store.billingDetail = null
  store.fetchBilling(row.visit_id).catch(() => {})
  // Prefill kalau sudah ada verifikasi awal (status PENDING dari admisi / sudah diverifikasi)
  store.fetchVerifikasi(row.visit_id).then(() => {
    const v = store.verifikasi
    if (v) {
      verifModal.value.mode = 'update'
      verifModal.value.id = v.id
      verifForm.value = {
        status: v.status === 'PENDING' ? 'VERIFIED' : v.status,
        policy_number: v.policy_number ?? '',
        member_name: v.member_name ?? '',
        member_card_number: v.member_card_number ?? '',
        // Backend cast decimal:2 → string "100000.00". Konversi ke integer (Rupiah
        // tidak pakai sen, copay % bulat). Round, bukan floor — jaga-jaga ada koma.
        plafon_amount: v.plafon_amount != null ? Math.round(Number(v.plafon_amount)) : null,
        copayment_percent: v.copayment_percent != null ? Math.round(Number(v.copayment_percent)) : 0,
        copayment_amount:  v.copayment_amount  != null ? Math.round(Number(v.copayment_amount))  : 0,
        covered_amount:    v.covered_amount    != null ? Math.round(Number(v.covered_amount))    : null,
        coverage_notes: v.coverage_notes ?? '',
        exclusion_flags: v.exclusion_flags ?? [],
        issue_notes: v.issue_notes ?? '',
      }
    }
  }).catch(() => {})
}

// Total tagihan riil dari billing detail (untuk panel & hitung selisih cover).
const billingTotal = computed(() => Number(store.billingDetail?.total ?? 0))
const billingHasInvoice = computed(() => !!store.billingDetail?.id)

// Selisih yang ditanggung pasien berdasarkan cover yang diinput vs total tagihan.
const coverSelisih = computed(() => {
  const total = billingTotal.value
  const covered = Number(verifForm.value.covered_amount)
  if (!billingHasInvoice.value || isNaN(covered)) return null
  const sisa = Math.max(0, total - covered)
  return { total, covered, sisa, fullCover: sisa <= 0 && covered > 0 }
})

// Isi cover = full (samakan dengan total tagihan).
function setCoverFull() {
  verifForm.value.covered_amount = Math.round(billingTotal.value)
}

async function submitVerif() {
  try {
    const payload = { ...verifForm.value }
    if (verifModal.value.mode === 'create') {
      await store.createVerifikasi({
        visit_id: verifModal.value.visitId,
        insurer_id: verifModal.value.insurerId,
        ...payload,
      })
    } else {
      await store.updateVerifikasi(verifModal.value.id, payload)
    }
    verifModal.value.open = false
    await store.fetchPendingVerifications()
    await store.fetchInServiceVerifications()
    await store.fetchSummary()
  } catch (e) {
    // store.error sudah di-set
  }
}

// ─── Klaim Filter & Modal ───────────────────────────────────────────────────
const klaimFilters = ref({ status: '', insurer_id: '', search: '', date_from: '', date_to: '' })
const submitModal = ref({ open: false, claim: null, submission_ref: '', notes: '', claim_amount: 0, documents_checklist: {} })
const statusModal = ref({ open: false, claim: null, status: 'APPROVED', approved_amount: 0, rejection_code: '', rejection_reason: '', appeal_notes: '' })
const resubmitModal = ref({ open: false, claim: null, submission_ref: '', notes: '', documents_checklist: {} })
const logModal = ref({ open: false, claim: null })

function openSubmitModal(c) {
  submitModal.value = {
    open: true, claim: c,
    submission_ref: c.submission_ref ?? '',
    notes: c.notes ?? '',
    claim_amount: c.claim_amount ?? 0,
    documents_checklist: { ...(c.documents_checklist ?? {}) },
  }
}

async function doSubmitKlaim() {
  await store.submitKlaim(submitModal.value.claim.id, {
    submission_ref: submitModal.value.submission_ref,
    notes: submitModal.value.notes,
    claim_amount: submitModal.value.claim_amount,
    documents_checklist: submitModal.value.documents_checklist,
  })
  submitModal.value.open = false
  refreshKlaims()
}

function openStatusModal(c) {
  statusModal.value = {
    open: true, claim: c,
    status: 'APPROVED',
    approved_amount: c.claim_amount ?? 0,
    rejection_code: '', rejection_reason: '', appeal_notes: '',
  }
}

async function doUpdateStatus() {
  const payload = { status: statusModal.value.status }
  if (statusModal.value.status === 'APPROVED') payload.approved_amount = statusModal.value.approved_amount
  if (statusModal.value.status === 'REJECTED') {
    payload.rejection_code = statusModal.value.rejection_code
    payload.rejection_reason = statusModal.value.rejection_reason
  }
  if (statusModal.value.status === 'APPEALED') payload.appeal_notes = statusModal.value.appeal_notes
  await store.updateStatusKlaim(statusModal.value.claim.id, payload)
  statusModal.value.open = false
  refreshKlaims()
}

function openResubmitModal(c) {
  resubmitModal.value = {
    open: true, claim: c,
    submission_ref: '', notes: c.notes ?? '',
    documents_checklist: { ...(c.documents_checklist ?? {}) },
  }
}

async function doResubmit() {
  await store.resubmitKlaim(resubmitModal.value.claim.id, {
    submission_ref: resubmitModal.value.submission_ref,
    notes: resubmitModal.value.notes,
    documents_checklist: resubmitModal.value.documents_checklist,
  })
  resubmitModal.value.open = false
  refreshKlaims()
}

async function openLogModal(c) {
  logModal.value = { open: true, claim: c }
  await store.fetchLogs(c.id)
}

function refreshKlaims() {
  store.fetchKlaims(klaimFilters.value)
  store.fetchSummary()
}

watch(klaimFilters, () => refreshKlaims(), { deep: true })

// Format tanggal LOKAL (YYYY-MM-DD) tanpa konversi UTC. toISOString() menggeser
// ke UTC → di WIB (UTC+7) tengah malam–07:00 bisa mundur 1 hari (filter salah tanggal).
function localYmd(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// Quick filter periode — set date_from/date_to dengan rentang umum.
const activePeriod = ref('') // '', 'today', 'week', 'month'
function setPeriod(p) {
  activePeriod.value = p
  const today = new Date()
  const fmt = (d) => localYmd(d)
  if (p === '') {
    klaimFilters.value.date_from = ''
    klaimFilters.value.date_to = ''
  } else if (p === 'today') {
    klaimFilters.value.date_from = fmt(today)
    klaimFilters.value.date_to = fmt(today)
  } else if (p === 'week') {
    const d = new Date(today); d.setDate(today.getDate() - 6)
    klaimFilters.value.date_from = fmt(d)
    klaimFilters.value.date_to = fmt(today)
  } else if (p === 'month') {
    const d = new Date(today.getFullYear(), today.getMonth(), 1)
    klaimFilters.value.date_from = fmt(d)
    klaimFilters.value.date_to = fmt(today)
  }
}

// ─── Aging Export CSV ───────────────────────────────────────────────────────
function exportAgingCsv() {
  const rows = store.aging
  const header = ['Patient', 'Insurer', 'Status', 'Age (days)', 'SLA', 'Overdue', 'Claim Amount', 'Submission Ref', 'Submitted At']
  const csv = [header.join(',')]
  rows.forEach((r) => {
    csv.push([
      JSON.stringify(r.patient_name ?? ''),
      JSON.stringify(r.insurer_name ?? ''),
      r.status,
      r.age_days,
      r.sla_days,
      r.is_overdue ? 'YES' : 'NO',
      r.claim_amount,
      JSON.stringify(r.submission_ref ?? ''),
      r.submitted_at ?? '',
    ].join(','))
  })
  const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = `aging-asuransi-${localYmd(new Date())}.csv`
  a.click()
}

// ─── Utilities ──────────────────────────────────────────────────────────────
// Ringkasan rekonsiliasi saat status klaim diubah ke APPROVED.
// Menampilkan selisih (claim − approved) yang otomatis jadi patient_responsibility di backend.
const approvedDiff = computed(() => {
  const claim = Number(statusModal.value?.claim?.claim_amount) || 0
  const approved = Number(statusModal.value?.approved_amount)
  if (!statusModal.value?.claim || statusModal.value.status !== 'APPROVED' || isNaN(approved)) return null
  return { claim, approved, gap: claim - approved }
})

// Preview hitungan copay untuk modal verifikasi — referensi edukasi, BUKAN keputusan.
// Asumsi: pakai aturan "co-pay % atau co-pay tetap, mana yg lebih besar" (umum di TPA).
// Tagihan dummy Rp 1jt supaya billing punya gambaran konkret.
const copayPreview = computed(() => {
  const pct  = Number(verifForm.value.copayment_percent) || 0
  const fix  = Number(verifForm.value.copayment_amount)  || 0
  const plaf = Number(verifForm.value.plafon_amount)     || 0
  if (pct === 0 && fix === 0 && !plaf) return null

  const DUMMY = 1_000_000
  const fromPct   = DUMMY * (pct / 100)
  const patientShare = Math.max(fromPct, fix)
  const tpaShare = Math.max(0, DUMMY - patientShare)

  let plafonWarn = null
  if (plaf && plaf < tpaShare) {
    plafonWarn = `Sisa plafon (${formatRp(plaf)}) lebih kecil dari estimasi klaim (${formatRp(tpaShare)}). Selisih ${formatRp(tpaShare - plaf)} jadi tanggungan pasien.`
  }

  return {
    patientShort: formatRp(patientShare),
    tpaShort: formatRp(tpaShare),
    plafonWarn,
  }
})

// Hitung progress checklist dokumen dari documents_checklist jsonb
// Format: {"Resume Medis": true, "Kwitansi Asli": false, ...}
function docProgress(claim) {
  const cl = claim?.documents_checklist
  if (!cl || typeof cl !== 'object') return null
  const keys = Object.keys(cl)
  if (!keys.length) return null
  const done = keys.filter((k) => !!cl[k]).length
  return { done, total: keys.length, complete: done === keys.length }
}

// Rupiah selalu integer (tanpa sen). Round dulu sebelum format.
const formatRp = (n) => 'Rp ' + Math.round(Number(n ?? 0)).toLocaleString('id-ID')
const formatDate = (s) => s ? new Date(s).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' }) : '—'
const statusPill = (s) => ({
  DRAFT: 'pill-gray', SUBMITTED: 'pill-info', APPROVED: 'pill-success',
  REJECTED: 'pill-danger', APPEALED: 'pill-warning',
  PENDING: 'pill-warning', VERIFIED: 'pill-success', ISSUE: 'pill-danger',
  NEEDS_CLARIFICATION: 'pill-warning', NONE: 'pill-gray',
}[s] ?? 'pill-gray')

// Daftar insurers TPA (filter type ASURANSI / PERUSAHAAN)
const insurerOptions = computed(() =>
  penjaminList.value.filter((i) => ['ASURANSI', 'PERUSAHAAN'].includes(i.type)),
)

async function loadPenjamin() {
  try {
    const { data } = await masterApi.penjamin.list({ per_page: 200 })
    const rows = data.data?.data ?? data.data ?? []
    penjaminList.value = Array.isArray(rows) ? rows : (rows.data ?? [])
  } catch {
    penjaminList.value = []
  }
}

onMounted(async () => {
  store.fetchPendingVerifications()
  store.fetchInServiceVerifications()
  store.fetchSummary()
  loadPenjamin()
})

watch(tab, (t) => {
  if (t === 'verifikasi') store.fetchPendingVerifications()
  if (t === 'dilayani') store.fetchInServiceVerifications()
  if (t === 'klaim') store.fetchKlaims(klaimFilters.value)
  if (t === 'aging') store.fetchAging()
})
</script>

<template>
  <div class="asuransi-view">
    <!-- HEADER + SUMMARY -->
    <div class="page-head">
      <div>
        <h1>Asuransi & Klaim TPA</h1>
        <p class="sub">Verifikasi eligibility manual + workflow klaim non-BPJS.</p>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background: var(--wb)">
          <svg viewBox="0 0 24 24" stroke="var(--wt)"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div><div class="stat-val">{{ store.summary.pending_verification }}</div><div class="stat-lbl">Verifikasi Pending</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background: var(--ib)">
          <svg viewBox="0 0 24 24" stroke="var(--it)"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
        </div>
        <div><div class="stat-val">{{ store.summary.draft_claims }}</div><div class="stat-lbl">Draft Klaim</div></div>
      </div>
      <div :class="['stat-card', store.summary.overdue_claims ? 'alert-card' : '']">
        <div class="stat-icon" :style="{ background: store.summary.overdue_claims ? 'var(--eb)' : 'var(--gl)' }">
          <svg viewBox="0 0 24 24" :stroke="store.summary.overdue_claims ? 'var(--et)' : 'var(--ga)'"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
        </div>
        <div><div class="stat-val" :style="{ color: store.summary.overdue_claims ? 'var(--et)' : '' }">{{ store.summary.overdue_claims }}</div><div class="stat-lbl">Klaim Overdue</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background: var(--sb)">
          <svg viewBox="0 0 24 24" stroke="var(--st)"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div><div class="stat-val" style="color: var(--st)">{{ store.summary.approved_this_month }}</div><div class="stat-lbl">Approved Bulan Ini</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background: var(--ib)">
          <svg viewBox="0 0 24 24" stroke="var(--it)"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg>
        </div>
        <div><div class="stat-val">{{ store.summary.submitted_this_month }}</div><div class="stat-lbl">Submitted Bulan Ini</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background: var(--eb)">
          <svg viewBox="0 0 24 24" stroke="var(--et)"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div><div class="stat-val" style="color: var(--et)">{{ store.summary.rejected_this_month }}</div><div class="stat-lbl">Rejected Bulan Ini</div></div>
      </div>
    </div>

    <!-- TABS -->
    <div class="nav-tabs">
      <button :class="['nt', tab === 'verifikasi' ? 'a' : '']" @click="tab = 'verifikasi'">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Verifikasi Pending
        <span v-if="store.summary.pending_verification" class="ntbg alert">{{ store.summary.pending_verification }}</span>
      </button>
      <button :class="['nt', tab === 'dilayani' ? 'a' : '']" @click="tab = 'dilayani'">
        <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Sedang Dilayani
        <span v-if="store.inServiceList.length" class="ntbg">{{ store.inServiceList.length }}</span>
      </button>
      <button :class="['nt', tab === 'klaim' ? 'a' : '']" @click="tab = 'klaim'">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
        Klaim Management
      </button>
      <button :class="['nt', tab === 'aging' ? 'a' : '']" @click="tab = 'aging'">
        <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        Aging Report
        <span v-if="store.summary.overdue_claims" class="ntbg alert">{{ store.summary.overdue_claims }} overdue</span>
      </button>
    </div>

    <!-- ─── TAB 1: VERIFIKASI PENDING ──────────────────────────────────── -->
    <div v-if="tab === 'verifikasi'" class="tab-pane">
      <div class="po-table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th style="width:50px">No.</th>
              <th>Pasien</th>
              <th>No. Rekam Medis</th>
              <th>Penjamin</th>
              <th>Penjamin Type</th>
              <th>No. Polis</th>
              <th>Nama Peserta</th>
              <th>No. Kartu</th>
              <th class="c">Menunggu</th>
              <th class="c">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="store.pendingLoading"><td colspan="10" class="po-state">Memuat…</td></tr>
            <tr v-else-if="!store.pendingList.length"><td colspan="10" class="po-state">Tidak ada verifikasi pending hari ini.</td></tr>
            <tr v-for="(row, idx) in store.pendingList" :key="row.visit_id">
              <td class="muted">{{ idx + 1 }}</td>
              <td>{{ row.patient_name }}</td>
              <td class="muted">{{ row.mrn ?? '—' }}</td>
              <td>{{ row.insurer_name }}</td>
              <td><span class="pill-gray">{{ row.guarantor_type }}</span></td>
              <td class="mono">{{ row.policy_number ?? '—' }}</td>
              <td>{{ row.member_name ?? '—' }}</td>
              <td class="mono muted">{{ row.member_card_number ?? '—' }}</td>
              <td class="c">
                <span :class="['wait', row.wait_minutes > 10 ? 'wait-danger' : '']">{{ row.wait_minutes }} mnt</span>
              </td>
              <td class="c">
                <button class="btn btn-sm btn-primary" @click="openVerifForm(row)">Verifikasi</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="hint">Tip: buka portal TPA secara manual untuk cek eligibility. Input hasil cek di form ini.</p>
    </div>

    <!-- ─── TAB: SEDANG DILAYANI ────────────────────────────────────────── -->
    <div v-if="tab === 'dilayani'" class="tab-pane">
      <div class="po-table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th style="width:50px">No.</th>
              <th>Pasien</th>
              <th>No. Rekam Medis</th>
              <th>Penjamin</th>
              <th>Verifikasi</th>
              <th>Stasiun</th>
              <th class="r">Total Tagihan</th>
              <th class="r">Ditanggung</th>
              <th class="r">Sisa Pasien</th>
              <th class="c">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="store.inServiceLoading"><td colspan="10" class="po-state">Memuat…</td></tr>
            <tr v-else-if="!store.inServiceList.length"><td colspan="10" class="po-state">Tidak ada pasien asuransi yang sedang dilayani.</td></tr>
            <tr v-for="(row, idx) in store.inServiceList" :key="row.visit_id">
              <td class="muted">{{ idx + 1 }}</td>
              <td>{{ row.patient_name }}</td>
              <td class="muted">{{ row.mrn ?? '—' }}</td>
              <td>{{ row.insurer_name }}</td>
              <td><span :class="['pill', statusPill(row.verif_status)]">{{ row.verif_status }}</span></td>
              <td class="muted">{{ row.current_station ?? '—' }}</td>
              <td class="r">{{ row.has_invoice ? formatRp(row.invoice_total) : '—' }}</td>
              <td class="r">{{ row.covered_amount ? formatRp(row.covered_amount) : '—' }}</td>
              <td class="r">
                <strong v-if="row.has_invoice" :style="row.patient_due > 0 ? 'color:var(--wt)' : 'color:var(--st)'">
                  {{ formatRp(row.patient_due) }}
                </strong>
                <span v-else class="muted">—</span>
              </td>
              <td class="c">
                <button class="btn btn-sm btn-primary" @click="openVerifForm(row)">Cover &amp; Detail</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="hint">Pasien yang sudah diverifikasi & belum lunas. Tentukan <strong>jumlah cover</strong> di sini — kasir hanya konfirmasi (full cover) atau tagih selisih.</p>
    </div>

    <!-- ─── TAB 2: KLAIM MANAGEMENT ─────────────────────────────────────── -->
    <div v-if="tab === 'klaim'" class="tab-pane">
      <div class="filter-bar">
        <div class="period-chips">
          <span class="chip-label">Periode:</span>
          <button :class="['chip', activePeriod === '' ? 'chip-on' : '']" @click="setPeriod('')">Semua</button>
          <button :class="['chip', activePeriod === 'today' ? 'chip-on' : '']" @click="setPeriod('today')">Hari Ini</button>
          <button :class="['chip', activePeriod === 'week' ? 'chip-on' : '']" @click="setPeriod('week')">7 Hari</button>
          <button :class="['chip', activePeriod === 'month' ? 'chip-on' : '']" @click="setPeriod('month')">Bulan Ini</button>
        </div>
        <select v-model="klaimFilters.status" class="filt">
          <option value="">Semua Status</option>
          <option value="DRAFT">DRAFT</option>
          <option value="SUBMITTED">SUBMITTED</option>
          <option value="APPROVED">APPROVED</option>
          <option value="REJECTED">REJECTED</option>
          <option value="APPEALED">APPEALED</option>
        </select>
        <select v-model="klaimFilters.insurer_id" class="filt">
          <option value="">Semua Penjamin</option>
          <option v-for="i in insurerOptions" :key="i.id" :value="i.id">{{ i.name }}</option>
        </select>
        <input type="date" v-model="klaimFilters.date_from" class="filt" title="Custom: dari tanggal" />
        <input type="date" v-model="klaimFilters.date_to" class="filt" title="Custom: sampai tanggal" />
        <input type="text" v-model="klaimFilters.search" placeholder="Cari pasien / nomor referensi…" class="filt search" />
      </div>

      <div class="po-table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th>Pasien</th>
              <th>Penjamin</th>
              <th>Status</th>
              <th class="r">Klaim</th>
              <th class="r">Approved</th>
              <th>Ref</th>
              <th>Submitted</th>
              <th class="c">Resubmit</th>
              <th class="c">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="store.klaimsLoading"><td colspan="9" class="po-state">Memuat…</td></tr>
            <tr v-else-if="!store.klaims.data?.length"><td colspan="9" class="po-state">Tidak ada klaim sesuai filter.</td></tr>
            <tr v-for="c in store.klaims.data" :key="c.id">
              <td>{{ c.visit?.patient?.name ?? '—' }}</td>
              <td>{{ c.insurer?.name ?? '—' }}</td>
              <td>
                <span :class="['pill', statusPill(c.status)]">{{ c.status }}</span>
                <span
                  v-if="docProgress(c)"
                  :class="['doc-badge', docProgress(c).complete ? 'db-ok' : 'db-pending']"
                  :title="`Checklist dokumen: ${docProgress(c).done} dari ${docProgress(c).total} dilengkapi`">
                  📎 {{ docProgress(c).done }}/{{ docProgress(c).total }}
                </span>
              </td>
              <td class="r">{{ formatRp(c.claim_amount) }}</td>
              <td class="r">{{ c.approved_amount ? formatRp(c.approved_amount) : '—' }}</td>
              <td class="muted">{{ c.submission_ref ?? '—' }}</td>
              <td class="muted">{{ formatDate(c.submitted_at) }}</td>
              <td class="c">{{ c.resubmission_count || 0 }}×</td>
              <td class="c">
                <div class="action-row">
                  <button v-if="c.status === 'DRAFT'" class="btn btn-sm btn-primary" @click="openSubmitModal(c)">Submit</button>
                  <button v-if="['SUBMITTED','APPEALED'].includes(c.status)" class="btn btn-sm btn-info" @click="openStatusModal(c)">Update Status</button>
                  <button v-if="c.status === 'REJECTED'" class="btn btn-sm btn-warning" @click="openResubmitModal(c)">Resubmit</button>
                  <button class="btn btn-sm btn-secondary" @click="openLogModal(c)">Log</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ─── TAB 3: AGING REPORT ─────────────────────────────────────────── -->
    <div v-if="tab === 'aging'" class="tab-pane">
      <div class="filter-bar">
        <div style="margin-left:auto">
          <button class="btn btn-secondary" @click="exportAgingCsv">⬇ Export CSV</button>
        </div>
      </div>

      <div class="po-table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th>Pasien</th>
              <th>Penjamin</th>
              <th>Status</th>
              <th class="r">Klaim</th>
              <th>Submission Ref</th>
              <th class="c">Usia (hari)</th>
              <th class="c">SLA</th>
              <th>Submitted</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="store.agingLoading"><td colspan="8" class="po-state">Memuat…</td></tr>
            <tr v-else-if="!store.aging.length"><td colspan="8" class="po-state">Tidak ada klaim outstanding.</td></tr>
            <tr v-for="a in store.aging" :key="a.id" :class="a.is_overdue ? 'row-overdue' : ''">
              <td>{{ a.patient_name }}</td>
              <td>{{ a.insurer_name }}</td>
              <td><span :class="['pill', statusPill(a.status)]">{{ a.status }}</span></td>
              <td class="r">{{ formatRp(a.claim_amount) }}</td>
              <td class="muted">{{ a.submission_ref ?? '—' }}</td>
              <td class="c"><strong :style="a.is_overdue ? 'color:var(--et)' : ''">{{ a.age_days }}</strong></td>
              <td class="c muted">{{ a.sla_days }}</td>
              <td class="muted">{{ formatDate(a.submitted_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ─── MODAL: Verifikasi Form ──────────────────────────────────────── -->
    <div v-if="verifModal.open" class="modal-overlay" @click.self="verifModal.open = false">
      <div class="modal" style="max-width:780px">
        <div class="modal-head">
          <h3>Verifikasi Eligibility Asuransi</h3>
          <button class="x" @click="verifModal.open = false">×</button>
        </div>
        <div class="modal-body">

          <!-- INSTRUKSI -->
          <div class="instr-box">
            <strong>Langkah:</strong> buka portal TPA (mis. Admedika, Inhealth, Allianz) →
            input nomor kartu pasien → catat hasil cek ke form di bawah.
          </div>

          <!-- RINCIAN TAGIHAN PASIEN (read-only) -->
          <div class="form-group billing-box">
            <div class="group-title">Rincian Tagihan Pasien
              <span class="group-hint">(referensi untuk menentukan jumlah cover)</span>
              <span v-if="billingHasInvoice" class="bill-count">{{ (store.billingDetail.items ?? []).length }} item</span>
            </div>
            <div v-if="store.billingLoading" class="po-state">Memuat tagihan…</div>
            <div v-else-if="!billingHasInvoice" class="bill-empty">
              Invoice belum dibuat — pasien belum sampai kasir / belum ada tindakan. Cover bisa diisi belakangan.
            </div>
            <template v-else>
              <!-- Area item: scroll internal supaya banyak item tidak mendorong form cover -->
              <div class="bill-scroll">
                <table class="bill-table">
                  <thead>
                    <tr><th>Keterangan</th><th class="c">Qty</th><th class="r">Net</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="it in (store.billingDetail.items ?? [])" :key="it.id">
                      <td>{{ it.description }}</td>
                      <td class="c">{{ it.quantity }}</td>
                      <td class="r">{{ formatRp(it.net_price ?? it.total_price) }}</td>
                    </tr>
                    <tr v-if="!(store.billingDetail.items ?? []).length"><td colspan="3" class="po-state">Belum ada item</td></tr>
                  </tbody>
                </table>
              </div>
              <!-- Total di luar area scroll → selalu terlihat (sticky) -->
              <div class="bill-total-bar">
                <span>Total Tagihan</span>
                <strong>{{ formatRp(store.billingDetail.total) }}</strong>
              </div>
              <div v-if="store.billingDetail.status === 'PAID'" class="bill-paid-warn">
                ⚠ Invoice sudah <strong>PAID</strong> — perubahan cover tidak akan mengubah tagihan yang sudah lunas.
              </div>
            </template>
          </div>

          <!-- GROUP 1: Status keputusan -->
          <div class="form-group">
            <div class="group-title">1. Hasil Cek Portal TPA</div>
            <div class="row">
              <label>Status<select v-model="verifForm.status" class="big-select">
                <option value="VERIFIED">✓ VERIFIED — Kartu aktif &amp; menanggung</option>
                <option value="NEEDS_CLARIFICATION">⚠ NEEDS_CLARIFICATION — Aktif, tapi ada masalah (plafon kurang/exclusion)</option>
                <option value="REJECTED">✗ REJECTED — Kartu tidak aktif / tidak menanggung</option>
              </select></label>
            </div>
          </div>

          <!-- GROUP 2: Data kartu -->
          <div class="form-group">
            <div class="group-title">2. Data Peserta (sesuai portal/kartu)</div>
            <div class="row r2">
              <label>Nomor Polis / Peserta<input v-model="verifForm.policy_number" placeholder="mis. 7871125121235" /></label>
              <label>Nama Peserta<input v-model="verifForm.member_name" placeholder="Sesuai portal" /></label>
            </div>
            <div class="row r2">
              <label>Nomor Kartu Fisik<input v-model="verifForm.member_card_number" placeholder="Jika berbeda dari no. polis" /></label>
              <div></div>
            </div>
          </div>

          <!-- GROUP 3: Plafon & Cost-sharing (yang sering bikin bingung) -->
          <div class="form-group">
            <div class="group-title">3. Plafon &amp; Cost-Sharing
              <span class="group-hint">(angka dari portal TPA — sistem TIDAK auto-hitung, hanya catat)</span>
            </div>

            <div class="row">
              <label>
                Sisa Plafon (Rp)
                <input v-model.number="verifForm.plafon_amount" type="number" min="0" step="1000" placeholder="Kosongkan jika unlimited" />
                <span class="field-help">
                  Sisa benefit yang masih bisa dipakai pasien.
                  <span v-if="verifForm.plafon_amount">≈ <strong>{{ formatRp(verifForm.plafon_amount) }}</strong></span>
                </span>
              </label>
            </div>

            <div class="row r2">
              <label>
                Co-payment Persen (%)
                <input v-model.number="verifForm.copayment_percent" type="number" step="1" min="0" max="100" placeholder="0" />
                <span class="field-help">% tagihan yg ditanggung pasien tiap kunjungan. Contoh: 20 = pasien bayar 20%.</span>
              </label>
              <label>
                Co-payment Tetap (Rp)
                <input v-model.number="verifForm.copayment_amount" type="number" min="0" step="1000" placeholder="0" />
                <span class="field-help">
                  Nominal fix/min yang ditanggung pasien per kunjungan.
                  <span v-if="verifForm.copayment_amount">≈ <strong>{{ formatRp(verifForm.copayment_amount) }}</strong></span>
                </span>
              </label>
            </div>

            <!-- Jumlah cover riil (nominal ditanggung asuransi untuk kunjungan ini) -->
            <div class="row">
              <label>
                Jumlah Ditanggung Asuransi (Rp)
                <div class="cover-input-wrap">
                  <input v-model.number="verifForm.covered_amount" type="number" min="0" step="1000" placeholder="Kosongkan jika belum ditentukan" />
                  <button v-if="billingHasInvoice" type="button" class="btn btn-sm btn-secondary" @click="setCoverFull">Full ({{ formatRp(billingTotal) }})</button>
                </div>
                <span class="field-help">
                  Nominal yang ditanggung TPA untuk tagihan kunjungan ini. Disinkron ke kasir:
                  sisa = total − ditanggung. <strong>Full cover</strong> → pasien tidak bayar, kasir cukup konfirmasi.
                </span>
              </label>
            </div>

            <!-- Ringkasan selisih cover vs total tagihan riil -->
            <div v-if="coverSelisih" :class="['preview-box', coverSelisih.fullCover ? 'cover-full' : '']">
              <strong>Selisih dengan tagihan riil:</strong>
              <div class="prev-line">Total tagihan: <strong>{{ formatRp(coverSelisih.total) }}</strong></div>
              <div class="prev-line">Ditanggung asuransi: <strong>{{ formatRp(coverSelisih.covered) }}</strong></div>
              <div class="prev-line" :style="coverSelisih.sisa > 0 ? 'color:var(--wt);font-weight:700' : 'color:var(--st);font-weight:700'">
                {{ coverSelisih.fullCover ? '✓ Ditanggung penuh — pasien tidak membayar (kasir konfirmasi).'
                   : `→ Sisa dibayar pasien: ${formatRp(coverSelisih.sisa)}` }}
              </div>
            </div>

            <!-- Preview hitungan (read-only edukasi) -->
            <div v-if="copayPreview" class="preview-box">
              <strong>Contoh perhitungan:</strong>
              <div class="prev-line">Jika tagihan kunjungan ini Rp 1.000.000:</div>
              <div class="prev-line">→ Pasien tanggung: <strong>{{ copayPreview.patientShort }}</strong></div>
              <div class="prev-line">→ TPA klaim: <strong>{{ copayPreview.tpaShort }}</strong></div>
              <div v-if="copayPreview.plafonWarn" class="prev-warn">
                ⚠ {{ copayPreview.plafonWarn }}
              </div>
              <div class="prev-note">Catatan: ini estimasi referensi. Aturan tiap polis bisa berbeda (mis. <em>max</em> vs <em>min</em>). Kasir tetap hitung manual saat tagihan riil.</div>
            </div>
          </div>

          <!-- GROUP 4: Notes -->
          <div class="form-group">
            <div class="group-title">4. Catatan</div>
            <div class="row">
              <label>
                Catatan Coverage <span class="opt">(opsional)</span>
                <textarea v-model="verifForm.coverage_notes" rows="2" placeholder="Mis: Cover rawat jalan, exclusion kacamata & LASIK"></textarea>
              </label>
            </div>
            <div v-if="['NEEDS_CLARIFICATION','REJECTED'].includes(verifForm.status)" class="row">
              <label>
                Catatan Issue <span class="req">(wajib)</span>
                <textarea v-model="verifForm.issue_notes" rows="2" placeholder="Mis: Sisa plafon Rp 500rb, estimasi tagihan Rp 800rb — pasien tanggung selisih Rp 300rb"></textarea>
                <span class="field-help">Akan dikirim sebagai notifikasi ke supervisor.</span>
              </label>
            </div>
          </div>

        </div>
        <div class="modal-foot">
          <button class="btn btn-secondary" @click="verifModal.open = false">Batal</button>
          <button class="btn btn-primary" :disabled="store.verifSaving" @click="submitVerif">
            {{ store.verifSaving ? 'Menyimpan…' : 'Simpan Verifikasi' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ─── MODAL: Submit Klaim ─────────────────────────────────────────── -->
    <div v-if="submitModal.open" class="modal-overlay" @click.self="submitModal.open = false">
      <div class="modal" style="max-width:700px">
        <div class="modal-head">
          <h3>Submit Klaim ke TPA</h3>
          <button class="x" @click="submitModal.open = false">×</button>
        </div>
        <div class="modal-body">
          <div class="row r2">
            <label>Nomor Referensi Portal TPA*<input v-model="submitModal.submission_ref" placeholder="Wajib" /></label>
            <label>Nominal Klaim (Rp)<input v-model.number="submitModal.claim_amount" type="number" /></label>
          </div>
          <div class="row">
            <label>Catatan<textarea v-model="submitModal.notes" rows="2"></textarea></label>
          </div>
          <div class="row">
            <label>Checklist Dokumen</label>
            <div class="checklist">
              <p v-if="!Object.keys(submitModal.documents_checklist ?? {}).length" class="muted small">
                Belum ada document requirement di master TPA. Silakan kosongkan checklist atau atur di Master Penjamin.
              </p>
              <label v-for="(checked, name) in submitModal.documents_checklist" :key="name" class="cb">
                <input type="checkbox" v-model="submitModal.documents_checklist[name]" />
                {{ name }}
              </label>
            </div>
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn btn-secondary" @click="submitModal.open = false">Batal</button>
          <button class="btn btn-primary" :disabled="store.klaimSaving || !submitModal.submission_ref" @click="doSubmitKlaim">
            {{ store.klaimSaving ? 'Mengirim…' : 'Submit Klaim' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ─── MODAL: Update Status ────────────────────────────────────────── -->
    <div v-if="statusModal.open" class="modal-overlay" @click.self="statusModal.open = false">
      <div class="modal" style="max-width:600px">
        <div class="modal-head">
          <h3>Update Status Klaim</h3>
          <button class="x" @click="statusModal.open = false">×</button>
        </div>
        <div class="modal-body">
          <!-- Info klaim referensi -->
          <div v-if="statusModal.claim" class="instr-box">
            <strong>Klaim:</strong>
            {{ statusModal.claim.visit?.patient?.name ?? '—' }}
            · {{ statusModal.claim.insurer?.name ?? '—' }}
            · Diajukan <strong>{{ formatRp(statusModal.claim.claim_amount) }}</strong>
          </div>

          <div class="row">
            <label>Status Baru<select v-model="statusModal.status" class="big-select">
              <option value="APPROVED">✓ APPROVED — TPA menyetujui klaim</option>
              <option value="REJECTED">✗ REJECTED — TPA menolak klaim</option>
              <option value="APPEALED">⚖ APPEALED — Klaim dibanding</option>
            </select></label>
          </div>

          <!-- APPROVED -->
          <template v-if="statusModal.status === 'APPROVED'">
            <div class="row">
              <label>
                Nominal Disetujui (Rp) <span class="req">*</span>
                <input v-model.number="statusModal.approved_amount" type="number" min="0" />
                <span class="field-help">
                  Sesuai approval dari portal TPA / surat persetujuan.
                  <span v-if="statusModal.approved_amount">≈ <strong>{{ formatRp(statusModal.approved_amount) }}</strong></span>
                </span>
              </label>
            </div>
            <div v-if="approvedDiff" class="preview-box">
              <strong>Ringkasan Rekonsiliasi:</strong>
              <div class="prev-line">Klaim diajukan:    <strong>{{ formatRp(statusModal.claim.claim_amount) }}</strong></div>
              <div class="prev-line">TPA setujui:       <strong>{{ formatRp(statusModal.approved_amount) }}</strong></div>
              <div v-if="approvedDiff.gap > 0" class="prev-warn">
                ⚠ Selisih <strong>{{ formatRp(approvedDiff.gap) }}</strong> tidak dicover TPA → otomatis tercatat sebagai <em>Patient Responsibility</em>.
                Tagih ke pasien manual via KasirView / SMS WA.
              </div>
              <div v-else-if="approvedDiff.gap === 0" class="prev-line" style="color:var(--st);font-weight:600">✓ Klaim disetujui penuh, tidak ada selisih.</div>
              <div v-else class="prev-warn">⚠ Approved &gt; klaim? Cek ulang nominal yang diinput.</div>
            </div>
          </template>

          <!-- REJECTED -->
          <template v-if="statusModal.status === 'REJECTED'">
            <div class="row r2">
              <label>Kode Reject<input v-model="statusModal.rejection_code" placeholder="mis. DOC-INCOMPLETE" /></label>
              <div></div>
            </div>
            <div class="row">
              <label>
                Alasan Reject <span class="req">*</span>
                <textarea v-model="statusModal.rejection_reason" rows="3" placeholder="Sesuai pesan rejection dari TPA"></textarea>
                <span class="field-help">Berdasarkan rejection, billing perlu revisi dokumen lalu Resubmit, atau tagih ke pasien jika exclusion.</span>
              </label>
            </div>
            <div v-if="statusModal.claim" class="preview-box">
              ⚠ Klaim sebesar <strong>{{ formatRp(statusModal.claim.claim_amount) }}</strong> akan jadi <em>Patient Responsibility</em>
              jika resubmit gagal. Pertimbangkan opsi APPEALED jika kasus borderline.
            </div>
          </template>

          <!-- APPEALED -->
          <template v-if="statusModal.status === 'APPEALED'">
            <div class="row">
              <label>
                Catatan Appeal <span class="req">*</span>
                <textarea v-model="statusModal.appeal_notes" rows="3" placeholder="Alasan banding, dokumen baru yang diserahkan, dll"></textarea>
              </label>
            </div>
          </template>
        </div>
        <div class="modal-foot">
          <button class="btn btn-secondary" @click="statusModal.open = false">Batal</button>
          <button class="btn btn-primary" :disabled="store.klaimSaving" @click="doUpdateStatus">Simpan</button>
        </div>
      </div>
    </div>

    <!-- ─── MODAL: Resubmit ─────────────────────────────────────────────── -->
    <div v-if="resubmitModal.open" class="modal-overlay" @click.self="resubmitModal.open = false">
      <div class="modal" style="max-width:600px">
        <div class="modal-head">
          <h3>Resubmit Klaim (Revisi)</h3>
          <button class="x" @click="resubmitModal.open = false">×</button>
        </div>
        <div class="modal-body">
          <div class="row">
            <label>Nomor Referensi Baru*<input v-model="resubmitModal.submission_ref" /></label>
          </div>
          <div class="row">
            <label>Catatan Revisi<textarea v-model="resubmitModal.notes" rows="2"></textarea></label>
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn btn-secondary" @click="resubmitModal.open = false">Batal</button>
          <button class="btn btn-primary" :disabled="store.klaimSaving || !resubmitModal.submission_ref" @click="doResubmit">Resubmit</button>
        </div>
      </div>
    </div>

    <!-- ─── MODAL: Log Timeline ─────────────────────────────────────────── -->
    <div v-if="logModal.open" class="modal-overlay" @click.self="logModal.open = false">
      <div class="modal" style="max-width:600px">
        <div class="modal-head">
          <h3>Log Klaim — {{ logModal.claim?.visit?.patient?.name ?? logModal.claim?.id }}</h3>
          <button class="x" @click="logModal.open = false">×</button>
        </div>
        <div class="modal-body">
          <div v-if="store.klaimLogsLoading" class="po-state">Memuat…</div>
          <div v-else-if="!store.klaimLogs.length" class="po-state">Belum ada log.</div>
          <div v-else class="log-timeline">
            <div v-for="l in store.klaimLogs" :key="l.id" class="log-item">
              <div class="log-dot" :class="statusPill(l.to_status ?? l.action)"></div>
              <div class="log-content">
                <div class="log-action"><strong>{{ l.action }}</strong> {{ l.from_status }} → {{ l.to_status ?? '—' }}</div>
                <div class="log-meta muted small">{{ formatDate(l.performed_at) }} oleh {{ l.performed_by?.name ?? '—' }}</div>
                <div v-if="l.notes" class="log-notes">{{ l.notes }}</div>
                <pre v-if="l.metadata && Object.keys(l.metadata).length" class="log-meta-json">{{ JSON.stringify(l.metadata, null, 2) }}</pre>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.asuransi-view { padding: 1rem 1.25rem; font-family: 'Inter', sans-serif; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1rem; }
.page-head h1 { font-family: 'Space Grotesk', serif; font-size: 22px; margin: 0; color: var(--td); }
.page-head .sub { font-size: 12px; color: var(--tu); margin: 4px 0 0; }

.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.6rem; margin-bottom: 1rem; }
.stat-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 11px; padding: 0.75rem; display: flex; align-items: center; gap: 9px; }
.stat-card.alert-card { border-color: var(--ebd); }
.stat-icon { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-icon svg { width: 16px; height: 16px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.stat-val { font-size: 18px; font-weight: 700; color: var(--td); font-family: 'Geist Mono', monospace; }
.stat-lbl { font-size: 10.5px; color: var(--tu); }

.nav-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); padding: 0 4px; margin-bottom: 1rem; }
.nt { padding: 0.6rem 1rem; font-size: 12px; font-weight: 500; color: var(--tu); background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: 'Inter', sans-serif; display: inline-flex; align-items: center; gap: 6px; }
.nt:hover { color: var(--td); }
.nt.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }
.nt svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.ntbg { font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 20px; background: var(--bs); color: var(--tm); }
.ntbg.alert { background: var(--eb); color: var(--et); border: 1px solid var(--ebd); }

.tab-pane { display: flex; flex-direction: column; gap: 0.85rem; }

.filter-bar { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
.filt { height: 32px; padding: 0 10px; border-radius: 7px; border: 1px solid var(--gb); background: var(--bc); font-size: 12px; font-family: inherit; }
.filt.search { flex: 1; min-width: 200px; }

.period-chips { display: inline-flex; gap: 4px; align-items: center; padding: 3px 7px; background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; }
.period-chips .chip-label { font-size: 11px; color: var(--tu); font-weight: 600; }
.chip { background: transparent; border: 1px solid transparent; padding: 3px 9px; border-radius: 5px; font-size: 11px; cursor: pointer; color: var(--tm); font-family: inherit; }
.chip:hover { background: var(--bc); color: var(--td); }
.chip.chip-on { background: var(--ga); color: #fff; font-weight: 600; }

.po-table-wrap { background: var(--bc); border: 1px solid var(--gb); border-radius: 8px; overflow-x: auto; }
.po-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.po-table th, .po-table td { padding: 9px 12px; text-align: left; border-bottom: 1px solid var(--gb); }
.po-table th { background: var(--bs); font-weight: 600; color: var(--tm); font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; }
.po-table td.r, .po-table th.r { text-align: right; font-variant-numeric: tabular-nums; }
.po-table td.c, .po-table th.c { text-align: center; }
.po-table td.muted, .po-table .muted { color: var(--tu); }
.po-table td.mono, .po-table .mono { font-family: 'Geist Mono', monospace; font-size: 12px; }
.po-table tbody tr:hover { background: var(--bs); }
.po-table tr.row-overdue td { background: rgba(239, 68, 68, 0.06); }
.po-state { text-align: center; padding: 24px; color: var(--tu); font-size: 12.5px; }

.wait { padding: 2px 7px; border-radius: 12px; background: var(--bs); color: var(--tm); font-size: 11px; font-weight: 600; }
.wait.wait-danger { background: var(--eb); color: var(--et); }

.pill { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.pill-gray { background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.pill-info { background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.pill-success { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.pill-danger { background: var(--eb); color: var(--et); border: 1px solid var(--ebd); }
.pill-warning { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

.doc-badge { display: inline-block; margin-left: 5px; font-size: 9.5px; font-weight: 700; padding: 2px 6px; border-radius: 10px; vertical-align: middle; }
.doc-badge.db-ok { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.doc-badge.db-pending { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

.action-row { display: inline-flex; gap: 4px; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 0 12px; height: 32px; border-radius: 7px; font-family: inherit; font-size: 12px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; }
.btn-sm { height: 26px; padding: 0 10px; font-size: 11px; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover:not(:disabled) { background: var(--gm); }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-info { background: var(--it); color: #fff; border-color: var(--it); }
.btn-warning { background: var(--lm); color: var(--td); border-color: var(--lm); }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover { border-color: var(--ga); color: var(--td); background: var(--gl); }

.hint { font-size: 11px; color: var(--tu); padding-left: 2px; }

/* MODAL */
.modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.45); z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.modal { background: var(--bc); border-radius: 12px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; }
.modal-head { padding: 0.9rem 1.2rem; background: linear-gradient(135deg, var(--gm), var(--gd)); color: #fff; display: flex; justify-content: space-between; align-items: center; }
.modal-head h3 { margin: 0; font-family: 'Space Grotesk', serif; font-size: 16px; }
.modal-head .x { background: transparent; border: none; color: #fff; font-size: 22px; cursor: pointer; line-height: 1; }
.modal-body { padding: 1rem 1.2rem; overflow-y: auto; display: flex; flex-direction: column; gap: 0.75rem; }
.modal-foot { padding: 0.75rem 1.2rem; border-top: 1px solid var(--gb); display: flex; justify-content: flex-end; gap: 0.5rem; background: var(--bs); }

.row { display: flex; flex-direction: column; }
.row.r2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem; }
.row label { display: flex; flex-direction: column; gap: 4px; font-size: 11px; font-weight: 600; color: var(--tm); }
.row input, .row select, .row textarea { padding: 7px 10px; border: 1px solid var(--gb); border-radius: 6px; font-size: 12.5px; font-family: inherit; background: var(--bc); }
.row input:focus, .row select:focus, .row textarea:focus { outline: none; border-color: var(--ga); }
.row .field-help { font-size: 10.5px; color: var(--tu); font-weight: 400; line-height: 1.35; margin-top: 2px; }
.row .req { color: var(--et); font-weight: 700; font-size: 10px; }
.row .opt { color: var(--tu); font-weight: 400; font-size: 10px; }

.instr-box { padding: 9px 13px; background: var(--ib); border: 1px solid var(--ibd); border-radius: 8px; color: var(--it); font-size: 11.5px; line-height: 1.5; }
.form-group { padding: 0.75rem 0.85rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; display: flex; flex-direction: column; gap: 0.6rem; }
.group-title { font-size: 11px; font-weight: 700; color: var(--td); text-transform: uppercase; letter-spacing: .04em; display: flex; align-items: center; gap: 4px; }
.group-hint { font-size: 10px; font-weight: 400; color: var(--tu); text-transform: none; letter-spacing: 0; margin-left: 4px; }
.big-select { font-size: 13px !important; padding: 9px 10px !important; font-weight: 500; }

.preview-box { background: var(--bc); border: 1px dashed var(--ga); border-radius: 8px; padding: 10px 13px; font-size: 11.5px; color: var(--tm); line-height: 1.55; }
.preview-box strong { color: var(--td); }
.prev-line { margin-top: 3px; }
.prev-warn { margin-top: 7px; padding: 6px 9px; background: var(--wb); border: 1px solid var(--wbd); border-radius: 5px; color: var(--wt); font-size: 11px; }
.prev-note { margin-top: 6px; font-size: 10.5px; color: var(--tu); font-style: italic; }

.checklist { display: flex; flex-direction: column; gap: 4px; max-height: 200px; overflow-y: auto; padding: 6px 0; }
.cb { display: flex; align-items: center; gap: 7px; font-size: 12.5px; font-weight: 400; cursor: pointer; }
.cb input { width: 15px; height: 15px; accent-color: var(--ga); }
.small { font-size: 11px; }

/* Log timeline */
.log-timeline { display: flex; flex-direction: column; gap: 0.7rem; }
.log-item { display: flex; gap: 0.6rem; padding-bottom: 0.5rem; border-bottom: 1px dashed var(--gb); }
.log-dot { width: 10px; height: 10px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; background: var(--ga); }
.log-content { flex: 1; min-width: 0; }
.log-action { font-size: 12px; color: var(--td); }
.log-meta { font-size: 11px; }
.log-notes { font-size: 12px; color: var(--tm); margin-top: 3px; }
.log-meta-json { font-size: 10.5px; background: var(--bs); padding: 5px 8px; border-radius: 5px; color: var(--tm); margin-top: 4px; max-height: 80px; overflow: auto; }

/* Billing detail panel (modal verifikasi) */
.billing-box { background: var(--bc); }
.bill-count { font-size: 10px; font-weight: 700; color: var(--tm); background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; padding: 1px 7px; margin-left: auto; text-transform: none; letter-spacing: 0; }
.bill-empty { font-size: 11.5px; color: var(--tu); padding: 6px 2px; }
/* Area item dengan scroll internal — banyak item tidak mendorong form cover ke bawah */
.bill-scroll { max-height: 220px; overflow-y: auto; border: 1px solid var(--gb); border-radius: 6px; }
.bill-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.bill-table th, .bill-table td { padding: 5px 8px; border-bottom: 1px solid var(--gb); text-align: left; }
.bill-table th { font-size: 10.5px; text-transform: uppercase; color: var(--tu); letter-spacing: .03em; position: sticky; top: 0; background: var(--bs); z-index: 1; }
.bill-table td.r, .bill-table th.r { text-align: right; font-variant-numeric: tabular-nums; }
.bill-table td.c, .bill-table th.c { text-align: center; }
.bill-table tbody tr:last-child td { border-bottom: none; }
/* Total selalu terlihat di bawah area scroll */
.bill-total-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; padding: 7px 9px; background: var(--bs); border: 1px solid var(--gb); border-radius: 6px; font-size: 12.5px; color: var(--td); }
.bill-total-bar strong { font-size: 14px; color: var(--td); font-family: 'Geist Mono', monospace; }
.bill-paid-warn { margin-top: 6px; font-size: 11px; color: var(--wt); background: var(--wb); border: 1px solid var(--wbd); border-radius: 5px; padding: 5px 9px; }

.cover-input-wrap { display: flex; gap: 6px; align-items: center; }
.cover-input-wrap input { flex: 1; }
.preview-box.cover-full { border-color: var(--sbd); border-style: solid; background: var(--sb); }

.ntbg:not(.alert) { background: var(--gl); color: var(--ga); border: 1px solid var(--ga); }
</style>
