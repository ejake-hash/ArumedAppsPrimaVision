<script setup>
import { ref, computed, watch } from 'vue'
import api from '@/services/api'

// ─── HELPERS ──────────────────────────────────────────────────────────────────
function fmtTgl(d) {
  if (!d) return '–'
  const [y, m, dd] = d.slice(0, 10).split('-')
  const bln = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des']
  return `${dd} ${bln[+m - 1]} ${y}`
}
function hitungUsia(dob) {
  if (!dob) return '–'
  const b = new Date(dob), n = new Date()
  let a = n.getFullYear() - b.getFullYear()
  if (n < new Date(n.getFullYear(), b.getMonth(), b.getDate())) a--
  return a
}
function classColor(c) {
  return ({ Baru:'#8abf44', Kontrol:'#1d4ed8', 'Pre-Op':'#b45309', 'Post-Op':'#15803d' })[c] ?? '#6b7280'
}
function guarantorCls(g) {
  return ({ BPJS:'bpjs', UMUM:'umum', ASURANSI:'asn', PERUSAHAAN:'asn', SOSIAL:'asn' })[g] ?? 'umum'
}

// ─── TOAST ────────────────────────────────────────────────────────────────────
const toasts = ref([])
let _tid = 0
function toast(type, msg) {
  const id = ++_tid
  toasts.value.push({ id, type, msg })
  setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id) }, 3200)
}

// ─── SEARCH ──────────────────────────────────────────────────────────────────
const searchMode     = ref('nama')
const searchQuery    = ref('')
const searchResults  = ref([])
const searching      = ref(false)
const showSearchDrop = ref(false)

const searchPlaceholder = computed(() =>
  ({ nama:'Cari nama pasien...', rm:'Cari No. Rekam Medis...', nik:'Cari NIK (16 digit)...' })[searchMode.value]
)

let _debounce = null
watch(searchQuery, (q) => {
  clearTimeout(_debounce)
  if (!q.trim()) { searchResults.value = []; return }
  _debounce = setTimeout(doSearch, 320)
})

async function doSearch() {
  const q = searchQuery.value.trim()
  if (!q) return
  searching.value = true
  try {
    const { data } = await api.get('/rekam-medis/pasien', { params: { keyword: q, mode: searchMode.value } })
    searchResults.value = data.data ?? []
  } catch { searchResults.value = [] }
  finally  { searching.value = false }
}

function setSearchMode(m) { searchMode.value = m; searchQuery.value = ''; searchResults.value = [] }
function hideDropLater() { setTimeout(() => { showSearchDrop.value = false }, 200) }

// ─── PATIENT STATE ────────────────────────────────────────────────────────────
const patient        = ref(null)
const visits         = ref([])
const loadingVisits  = ref(false)
const visitError     = ref(null)
const visitFilter    = ref('Semua')
const expandedVisit  = ref(null)
const addendumTarget = ref(null)
const addendumText   = ref('')
const savingAddendum = ref(false)

const docs        = ref([])
const loadingDocs = ref(false)
const docsFetched = ref(false)
const selDoc      = ref(null)

const activeRmeTab = ref('soap')

async function pickPatient(p) {
  patient.value        = p
  visits.value         = []
  visitError.value     = null
  expandedVisit.value  = null
  visitFilter.value    = 'Semua'
  activeRmeTab.value   = 'soap'
  docs.value           = []
  docsFetched.value    = false
  addendumTarget.value = null
  showSearchDrop.value = false
  searchQuery.value    = ''
  loadingVisits.value  = true

  try {
    const { data } = await api.get(`/rekam-medis/pasien/${p.id}/kunjungan`)
    visits.value = data.data ?? []
    if (visits.value.length) expandedVisit.value = visits.value[0].id
  } catch (err) {
    visitError.value = err.response?.data?.message ?? 'Gagal memuat riwayat kunjungan'
  } finally {
    loadingVisits.value = false
  }
}

function clearPatient() {
  patient.value = null; visits.value = []; docs.value = []; searchQuery.value = ''; expandedVisit.value = null
}

async function retryVisits() {
  if (!patient.value) return
  visitError.value = null
  loadingVisits.value = true
  try {
    const { data } = await api.get(`/rekam-medis/pasien/${patient.value.id}/kunjungan`)
    visits.value = data.data ?? []
    if (visits.value.length) expandedVisit.value = visits.value[0].id
  } catch (err) {
    visitError.value = err.response?.data?.message ?? 'Gagal memuat riwayat kunjungan'
  } finally {
    loadingVisits.value = false
  }
}

// ─── VISITS HELPERS ───────────────────────────────────────────────────────────
const filteredVisits = computed(() => {
  if (visitFilter.value === 'Semua')     return visits.value
  if (visitFilter.value === 'Bedah')     return visits.value.filter(v => ['Pre-Op','Post-Op'].includes(v.classification))
  if (visitFilter.value === 'Penunjang') return visits.value.filter(v => v.diagnostic_results?.length)
  return visits.value.filter(v => v.classification === visitFilter.value)
})

function vFilterCnt(f) {
  if (f === 'Bedah')     return visits.value.filter(v => ['Pre-Op','Post-Op'].includes(v.classification)).length
  if (f === 'Penunjang') return visits.value.filter(v => v.diagnostic_results?.length).length
  return visits.value.filter(v => v.classification === f).length
}

function tdStr(na)   { return na?.td_sistol ? `${na.td_sistol}/${na.td_diastol}` : '–' }
function tdHigh(na)  { return (na?.td_sistol ?? 0) >= 140 }
function spo2Low(na) { return (na?.spo2 ?? 100) < 95 }
function kgdCls(na)  { return na?.kgd > 200 ? 'hi' : na?.kgd > 140 ? 'warn' : '' }

function toggleExpand(id) { expandedVisit.value = expandedVisit.value === id ? null : id; addendumTarget.value = null }

function allRxItems(v) { return (v.prescriptions ?? []).flatMap(p => p.items ?? []) }

function diagnosisArray(de) {
  if (!de) return []
  const arr = []
  if (de.diagnosis_utama) arr.push({ kode: de.diagnosis_utama, nama: de.diagnosis_utama_nama ?? '', tipe: 'primer' })
  ;(de.diagnosis_sekunder ?? []).forEach(d => arr.push({ ...d, tipe: 'sekunder' }))
  return arr
}

const visitStats = computed(() => ({
  total:    visits.value.length,
  hasDx:    visits.value.filter(v => v.doctor_examination?.diagnosis_utama).length,
  lastDate: visits.value[0]?.visit_date ?? null,
}))

// ─── DOCUMENTS ───────────────────────────────────────────────────────────────
watch(activeRmeTab, async (tab) => {
  if (tab !== 'dokumen' || !patient.value || docsFetched.value) return
  loadingDocs.value = true
  try {
    const { data } = await api.get(`/rekam-medis/pasien/${patient.value.id}/dokumen`)
    docs.value = data.data ?? []; docsFetched.value = true
  } catch { docs.value = [] }
  finally  { loadingDocs.value = false }
})

const docsFinalCount = computed(() => docs.value.filter(d => d.status === 'FINAL').length)
function docStatusLabel(s) {
  return ({ FINAL:'Final', WAITING_SIGNATURE:'Menunggu TTD', DRAFT:'Draft', REJECTED:'Ditolak', VOID:'Void' })[s] ?? s
}
function docStatusCls(s) {
  return ({ FINAL:'final', WAITING_SIGNATURE:'waiting', DRAFT:'draft', REJECTED:'rejected', VOID:'void' })[s] ?? 'draft'
}

// ─── ADDENDUM ────────────────────────────────────────────────────────────────
async function saveAddendum(visit) {
  if (!addendumText.value.trim()) { toast('w', 'Teks addendum tidak boleh kosong'); return }
  savingAddendum.value = true
  try {
    const { data } = await api.post(`/rekam-medis/kunjungan/${visit.id}/addendum`, { isi: addendumText.value.trim() })
    if (!visit.addenda) visit.addenda = []
    visit.addenda.push(data.data)
    addendumTarget.value = null; addendumText.value = ''
    toast('s', 'Addendum berhasil dicatat')
  } catch { toast('w', 'Gagal menyimpan addendum') }
  finally  { savingAddendum.value = false }
}

// ─── MISC ────────────────────────────────────────────────────────────────────
function copyRm() { navigator.clipboard?.writeText(patient.value.no_rm); toast('s', `No. RM ${patient.value.no_rm} disalin`) }
function printResume() { window.print(); toast('i', 'Resume medis dikirim ke printer') }
function printDoc(doc) {
  const base = import.meta.env.VITE_API_URL ?? '/api/v1'
  window.open(`${base}/rekam-medis/dokumen/${doc.id}/cetak`, '_blank')
  toast('i', `Mencetak ${doc.document_type?.code}`)
}
</script>

<template>
  <div class="rme-page">

    <!-- ─── SEARCH BAR ─── -->
    <div class="rme-searchbar">
      <div class="rsb-inner">
        <div class="rsb-mode">
          <button v-for="m in [{v:'nama',l:'Nama'},{v:'rm',l:'No. RM'},{v:'nik',l:'NIK'}]" :key="m.v"
            :class="['rsb-mode-btn', searchMode===m.v?'a':'']" @click="setSearchMode(m.v)">{{ m.l }}</button>
        </div>
        <div class="rsb-input-wrap">
          <svg class="rsb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
          <input v-model="searchQuery" class="rsb-input" :placeholder="searchPlaceholder"
            @focus="showSearchDrop=true" @blur="hideDropLater" />
          <div v-if="searching" class="rsb-spinner"></div>
          <button v-if="patient" class="rsb-clear" @click="clearPatient">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            Ganti Pasien
          </button>
        </div>

        <!-- Dropdown -->
        <div v-if="showSearchDrop && !patient" class="rsb-dropdown">
          <!-- Skeleton while searching -->
          <template v-if="searching">
            <div v-for="i in 3" :key="i" class="rsb-item rsb-sk">
              <div class="rsi-bar" style="background:var(--gb)"></div>
              <div style="flex:1">
                <div class="sk-line w50 mb4"></div>
                <div class="sk-line w70 mb4"></div>
                <div class="sk-line w40"></div>
              </div>
            </div>
          </template>
          <template v-else>
            <div v-for="p in searchResults" :key="p.id" class="rsb-item" @mousedown.prevent="pickPatient(p)">
              <div :class="['rsi-bar', guarantorCls(p.last_guarantor_type)]"></div>
              <div class="rsi-body">
                <div class="rsi-top">
                  <span class="rsi-rm">{{ p.no_rm }}</span>
                  <span class="rsi-cnt">{{ p.visit_count ?? 0 }} kunjungan</span>
                </div>
                <div class="rsi-name">{{ p.nama }}</div>
                <div class="rsi-meta">{{ hitungUsia(p.date_of_birth) }} th · {{ p.gender === 'L' ? 'Laki-laki' : 'Perempuan' }} · {{ p.last_guarantor_type ?? 'UMUM' }}</div>
              </div>
            </div>
            <div v-if="!searchResults.length && searchQuery.trim()" class="rsb-empty">Tidak ada pasien yang cocok</div>
            <div v-if="!searchQuery.trim()" class="rsb-empty">Ketik untuk mencari pasien…</div>
          </template>
        </div>
      </div>
    </div>

    <!-- ─── MAIN CONTENT ─── -->
    <div class="rme-content">

      <!-- EMPTY STATE -->
      <div v-if="!patient" class="empty">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        <p>Cari dan pilih pasien dari kolom pencarian di atas<br/>untuk melihat Rekam Medis Elektronik</p>
      </div>

      <template v-if="patient">

        <!-- PATIENT PROFILE BAR -->
        <div class="ptb">
          <div class="ptav">{{ patient.nama?.charAt(0) }}</div>
          <div class="pti">
            <div class="ptn">{{ patient.nama }}</div>
            <div class="ptm">{{ hitungUsia(patient.date_of_birth) }} th · {{ patient.gender === 'L' ? 'Laki-laki' : 'Perempuan' }} · {{ patient.address }}</div>
            <div class="ptags">
              <span :class="['ptg', `ptg-${guarantorCls(patient.last_guarantor_type)}`]">
                {{ patient.last_guarantor_type ?? 'UMUM' }}
              </span>
              <span v-if="patient.allergy" class="ptg ptg-r">⚠ Alergi: {{ patient.allergy }}</span>
              <button class="copy-rm-btn" @click="copyRm" title="Salin nomor RM">
                <svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                {{ patient.no_rm }}
              </button>
            </div>
          </div>
          <div class="pt-stats">
            <div class="pt-stat">
              <div class="pt-stat-v">{{ visitStats.total }}</div>
              <div class="pt-stat-l">Kunjungan</div>
            </div>
            <div class="pt-stat">
              <div class="pt-stat-v">{{ visitStats.hasDx }}</div>
              <div class="pt-stat-l">Diagnosis</div>
            </div>
            <div class="pt-stat">
              <div class="pt-stat-v">{{ visitStats.lastDate ? fmtTgl(visitStats.lastDate) : '–' }}</div>
              <div class="pt-stat-l">Kunjungan Terakhir</div>
            </div>
          </div>
          <button class="print-btn" @click="printResume">
            <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Cetak Resume
          </button>
        </div>

        <!-- RME TABS -->
        <div class="rme-tabs">
          <button :class="['rmt', activeRmeTab==='soap'?'a':'']" @click="activeRmeTab='soap'">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            SOAP
          </button>
          <button :class="['rmt', activeRmeTab==='dokumen'?'a':'']" @click="activeRmeTab='dokumen'">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Dokumen
            <span v-if="docsFetched" class="rmt-ct">{{ docsFinalCount }}/{{ docs.length }}</span>
          </button>
        </div>

        <!-- ── TAB: SOAP ── -->
        <template v-if="activeRmeTab === 'soap'">
          <!-- Visit filter tabs -->
          <div class="visit-tabs">
            <button v-for="f in ['Semua','Kontrol','Bedah','Penunjang','Baru']" :key="f"
              :class="['vt', visitFilter===f?'a':'']" @click="visitFilter=f">
              {{ f }}
              <span v-if="f !== 'Semua'" class="vt-cnt">{{ vFilterCnt(f) }}</span>
            </button>
          </div>

          <!-- Timeline -->
          <div class="timeline-area">

            <!-- Loading skeleton -->
            <div v-if="loadingVisits" class="timeline">
              <div v-for="i in 3" :key="i" class="tl-item">
                <div class="tl-dot" style="background:var(--gb)"></div>
                <div class="sk-card">
                  <div class="sk-line w30 mb6"></div>
                  <div class="sk-line w60 mb4"></div>
                  <div class="sk-line w45"></div>
                </div>
              </div>
            </div>

            <!-- Error state -->
            <div v-else-if="visitError" class="error-state">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <span>{{ visitError }}</span>
              <button class="retry-btn" @click="retryVisits">Coba Lagi</button>
            </div>

            <!-- Empty filter -->
            <div v-else-if="!filteredVisits.length" class="empty-filter">Tidak ada kunjungan dengan filter ini</div>

            <!-- Visit cards -->
            <div v-else class="timeline">
              <div v-for="v in filteredVisits" :key="v.id" class="tl-item">
                <div class="tl-dot" :style="{ background: classColor(v.classification) }"></div>

                <div :class="['visit-card', expandedVisit === v.id ? 'open' : '']">
                  <!-- Header -->
                  <div class="vc-header" @click="toggleExpand(v.id)">
                    <div class="vc-left">
                      <span class="jenis-badge" :style="{ background: classColor(v.classification)+'20', color: classColor(v.classification), border: '1px solid '+classColor(v.classification)+'55' }">
                        {{ v.classification }}
                      </span>
                      <div class="vc-date">{{ fmtTgl(v.visit_date) }}<span v-if="v.visit_time"> · {{ v.visit_time }}</span></div>
                      <div class="vc-doctor">{{ v.doctor_name ?? '–' }}<span v-if="v.poli_name"> · {{ v.poli_name }}</span></div>
                    </div>
                    <div class="vc-right">
                      <div v-if="v.doctor_examination?.diagnosis_utama" class="vc-main-dx">
                        {{ v.doctor_examination.diagnosis_utama }} — {{ v.doctor_examination.diagnosis_utama_nama }}
                      </div>
                      <div class="vc-badges">
                        <span v-if="v.is_finalized" class="badge-finalized">Terfinalisasi</span>
                        <span v-if="v.diagnostic_results?.length" class="badge-penunjang">{{ v.diagnostic_results.length }} Penunjang</span>
                        <span v-if="v.addenda?.length" class="badge-addendum">{{ v.addenda.length }} Addendum</span>
                        <span v-if="v.no_sep" class="badge-sep">SEP</span>
                      </div>
                      <div class="vc-toggle">{{ expandedVisit === v.id ? '▲' : '▼' }}</div>
                    </div>
                  </div>

                  <!-- Body (expanded) -->
                  <div v-if="expandedVisit === v.id" class="vc-body">
                    <div class="vc-row-grid">
                      <!-- Keluhan -->
                      <div class="vc-section">
                        <div class="vcs-title">Keluhan Utama</div>
                        <div class="keluhan-text">{{ v.nurse_assessment?.keluhan_utama || '–' }}</div>
                      </div>
                      <!-- Vitals -->
                      <div class="vc-section">
                        <div class="vcs-title">Tanda-Tanda Vital</div>
                        <div class="vitals-grid">
                          <div class="vg-item">
                            <span class="vg-l">TD</span>
                            <span :class="['vg-v', tdHigh(v.nurse_assessment)?'hi':'']">{{ tdStr(v.nurse_assessment) }} mmHg</span>
                          </div>
                          <div class="vg-item">
                            <span class="vg-l">Nadi</span>
                            <span class="vg-v">{{ v.nurse_assessment?.nadi ?? '–' }} bpm</span>
                          </div>
                          <div class="vg-item">
                            <span class="vg-l">SpO₂</span>
                            <span :class="['vg-v', spo2Low(v.nurse_assessment)?'hi':'']">{{ v.nurse_assessment?.spo2 ?? '–' }}%</span>
                          </div>
                          <div class="vg-item">
                            <span class="vg-l">Suhu</span>
                            <span class="vg-v">{{ v.nurse_assessment?.suhu ?? '–' }}°C</span>
                          </div>
                          <div class="vg-item">
                            <span class="vg-l">Respirasi</span>
                            <span class="vg-v">{{ v.nurse_assessment?.respirasi ?? '–' }} /min</span>
                          </div>
                          <div class="vg-item">
                            <span class="vg-l">KGD</span>
                            <span :class="['vg-v', kgdCls(v.nurse_assessment)]">{{ v.nurse_assessment?.kgd ?? '–' }} mg/dL</span>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- SOAP -->
                    <div v-if="v.doctor_examination" class="vc-section">
                      <div class="vcs-title">Catatan SOAP</div>
                      <div class="soap-grid">
                        <div class="soap-row"><span class="soap-lbl">S</span><span class="soap-val">{{ v.doctor_examination.soap_subjective || '–' }}</span></div>
                        <div class="soap-row"><span class="soap-lbl">O</span><span class="soap-val">{{ v.doctor_examination.soap_objective || '–' }}</span></div>
                        <div class="soap-row"><span class="soap-lbl">A</span><span class="soap-val">{{ v.doctor_examination.soap_assessment || '–' }}</span></div>
                        <div class="soap-row"><span class="soap-lbl">P</span><span class="soap-val">{{ v.doctor_examination.soap_plan || '–' }}</span></div>
                      </div>
                    </div>

                    <div class="vc-row-grid">
                      <!-- Diagnosa -->
                      <div class="vc-section">
                        <div class="vcs-title">Diagnosis ICD-10</div>
                        <template v-if="diagnosisArray(v.doctor_examination).length">
                          <div v-for="dx in diagnosisArray(v.doctor_examination)" :key="dx.kode" class="dx-row">
                            <span :class="['dx-type', dx.tipe==='primer'?'pr':'sk']">{{ dx.tipe==='primer'?'Primer':'Sekunder' }}</span>
                            <span class="dx-kode">{{ dx.kode }}</span>
                            <span class="dx-nama">{{ dx.nama }}</span>
                          </div>
                        </template>
                        <div v-else class="empty-mini">Belum ada diagnosis</div>
                      </div>

                      <!-- Resep -->
                      <div class="vc-section">
                        <div class="vcs-title">Resep</div>
                        <div v-if="allRxItems(v).length" class="resep-list">
                          <div v-for="(item, i) in allRxItems(v)" :key="i" class="resep-row">
                            <div class="resep-name">{{ item.medication?.nama ?? item.medication_name ?? '–' }}</div>
                            <div class="resep-detail">{{ item.dosage }} · {{ item.frequency }} · {{ item.duration }}</div>
                          </div>
                        </div>
                        <div v-else class="empty-mini">Tidak ada resep</div>
                      </div>
                    </div>

                    <!-- Penunjang -->
                    <div v-if="v.diagnostic_results?.length" class="vc-section">
                      <div class="vcs-title">Hasil Pemeriksaan Penunjang</div>
                      <div v-for="r in v.diagnostic_results" :key="r.id" class="pj-result-row">
                        <span class="pj-type-badge">{{ r.order?.test_type ?? r.test_type }}</span>
                        <span class="pj-hasil">{{ r.expertise_data?.hasil ?? r.expertise_data ?? '–' }}</span>
                      </div>
                    </div>

                    <!-- Addenda -->
                    <div v-if="v.addenda?.length" class="vc-section">
                      <div class="vcs-title">Addendum</div>
                      <div v-for="(a, i) in v.addenda" :key="i" class="addendum-row">
                        <span class="add-tgl">{{ fmtTgl(a.created_at ?? a.tgl) }}</span>
                        <span class="add-oleh">{{ a.created_by ?? a.oleh }}</span>
                        <span class="add-isi">{{ a.isi }}</span>
                      </div>
                    </div>

                    <!-- Addendum input -->
                    <div v-if="addendumTarget === v.id" class="addendum-input-row">
                      <textarea v-model="addendumText" class="add-textarea" rows="2" placeholder="Tulis addendum — catatan koreksi atau tambahan pada rekam medis ini..."></textarea>
                      <div style="display:flex;gap:.4rem;margin-top:.4rem">
                        <button class="btn-add-save" :disabled="savingAddendum" @click="saveAddendum(v)">
                          {{ savingAddendum ? 'Menyimpan…' : 'Simpan Addendum' }}
                        </button>
                        <button class="btn-add-cancel" @click="addendumTarget=null">Batal</button>
                      </div>
                    </div>

                    <!-- Card actions -->
                    <div class="vc-actions">
                      <div class="vc-meta">
                        <span v-if="v.no_sep" class="sep-info">SEP: {{ v.no_sep }}</span>
                        <span v-if="v.is_finalized" class="finalized-info">
                          <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                          RME Terfinalisasi
                        </span>
                      </div>
                      <button v-if="addendumTarget !== v.id" class="btn-addendum" @click="addendumTarget=v.id">
                        <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Addendum
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </template>

        <!-- ── TAB: DOKUMEN ── -->
        <div v-if="activeRmeTab === 'dokumen'" class="dok-panel">
          <div class="dok-header">
            <div class="dok-title">Dokumen Rekam Medis</div>
            <div class="dok-subtitle">
              <template v-if="loadingDocs">Memuat dokumen…</template>
              <template v-else>{{ docsFinalCount }} dari {{ docs.length }} dokumen final</template>
            </div>
          </div>

          <!-- Skeleton -->
          <div v-if="loadingDocs" class="dok-list">
            <div v-for="i in 5" :key="i" class="dok-row">
              <div class="sk-icon-wrap"></div>
              <div style="flex:1">
                <div class="sk-line w25 mb4"></div>
                <div class="sk-line w55 mb4"></div>
                <div class="sk-line w40"></div>
              </div>
            </div>
          </div>

          <div v-else-if="!docs.length" class="empty-filter">Belum ada dokumen untuk pasien ini</div>

          <div v-else class="dok-list">
            <div v-for="doc in docs" :key="doc.id" class="dok-row">
              <div :class="['dok-icon-wrap', docStatusCls(doc.status)]">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>
                </svg>
              </div>
              <div class="dok-info">
                <div class="dok-kode">{{ doc.document_type?.code }}</div>
                <div class="dok-nama">{{ doc.document_type?.name }}</div>
                <div class="dok-meta">
                  {{ doc.created_by_station }}
                  <span v-if="doc.document_number"> · {{ doc.document_number }}</span>
                  <span v-if="doc.visit?.visit_date"> · {{ fmtTgl(doc.visit.visit_date) }}</span>
                  <span v-if="doc.printed_count"> · Dicetak {{ doc.printed_count }}×</span>
                </div>
              </div>
              <span :class="['dok-status', docStatusCls(doc.status)]">
                <svg v-if="doc.status==='FINAL'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                {{ docStatusLabel(doc.status) }}
              </span>
              <div class="dok-actions">
                <button class="dok-btn view" @click="selDoc=doc">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  Lihat
                </button>
                <button v-if="doc.status==='FINAL'" class="dok-btn print" @click="printDoc(doc)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                  Print
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- PRINT RESUME (hidden, shown on print) -->
        <div class="resume-print">
          <div class="rp-header">
            <strong>KLINIK MATA ARUNIKA CILEGON</strong><br/>
            Jl. Klinik No.1, Cilegon · PMK No. 24/2022
          </div>
          <h2>RESUME MEDIS RAWAT JALAN</h2>
          <table class="rp-table">
            <tr><td>Nama</td><td>{{ patient.nama }}</td><td>No. RM</td><td>{{ patient.no_rm }}</td></tr>
            <tr><td>Tgl. Lahir</td><td>{{ fmtTgl(patient.date_of_birth) }}</td><td>NIK</td><td>{{ patient.nik }}</td></tr>
            <tr><td>Jenis Kelamin</td><td>{{ patient.gender === 'L' ? 'Laki-laki' : 'Perempuan' }}</td><td>Penjamin</td><td>{{ patient.last_guarantor_type }}</td></tr>
            <tr><td>Alamat</td><td colspan="3">{{ patient.address }}</td></tr>
          </table>
          <h3>Riwayat Kunjungan</h3>
          <div v-for="v in visits" :key="v.id" class="rp-visit">
            <strong>{{ fmtTgl(v.visit_date) }} — {{ v.classification }} — {{ v.doctor_name }}</strong>
            <div v-if="v.nurse_assessment?.keluhan_utama">Keluhan: {{ v.nurse_assessment.keluhan_utama }}</div>
            <div v-if="v.doctor_examination?.diagnosis_utama">
              Diagnosis: {{ v.doctor_examination.diagnosis_utama }} {{ v.doctor_examination.diagnosis_utama_nama }}
            </div>
          </div>
          <div class="rp-footer">Dicetak: {{ new Date().toLocaleDateString('id-ID') }} · Arumed Apps v1.0</div>
        </div>
      </template>
    </div>

    <!-- ─── DOCUMENT MODAL ─── -->
    <div v-if="selDoc" class="doc-ov" @click.self="selDoc=null">
      <div class="doc-mbx">
        <div class="doc-mh">
          <div>
            <div class="doc-mht">{{ selDoc.document_type?.code }} — {{ selDoc.document_type?.name }}</div>
            <div class="doc-msub">{{ selDoc.created_by_station }} · Status: {{ docStatusLabel(selDoc.status) }}</div>
          </div>
          <div style="display:flex;gap:.4rem">
            <button v-if="selDoc.status==='FINAL'" class="doc-mbtn print" @click="printDoc(selDoc)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              Print
            </button>
            <button class="doc-mcl" @click="selDoc=null">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
        </div>
        <div class="doc-mb">
          <div v-if="patient" class="doc-patient-bar">
            Pasien: <strong>{{ patient.nama }}</strong> · {{ patient.no_rm }}
            <span v-if="selDoc.visit?.visit_date"> · {{ fmtTgl(selDoc.visit.visit_date) }}</span>
          </div>
          <div :class="['doc-status-block', docStatusCls(selDoc.status)]">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <template v-if="selDoc.status==='FINAL'"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></template>
              <template v-else><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></template>
            </svg>
            <div>
              <div class="dsb-title">{{ docStatusLabel(selDoc.status) }}</div>
              <div class="dsb-desc">
                <template v-if="selDoc.status === 'FINAL'">
                  Dokumen telah final{{ selDoc.document_number ? ` · No: ${selDoc.document_number}` : '' }}.
                  <span v-if="selDoc.printed_count"> Dicetak {{ selDoc.printed_count }} kali.</span>
                </template>
                <template v-else-if="selDoc.status === 'WAITING_SIGNATURE'">
                  Menunggu tanda tangan. Akses dari stasiun <strong>{{ selDoc.created_by_station }}</strong>.
                </template>
                <template v-else-if="selDoc.status === 'REJECTED'">
                  Dokumen ditolak. Perbaiki melalui stasiun <strong>{{ selDoc.created_by_station }}</strong>.
                </template>
                <template v-else>
                  Draft. Isi dan submit melalui stasiun <strong>{{ selDoc.created_by_station }}</strong>.
                </template>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── TOAST ─── -->
    <div class="toast-wrap">
      <div v-for="t in toasts" :key="t.id" :class="['toast', 'toast-'+t.type]">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
/* ─── PAGE LAYOUT ─── */
.rme-page { display: flex; flex-direction: column; gap: .75rem; }

/* ─── SEARCH BAR ─── */
.rme-searchbar { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: .65rem .9rem; }
.rsb-inner { position: relative; }
.rsb-mode { display: flex; background: var(--bs); border-radius: 8px; padding: 2px; gap: 2px; margin-bottom: .5rem; max-width: 240px; }
.rsb-mode-btn { flex: 1; height: 26px; border: none; border-radius: 6px; background: transparent; color: var(--tu); font-size: 11px; font-weight: 500; cursor: pointer; font-family: 'DM Sans',sans-serif; transition: all .15s; }
.rsb-mode-btn:hover { color: var(--td); }
.rsb-mode-btn.a { background: var(--bc); color: var(--gd); font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.rsb-input-wrap { display: flex; align-items: center; gap: .5rem; }
.rsb-icon { width: 14px; height: 14px; flex-shrink: 0; color: var(--tu); }
.rsb-input { flex: 1; height: 34px; font-size: 12.5px; border: 1.5px solid var(--gb); border-radius: 8px; padding: 0 10px; background: var(--bs); font-family: 'DM Sans',sans-serif; outline: none; color: var(--td); }
.rsb-input:focus { border-color: var(--ga); background: #fff; }
.rsb-spinner { width: 14px; height: 14px; border: 2px solid var(--gb); border-top-color: var(--ga); border-radius: 50%; animation: spin .6s linear infinite; flex-shrink: 0; }
@keyframes spin { to { transform: rotate(360deg); } }
.rsb-clear { display: inline-flex; align-items: center; gap: 5px; padding: 0 12px; height: 34px; border: 1.5px solid var(--gb); border-radius: 8px; background: var(--bs); color: var(--tu); font-size: 11px; font-weight: 600; font-family: 'DM Sans',sans-serif; cursor: pointer; transition: all .15s; }
.rsb-clear:hover { border-color: var(--et); color: var(--et); }
.rsb-clear svg { width: 12px; height: 12px; }
.rsb-dropdown { position: absolute; top: calc(100% + 6px); left: 0; right: 0; background: var(--bc); border: 1px solid var(--ga); border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 100; max-height: 300px; overflow-y: auto; }
.rsb-item { display: flex; align-items: center; gap: 8px; padding: 8px 12px; cursor: pointer; transition: background .12s; }
.rsb-item:hover { background: var(--gl); }
.rsb-sk { pointer-events: none; }
.rsi-bar { width: 3px; height: 40px; border-radius: 2px; flex-shrink: 0; }
.rsi-bar.bpjs { background: #3b82f6; }
.rsi-bar.umum { background: var(--lm); }
.rsi-bar.asn  { background: var(--pt); }
.rsi-body { flex: 1; min-width: 0; }
.rsi-top { display: flex; align-items: center; gap: 6px; margin-bottom: 1px; }
.rsi-rm { font-size: 10.5px; font-weight: 700; color: var(--it); font-family: monospace; }
.rsi-cnt { font-size: 9px; color: var(--tu); background: var(--bs); padding: 1px 6px; border-radius: 10px; border: 1px solid var(--gb); }
.rsi-name { font-size: 13px; font-weight: 600; color: var(--td); }
.rsi-meta { font-size: 10px; color: var(--tu); }
.rsb-empty { padding: 1rem; text-align: center; font-size: 11.5px; color: var(--th); }

/* ─── SKELETON ─── */
.sk-line { height: 8px; border-radius: 4px; background: var(--gb); animation: shimmer 1.4s ease infinite; }
.sk-line.w25 { width: 25%; }
.sk-line.w30 { width: 30%; }
.sk-line.w40 { width: 40%; }
.sk-line.w45 { width: 45%; }
.sk-line.w50 { width: 50%; }
.sk-line.w55 { width: 55%; }
.sk-line.w60 { width: 60%; }
.sk-line.w70 { width: 70%; }
.mb4 { margin-bottom: 4px; }
.mb6 { margin-bottom: 6px; }
@keyframes shimmer { 0%,100%{opacity:.5} 50%{opacity:1} }
.sk-card { background: var(--bc); border: 1.5px solid var(--gb); border-radius: 11px; padding: .85rem 1rem; }
.sk-icon-wrap { width: 36px; height: 36px; border-radius: 9px; background: var(--gb); animation: shimmer 1.4s ease infinite; flex-shrink: 0; }

/* ─── ERROR STATE ─── */
.error-state { display: flex; align-items: center; gap: .65rem; padding: 1rem 1.25rem; background: var(--eb); border: 1px solid var(--ebd); border-radius: 10px; font-size: 12.5px; color: var(--et); }
.error-state svg { width: 16px; height: 16px; flex-shrink: 0; }
.error-state span { flex: 1; }
.retry-btn { padding: 4px 12px; border: 1.5px solid var(--et); border-radius: 7px; background: transparent; color: var(--et); font-size: 11px; font-weight: 600; font-family: 'DM Sans',sans-serif; cursor: pointer; }
.retry-btn:hover { background: var(--et); color: #fff; }

/* ─── CONTENT AREA ─── */
.rme-content { display: flex; flex-direction: column; }
.empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1rem; color: var(--th); text-align: center; padding: 4rem 2rem; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; }
.empty svg { width: 64px; height: 64px; fill: none; stroke: var(--gb); stroke-width: 1.5; stroke-linecap: round; }
.empty p { font-size: 13.5px; line-height: 1.7; color: var(--th); }

/* ─── PATIENT BAR ─── */
.ptb { display: flex; align-items: center; gap: .85rem; padding: .75rem 1rem; background: var(--bc); border-radius: 12px; border: 1px solid var(--gb); margin-bottom: .75rem; }
.ptav { width: 40px; height: 40px; border-radius: 50%; background: var(--gl); color: var(--ga); font-weight: 700; font-size: 16px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.pti { flex: 1; min-width: 0; }
.ptn { font-family: 'DM Serif Display', serif; font-size: 17px; color: var(--gd); line-height: 1.1; }
.ptm { font-size: 10.5px; color: var(--tu); margin-top: 2px; }
.ptags { display: flex; gap: 4px; margin-top: 5px; flex-wrap: wrap; align-items: center; }
.ptg { font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; }
.ptg-bpjs { background: #dbeafe; color: #1e40af; }
.ptg-umum { background: var(--gl); color: var(--ga); }
.ptg-asn  { background: var(--pb); color: var(--pt); }
.ptg-r    { background: var(--eb); color: var(--et); }
.copy-rm-btn { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border: 1.5px solid var(--gb); border-radius: 6px; background: var(--bs); color: var(--it); font-size: 10px; font-weight: 700; font-family: monospace; cursor: pointer; transition: all .15s; }
.copy-rm-btn:hover { border-color: var(--it); background: var(--ib); }
.copy-rm-btn svg { width: 11px; height: 11px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.pt-stats { display: flex; gap: 1rem; border-left: 1px solid var(--gb); padding-left: 1rem; flex-shrink: 0; }
.pt-stat { text-align: center; }
.pt-stat-v { font-size: 15px; font-weight: 700; color: var(--td); line-height: 1; }
.pt-stat-l { font-size: 9px; color: var(--tu); margin-top: 2px; }
.print-btn { display: inline-flex; align-items: center; gap: 5px; padding: 0 14px; height: 34px; border: 1.5px solid var(--ga); border-radius: 9px; background: transparent; color: var(--ga); font-size: 11.5px; font-weight: 600; font-family: 'DM Sans',sans-serif; cursor: pointer; transition: all .15s; flex-shrink: 0; }
.print-btn:hover { background: var(--gl); }
.print-btn svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

/* ─── MAIN RME TABS ─── */
.rme-tabs { display: flex; gap: 4px; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px 12px 0 0; border-bottom: none; padding: .5rem .75rem 0; }
.rmt { display: inline-flex; align-items: center; gap: 6px; padding: .55rem 1.1rem; font-size: 12.5px; font-weight: 500; color: var(--tu); background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: 'DM Sans',sans-serif; transition: all .15s; }
.rmt svg { width: 13px; height: 13px; }
.rmt:hover { color: var(--td); }
.rmt.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }
.rmt-ct { font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 10px; background: var(--sb); color: var(--st); margin-left: 2px; }

/* ─── VISIT FILTER TABS ─── */
.visit-tabs { display: flex; background: var(--bc); border: 1px solid var(--gb); border-top: none; border-bottom: 1px solid var(--gb); padding: 0 1rem; gap: 2px; flex-shrink: 0; }
.vt { padding: .55rem .9rem; font-size: 11.5px; font-weight: 500; color: var(--tu); background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: 'DM Sans', sans-serif; display: flex; align-items: center; gap: 5px; transition: all .15s; }
.vt:hover { color: var(--td); }
.vt.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }
.vt-cnt { font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 20px; background: var(--gb); color: var(--tu); }
.vt.a .vt-cnt { background: rgba(31,125,74,.12); color: var(--ga); }

/* ─── TIMELINE ─── */
.timeline-area { flex: 1; overflow-y: auto; padding: 1.25rem 1.25rem 1rem; background: var(--bc); border: 1px solid var(--gb); border-top: none; border-radius: 0 0 12px 12px; }
.timeline-area::-webkit-scrollbar { width: 4px; }
.timeline-area::-webkit-scrollbar-thumb { background: var(--gb); border-radius: 2px; }
.empty-filter { text-align: center; padding: 2rem; font-size: 12px; color: var(--th); }
.timeline { position: relative; padding-left: 2rem; }
.timeline::before { content: ''; position: absolute; left: 8px; top: 12px; bottom: 12px; width: 2px; background: var(--gb); border-radius: 1px; }
.tl-item { position: relative; margin-bottom: .75rem; }
.tl-dot { position: absolute; left: -1.95rem; top: 14px; width: 12px; height: 12px; border-radius: 50%; border: 2px solid var(--bc); box-shadow: 0 0 0 1px var(--gb); z-index: 1; }

/* ─── VISIT CARD ─── */
.visit-card { background: var(--bc); border: 1.5px solid var(--gb); border-radius: 11px; overflow: hidden; transition: border-color .15s; }
.visit-card.open { border-color: var(--ga); }
.vc-header { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; padding: .7rem .9rem; cursor: pointer; }
.vc-header:hover { background: var(--bs); }
.vc-left { display: flex; flex-direction: column; gap: .25rem; }
.jenis-badge { display: inline-flex; font-size: 9px; font-weight: 700; padding: 2px 8px; border-radius: 20px; width: fit-content; letter-spacing: .04em; text-transform: uppercase; }
.vc-date { font-size: 12px; font-weight: 600; color: var(--td); }
.vc-doctor { font-size: 10.5px; color: var(--tu); }
.vc-right { display: flex; flex-direction: column; align-items: flex-end; gap: .25rem; flex-shrink: 0; }
.vc-main-dx { font-size: 11px; font-weight: 500; color: var(--tm); max-width: 220px; text-align: right; }
.vc-badges { display: flex; gap: 3px; flex-wrap: wrap; justify-content: flex-end; }
.badge-finalized { font-size: 8.5px; font-weight: 700; padding: 1px 6px; border-radius: 4px; background: var(--sb); color: var(--st); }
.badge-penunjang { font-size: 8.5px; font-weight: 700; padding: 1px 6px; border-radius: 4px; background: var(--ib); color: var(--it); }
.badge-addendum  { font-size: 8.5px; font-weight: 700; padding: 1px 6px; border-radius: 4px; background: var(--wb); color: var(--wt); }
.badge-sep       { font-size: 8.5px; font-weight: 700; padding: 1px 6px; border-radius: 4px; background: var(--pb); color: var(--pt); }
.vc-toggle { font-size: 9px; color: var(--tu); margin-top: 4px; }

/* ─── CARD BODY ─── */
.vc-body { padding: .8rem .9rem; border-top: 1px solid var(--gb); background: var(--bs); display: flex; flex-direction: column; gap: .65rem; }
.vc-row-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .65rem; }
.vc-section { background: var(--bc); border: 1px solid var(--gb); border-radius: 8px; padding: .6rem .75rem; }
.vcs-title { font-size: 9.5px; font-weight: 700; color: var(--tm); text-transform: uppercase; letter-spacing: .06em; margin-bottom: .4rem; }
.keluhan-text { font-size: 12px; color: var(--td); line-height: 1.5; }
.vitals-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: .3rem; }
.vg-item { display: flex; flex-direction: column; gap: 1px; }
.vg-l { font-size: 8.5px; color: var(--tu); text-transform: uppercase; letter-spacing: .04em; }
.vg-v { font-size: 12px; font-weight: 600; color: var(--td); }
.vg-v.hi   { color: var(--et); }
.vg-v.warn { color: var(--wt); }
.soap-grid { display: flex; flex-direction: column; gap: .4rem; }
.soap-row { display: flex; gap: .6rem; font-size: 12px; }
.soap-lbl { width: 16px; height: 16px; border-radius: 4px; background: var(--gd); color: #fff; font-size: 9px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; }
.soap-val { color: var(--td); line-height: 1.5; flex: 1; }
.dx-row { display: flex; align-items: center; gap: .4rem; padding: 3px 0; }
.dx-type { font-size: 8px; font-weight: 700; padding: 1px 6px; border-radius: 4px; }
.dx-type.pr { background: var(--gd); color: #fff; }
.dx-type.sk { background: var(--gb); color: var(--tm); }
.dx-kode { font-size: 10.5px; font-weight: 700; color: var(--ga); font-family: monospace; }
.dx-nama { font-size: 11.5px; color: var(--td); }
.resep-list { display: flex; flex-direction: column; gap: 4px; }
.resep-row { padding: 4px 0; border-bottom: 1px dashed var(--gb); }
.resep-row:last-child { border-bottom: none; }
.resep-name { font-size: 12px; font-weight: 500; color: var(--td); }
.resep-detail { font-size: 10px; color: var(--tu); }
.empty-mini { font-size: 11px; color: var(--th); font-style: italic; }
.pj-result-row { display: flex; align-items: flex-start; gap: .5rem; padding: 4px 0; }
.pj-type-badge { font-size: 9px; font-weight: 700; padding: 2px 8px; border-radius: 4px; background: var(--ib); color: var(--it); flex-shrink: 0; }
.pj-hasil { font-size: 11.5px; color: var(--td); line-height: 1.5; }
.addendum-row { display: flex; gap: .5rem; padding: 4px 0; border-bottom: 1px dashed var(--gb); font-size: 11px; }
.addendum-row:last-child { border-bottom: none; }
.add-tgl  { color: var(--tu); flex-shrink: 0; }
.add-oleh { color: var(--it); font-weight: 500; flex-shrink: 0; }
.add-isi  { color: var(--td); flex: 1; }
.addendum-input-row { background: var(--wb); border: 1px solid var(--wbd); border-radius: 8px; padding: .65rem; }
.add-textarea { width: 100%; background: var(--bc); border: 1.5px solid var(--wbd); border-radius: 7px; font-family: 'DM Sans', sans-serif; font-size: 12px; color: var(--td); outline: none; resize: vertical; min-height: 52px; padding: 7px 10px; box-sizing: border-box; line-height: 1.5; }
.add-textarea:focus { border-color: var(--wt); }
.btn-add-save { padding: 5px 14px; background: var(--wt); color: #fff; border: none; border-radius: 7px; font-size: 11.5px; font-weight: 600; font-family: 'DM Sans', sans-serif; cursor: pointer; }
.btn-add-save:disabled { opacity: .6; cursor: not-allowed; }
.btn-add-cancel { padding: 5px 12px; background: transparent; color: var(--tu); border: 1.5px solid var(--gb); border-radius: 7px; font-size: 11.5px; font-family: 'DM Sans', sans-serif; cursor: pointer; }
.vc-actions { display: flex; align-items: center; justify-content: space-between; padding-top: .5rem; border-top: 1px solid var(--gb); }
.vc-meta { display: flex; align-items: center; gap: .75rem; }
.sep-info { font-size: 10px; color: var(--pt); font-family: monospace; }
.finalized-info { display: flex; align-items: center; gap: 4px; font-size: 10.5px; color: var(--st); font-weight: 500; }
.finalized-info svg { width: 12px; height: 12px; fill: none; stroke: var(--st); stroke-width: 2.5; stroke-linecap: round; }
.btn-addendum { display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; border: 1.5px solid var(--wbd); background: var(--wb); color: var(--wt); border-radius: 7px; font-size: 11px; font-weight: 600; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all .15s; }
.btn-addendum:hover { background: var(--wt); color: #fff; }
.btn-addendum svg { width: 11px; height: 11px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

/* ─── DOKUMEN TAB ─── */
.dok-panel { background: var(--bc); border: 1px solid var(--gb); border-top: none; border-radius: 0 0 12px 12px; }
.dok-header { padding: .85rem 1.1rem; border-bottom: 1px solid var(--gb); }
.dok-title { font-size: 13px; font-weight: 600; color: var(--td); }
.dok-subtitle { font-size: 10.5px; color: var(--tu); margin-top: 2px; }
.dok-list { display: flex; flex-direction: column; }
.dok-row { display: flex; align-items: center; gap: .85rem; padding: .75rem 1.1rem; border-bottom: 1px solid var(--gb); transition: background .12s; }
.dok-row:last-child { border-bottom: none; }
.dok-row:hover { background: var(--bs); }
.dok-icon-wrap { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.dok-icon-wrap.final   { background: var(--sb); }
.dok-icon-wrap.final svg { stroke: var(--st); }
.dok-icon-wrap.waiting { background: var(--wb); }
.dok-icon-wrap.waiting svg { stroke: var(--wt); }
.dok-icon-wrap.rejected { background: var(--eb); }
.dok-icon-wrap.rejected svg { stroke: var(--et); }
.dok-icon-wrap.draft, .dok-icon-wrap.void { background: var(--bs); }
.dok-icon-wrap.draft svg, .dok-icon-wrap.void svg { stroke: var(--tu); }
.dok-icon-wrap svg { width: 18px; height: 18px; }
.dok-info { flex: 1; min-width: 0; }
.dok-kode { font-size: 10px; font-weight: 700; color: var(--it); font-family: monospace; letter-spacing: .02em; }
.dok-nama { font-size: 13px; font-weight: 600; color: var(--td); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dok-meta { font-size: 10.5px; color: var(--tu); margin-top: 2px; }
.dok-status { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 20px; flex-shrink: 0; }
.dok-status svg { width: 11px; height: 11px; }
.dok-status.final    { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.dok-status.waiting  { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }
.dok-status.rejected { background: var(--eb); color: var(--et); border: 1px solid var(--ebd); }
.dok-status.draft, .dok-status.void { background: var(--bs); color: var(--tu); border: 1px solid var(--gb); }
.dok-actions { display: flex; gap: 4px; flex-shrink: 0; }
.dok-btn { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; font-size: 11px; font-weight: 600; border-radius: 7px; border: 1.5px solid var(--gb); background: var(--bs); color: var(--tu); cursor: pointer; font-family: 'DM Sans',sans-serif; transition: all .12s; }
.dok-btn svg { width: 12px; height: 12px; }
.dok-btn.view:hover  { border-color: var(--it); color: var(--it); background: var(--ib); }
.dok-btn.print:hover { border-color: var(--wt); color: var(--wt); background: var(--wb); }

/* ─── DOCUMENT MODAL ─── */
.doc-ov { position: fixed; inset: 0; background: rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center; z-index: 200; }
.doc-mbx { background: var(--bc); border-radius: 16px; width: 520px; max-width: 95vw; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,.2); }
.doc-mh { padding: .85rem 1.1rem; border-bottom: 1px solid var(--gb); display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; flex-shrink: 0; }
.doc-mht { font-size: 14px; font-weight: 700; color: var(--td); }
.doc-msub { font-size: 10.5px; color: var(--tu); margin-top: 2px; }
.doc-mbtn { display: inline-flex; align-items: center; gap: 5px; padding: 0 12px; height: 30px; border-radius: 8px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'DM Sans',sans-serif; border: 1.5px solid; transition: all .15s; }
.doc-mbtn svg { width: 12px; height: 12px; }
.doc-mbtn.print { border-color: var(--gb); color: var(--tu); background: var(--bs); }
.doc-mbtn.print:hover { border-color: var(--wt); color: var(--wt); }
.doc-mcl { width: 30px; height: 30px; border-radius: 7px; border: 1.5px solid var(--gb); background: var(--bs); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--tu); }
.doc-mcl:hover { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.doc-mcl svg { width: 13px; height: 13px; }
.doc-mb { flex: 1; overflow-y: auto; padding: 1rem 1.1rem; display: flex; flex-direction: column; gap: .65rem; }
.doc-patient-bar { font-size: 11.5px; color: var(--tu); background: var(--bs); border: 1px solid var(--gb); border-radius: 7px; padding: 7px 12px; }
.doc-status-block { display: flex; align-items: flex-start; gap: .65rem; padding: .85rem 1rem; border-radius: 10px; border: 1px solid; }
.doc-status-block svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }
.doc-status-block.final    { background: var(--sb); border-color: var(--sbd); color: var(--st); }
.doc-status-block.final svg { stroke: var(--st); }
.doc-status-block.waiting  { background: var(--wb); border-color: var(--wbd); color: var(--wt); }
.doc-status-block.waiting svg { stroke: var(--wt); }
.doc-status-block.rejected { background: var(--eb); border-color: var(--ebd); color: var(--et); }
.doc-status-block.rejected svg { stroke: var(--et); }
.doc-status-block.draft, .doc-status-block.void { background: var(--bs); border-color: var(--gb); color: var(--tu); }
.doc-status-block.draft svg, .doc-status-block.void svg { stroke: var(--tu); }
.dsb-title { font-size: 12.5px; font-weight: 700; }
.dsb-desc { font-size: 11.5px; margin-top: 2px; line-height: 1.5; }

/* ─── PRINT RESUME ─── */
.resume-print { display: none; }
@media print {
  .rme-searchbar, .ptb .print-btn, .visit-tabs, .timeline-area, .empty, .dok-panel { display: none !important; }
  .resume-print { display: block !important; font-family: 'DM Sans', sans-serif; font-size: 12px; line-height: 1.5; }
  .rp-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: .5rem; margin-bottom: .75rem; }
  .rp-table { width: 100%; border-collapse: collapse; margin-bottom: .75rem; }
  .rp-table td { border: 1px solid #ccc; padding: 4px 8px; }
  .rp-visit { margin-bottom: .75rem; padding-bottom: .5rem; border-bottom: 1px dashed #ccc; }
  .rp-footer { margin-top: 1rem; font-size: 10px; color: #666; text-align: right; }
}

/* ─── TOAST ─── */
.toast-wrap { position: fixed; top: .65rem; right: .65rem; z-index: 999; display: flex; flex-direction: column; gap: 3px; pointer-events: none; }
.toast { padding: 7px 12px; border-radius: 9px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 3px 12px rgba(0,0,0,.08); min-width: 200px; pointer-events: all; animation: slideIn .28s ease; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
@keyframes slideIn { from { opacity:0; transform:translateX(12px); } to { opacity:1; transform:translateX(0); } }
</style>
