<script setup>
/**
 * TtdDokumenView — halaman antrian TTD dokumen untuk dokter yang login.
 *
 * Backend (GET /rekam-medis/ttd-queue) mengembalikan data grouped by patient;
 * di sini kita FLATTEN jadi baris-per-dokumen agar bisa ditampilkan dalam
 * tabel ringkas (skala baik untuk banyak pasien). Tiap baris = 1 dokumen yang
 * menunggu TTD dokter ini.
 *
 * Filter: dokumen status PENDING_SIGNATURE / RENDERED / DRAFT yang punya field
 * signature_canvas signer_type='doctor' dan belum di-TTD oleh dokter ini.
 */
import { computed, onMounted, ref } from 'vue'
import { formTemplateApi } from '@/services/api'
import SignatureCaptureModal from '@/components/forms/signature/SignatureCaptureModal.vue'

const groups  = ref([])
const loading = ref(false)
const error   = ref('')

// Kontrol tabel
const perPage      = ref(10)
const statusFilter = ref('ALL')
const search       = ref('')
const page         = ref(1)

// Preview + capture
const modalOpen      = ref(false)
const captureOpen    = ref(false)
const currentDoc     = ref(null)
const previewHtml    = ref('')
const previewLoading = ref(false)

// Edit isi DRAFT (override teks HTML manual sebelum TTD)
const editMode   = ref(false)
const editHtml   = ref('')
const editSaving = ref(false)
const isDraft    = computed(() => currentDoc.value?.status === 'DRAFT')

// RENDERED + PENDING_SIGNATURE digabung jadi satu label "Menunggu TTD" —
// dari sisi dokter aksinya sama (preview → tanda tangan). DRAFT tetap terpisah
// sebagai sinyal bahwa isi dokumen belum final & masih bisa berubah (diedit di
// station-nya, bukan di halaman ini).
const STATUS_META = {
  DRAFT:             { label: 'Draf',         cls: 'st-draft'   },
  RENDERED:          { label: 'Menunggu TTD', cls: 'st-pending' },
  PENDING_SIGNATURE: { label: 'Menunggu TTD', cls: 'st-pending' },
}
function statusMeta(s) {
  return STATUS_META[s] ?? { label: s, cls: 'st-default' }
}

// Flatten grup → baris dokumen.
const allRows = computed(() => {
  const rows = []
  for (const g of groups.value) {
    for (const d of g.documents ?? []) {
      rows.push({
        ...d,
        patient_name:   g.patient?.name ?? '—',
        patient_no_rm:  g.patient?.no_rm ?? null,
        patient_gender: g.patient?.gender ?? null,
      })
    }
  }
  return rows
})

// Filter status: 'DRAFT' cocok hanya DRAFT; 'PENDING' mencakup RENDERED +
// PENDING_SIGNATURE (keduanya tampil sebagai "Menunggu TTD").
function matchStatus(status) {
  if (statusFilter.value === 'ALL') return true
  if (statusFilter.value === 'DRAFT') return status === 'DRAFT'
  if (statusFilter.value === 'PENDING') return status === 'RENDERED' || status === 'PENDING_SIGNATURE'
  return true
}

const filteredRows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return allRows.value.filter((r) => {
    if (!matchStatus(r.status)) return false
    if (!q) return true
    return (
      (r.patient_name || '').toLowerCase().includes(q) ||
      (r.patient_no_rm || '').toLowerCase().includes(q) ||
      (r.template_name || r.template_code || '').toLowerCase().includes(q) ||
      (r.review_doctor || '').toLowerCase().includes(q)
    )
  })
})

const totalFiltered = computed(() => filteredRows.value.length)
const totalPages = computed(() => Math.max(1, Math.ceil(totalFiltered.value / perPage.value)))
const pagedRows = computed(() => {
  const start = (page.value - 1) * perPage.value
  return filteredRows.value.slice(start, start + perPage.value)
})
const rangeStart = computed(() => (totalFiltered.value === 0 ? 0 : (page.value - 1) * perPage.value + 1))
const rangeEnd   = computed(() => Math.min(page.value * perPage.value, totalFiltered.value))

function resetPage() { page.value = 1 }
function goPrev() { if (page.value > 1) page.value-- }
function goNext() { if (page.value < totalPages.value) page.value++ }

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await formTemplateApi.ttdQueue()
    groups.value = data.data ?? []
    if (page.value > totalPages.value) page.value = 1
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal memuat antrian.'
  } finally {
    loading.value = false
  }
}

async function openTtd(doc) {
  currentDoc.value = doc
  modalOpen.value = true
  previewHtml.value = ''
  previewLoading.value = true
  try {
    const { data } = await formTemplateApi.snapshot(doc.id)
    previewHtml.value = data.data?.rendered_html ?? ''
    if (!previewHtml.value && doc.template_code && doc.visit_id) {
      const r = await formTemplateApi.renderForm(doc.template_code, doc.visit_id)
      previewHtml.value = r.data.data?.html ?? ''
    }
  } catch (e) {
    previewHtml.value = '<p style="color:#b42323">(Gagal render preview)</p>'
  } finally {
    previewLoading.value = false
  }
}

function closeModal() {
  modalOpen.value = false
  captureOpen.value = false
  currentDoc.value = null
  previewHtml.value = ''
  editMode.value = false
  editHtml.value = ''
}

function startEdit() {
  editHtml.value = previewHtml.value
  editMode.value = true
}

function cancelEdit() {
  editMode.value = false
  editHtml.value = ''
}

async function saveEdit() {
  if (!currentDoc.value) return
  editSaving.value = true
  error.value = ''
  try {
    const { data } = await formTemplateApi.saveDraftContent(currentDoc.value.id, editHtml.value)
    previewHtml.value = data.data?.rendered_html ?? editHtml.value
    editMode.value = false
  } catch (e) {
    error.value = 'Gagal menyimpan isi: ' + (e.response?.data?.message ?? e.message)
  } finally {
    editSaving.value = false
  }
}

async function onCapture(payload) {
  if (!currentDoc.value) return
  try {
    const userJson = localStorage.getItem('auth_user')
    let userId = null
    try { userId = JSON.parse(userJson ?? '{}')?.id ?? null } catch (_) {}

    await formTemplateApi.sign(currentDoc.value.id, {
      signer_type: 'doctor',
      signer_user_id: userId,
      signature_svg: payload.signature_svg,
      signature_png_base64: payload.signature_png_base64,
      biometric_metadata: payload.biometric_metadata,
      audit_log: payload.audit_log,
    })
    closeModal()
    await load()
  } catch (e) {
    error.value = 'Gagal menyimpan TTD: ' + (e.response?.data?.message ?? e.message)
  }
}

function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

onMounted(load)
</script>

<template>
  <div class="ttd-wrap">
    <header class="ttd-banner">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/>
      </svg>
      <h1>Daftar Formulir — Perlu Tanda Tangan</h1>
    </header>

    <div class="ttd-toolbar">
      <div class="ttd-tool-left">
        <label class="ttd-tool-inline">
          Tampil
          <select v-model.number="perPage" @change="resetPage" class="ttd-select-sm">
            <option :value="10">10</option>
            <option :value="25">25</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
          data
        </label>
        <select v-model="statusFilter" @change="resetPage" class="ttd-select">
          <option value="ALL">Semua status</option>
          <option value="DRAFT">Draf</option>
          <option value="PENDING">Menunggu TTD</option>
        </select>
        <button class="ttd-btn-ghost" @click="load" :disabled="loading">↻ Refresh</button>
      </div>
      <div class="ttd-tool-right">
        <label class="ttd-search">
          Cari:
          <input
            v-model="search"
            @input="resetPage"
            type="text"
            placeholder="Cari nama pasien, no. RM, dokumen…"
          />
        </label>
      </div>
    </div>

    <div class="ttd-table-card">
      <table class="ttd-table">
        <thead>
          <tr>
            <th style="width:52px">NO</th>
            <th>PASIEN</th>
            <th>REVIEW DOKTER</th>
            <th>JENIS DOKUMEN</th>
            <th style="width:120px">TANGGAL</th>
            <th style="width:150px">STATUS</th>
            <th style="width:120px" class="ttd-th-action">AKSI</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading && allRows.length === 0">
            <td colspan="7" class="ttd-cell-state">Memuat antrian…</td>
          </tr>
          <tr v-else-if="error">
            <td colspan="7" class="ttd-cell-state ttd-err">{{ error }}</td>
          </tr>
          <tr v-else-if="totalFiltered === 0">
            <td colspan="7" class="ttd-cell-state">
              <div class="ttd-empty">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
                <p>{{ allRows.length === 0 ? 'Antrian tanda tangan kosong.' : 'Tidak ada dokumen yang cocok dengan filter.' }}</p>
              </div>
            </td>
          </tr>
          <tr v-for="(r, i) in pagedRows" :key="r.id" class="ttd-row">
            <td class="ttd-num">{{ rangeStart + i }}</td>
            <td>
              <div class="ttd-patient">
                <strong>{{ r.patient_name }}</strong>
                <span class="ttd-rm">No.RM {{ r.patient_no_rm ?? '—' }} · {{ r.patient_gender || '—' }}</span>
              </div>
            </td>
            <td>{{ r.review_doctor ?? '—' }}</td>
            <td><span class="ttd-doc-chip">{{ r.template_name ?? r.template_code }}</span></td>
            <td>{{ formatDate(r.visit_date ?? r.created_at) }}</td>
            <td>
              <span class="ttd-status" :class="statusMeta(r.status).cls">
                <span class="ttd-status-dot"></span>{{ statusMeta(r.status).label }}
              </span>
            </td>
            <td class="ttd-th-action">
              <button class="ttd-act ttd-act-sign" @click="openTtd(r)" title="Tanda tangani">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/>
                </svg>
              </button>
              <button class="ttd-act ttd-act-view" @click="openTtd(r)" title="Lihat dokumen">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <footer class="ttd-table-foot">
        <span class="ttd-foot-info">
          Menampilkan {{ rangeStart }} s/d {{ rangeEnd }} dari {{ totalFiltered }} data
        </span>
        <div class="ttd-pager">
          <button class="ttd-page-btn" @click="goPrev" :disabled="page <= 1">‹ Previous</button>
          <button class="ttd-page-num">{{ page }}</button>
          <button class="ttd-page-btn" @click="goNext" :disabled="page >= totalPages">Next ›</button>
        </div>
      </footer>
    </div>

    <!-- Preview modal -->
    <Teleport to="body">
      <div v-if="modalOpen && currentDoc" class="ttd-preview-overlay" @click.self="closeModal">
        <div class="ttd-preview-modal">
          <header class="ttd-preview-head">
            <div>
              <h3>{{ currentDoc.template_name ?? currentDoc.template_code }}</h3>
              <p class="ttd-preview-sub">{{ currentDoc.patient_name }} · {{ statusMeta(currentDoc.status).label }}</p>
            </div>
            <button class="ttd-btn-ghost" @click="closeModal">Tutup</button>
          </header>
          <div class="ttd-preview-body">
            <div v-if="previewLoading" class="ttd-cell-state">Memuat preview…</div>
            <template v-else>
              <div v-if="!editMode" v-html="previewHtml"></div>
              <div v-else class="ttd-edit-wrap">
                <p class="ttd-edit-hint">
                  Edit isi dokumen (DRAFT). Perubahan menimpa isi dokumen ini dan
                  lepas dari data rekam medis.
                </p>
                <textarea
                  v-model="editHtml"
                  class="ttd-edit-area"
                  spellcheck="false"
                  placeholder="Isi dokumen (HTML)…"
                ></textarea>
              </div>
            </template>
          </div>
          <footer class="ttd-preview-foot">
            <template v-if="editMode">
              <button class="ttd-btn-secondary" @click="cancelEdit" :disabled="editSaving">Batal</button>
              <button class="ttd-btn-primary" @click="saveEdit" :disabled="editSaving">
                {{ editSaving ? 'Menyimpan…' : 'Simpan Perubahan' }}
              </button>
            </template>
            <template v-else>
              <button v-if="isDraft" class="ttd-btn-edit" @click="startEdit">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                  <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                Edit Isi
              </button>
              <button class="ttd-btn-primary" @click="captureOpen = true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/>
                </svg>
                Tanda Tangani Dokumen
              </button>
            </template>
          </footer>
        </div>
      </div>
    </Teleport>

    <SignatureCaptureModal
      v-if="currentDoc"
      v-model="captureOpen"
      signer-type="doctor"
      signer-label="Tanda Tangan Dokter"
      :document-name="currentDoc.template_name ?? currentDoc.template_code"
      @capture="onCapture"
    />
  </div>
</template>

<style scoped>
/* Palet "Prima Navy + Vision Sky" via token tokens.css:
   --gd Prima Navy #0E3A66 (header/banner), --ga/--lm Vision Sky #1FAAE0 (aksi),
   --gl tint Sky, --gb border, --bi zebra, --td/--tm teks. */
.ttd-wrap { padding: 1.25rem 1.5rem; display: flex; flex-direction: column; gap: 0.9rem; }

/* Banner — Prima Navy + teks putih, ikon Vision Sky */
.ttd-banner {
  display: flex; align-items: center; justify-content: center; gap: 0.6rem;
  background: var(--gd); color: #fff;
  padding: 0.75rem 1rem; border-radius: 10px;
  box-shadow: 0 2px 8px rgba(14,58,102,0.2);
}
.ttd-banner h1 { margin: 0; font-size: 16px; font-weight: 700; letter-spacing: 0.2px; }
.ttd-banner svg { flex: 0 0 auto; color: var(--ga); }

/* Toolbar */
.ttd-toolbar {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.6rem;
}
.ttd-tool-left, .ttd-tool-right { display: flex; align-items: center; gap: 0.6rem; }
.ttd-tool-inline { display: flex; align-items: center; gap: 0.4rem; font-size: 13px; color: var(--tm); }
.ttd-select, .ttd-select-sm {
  padding: 0.35rem 0.5rem; border: 1px solid var(--gb); border-radius: 6px;
  font-size: 13px; background: #fff; cursor: pointer; color: var(--td);
}
.ttd-select-sm { padding: 0.3rem 0.4rem; }
.ttd-search { display: flex; align-items: center; gap: 0.4rem; font-size: 13px; color: var(--tm); }
.ttd-search input {
  padding: 0.4rem 0.6rem; border: 1px solid var(--gb); border-radius: 6px;
  font-size: 13px; min-width: 230px; color: var(--td);
}
.ttd-search input:focus, .ttd-select:focus, .ttd-select-sm:focus {
  outline: none; border-color: var(--ga); box-shadow: 0 0 0 2px rgba(31,170,224,0.18);
}
.ttd-btn-ghost {
  padding: 0.4rem 0.85rem; border: 1px solid var(--gb); border-radius: 6px;
  background: #fff; cursor: pointer; font-size: 13px; color: var(--td);
}
.ttd-btn-ghost:hover { background: var(--bi); }

/* Table */
.ttd-table-card {
  background: #fff; border: 1px solid var(--gb); border-radius: 10px; overflow: hidden;
  box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}
.ttd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ttd-table thead th {
  background: var(--gd); color: #fff; text-align: left; font-weight: 700;
  padding: 0.7rem 0.9rem; font-size: 11.5px; letter-spacing: 0.4px;
  white-space: nowrap;
}
.ttd-th-action { text-align: right; }
.ttd-table tbody td { padding: 0.6rem 0.9rem; border-bottom: 1px solid var(--gb); color: var(--td); vertical-align: middle; }
.ttd-row:nth-child(even) { background: var(--bi); }
.ttd-row:hover { background: var(--gl); }
.ttd-num { color: var(--tu); font-weight: 600; text-align: center; }

.ttd-patient { display: flex; flex-direction: column; }
.ttd-patient strong { font-size: 13.5px; color: var(--td); }
.ttd-rm { font-size: 11px; color: var(--tu); }

.ttd-doc-chip {
  display: inline-block; padding: 3px 10px; border-radius: 6px;
  background: var(--gl); color: var(--ld); font-size: 12px; font-weight: 600;
  border: 1px solid var(--gb);
}

.ttd-status {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700;
}
.ttd-status-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.st-draft   { background: var(--bi); color: var(--tm); }
.st-ready   { background: var(--gl); color: var(--ld); }
.st-pending { background: var(--wb); color: var(--wt); }
.st-default { background: var(--bi); color: var(--tm); }

.ttd-act {
  width: 30px; height: 30px; border: 0; border-radius: 6px; cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center; margin-left: 4px;
  color: #fff;
}
.ttd-act-sign { background: var(--ga); }
.ttd-act-sign:hover { background: var(--ld); }
.ttd-act-view { background: var(--gd); }
.ttd-act-view:hover { background: var(--gm); }

.ttd-cell-state { text-align: center; padding: 2.5rem 1rem; color: var(--tu); }
.ttd-err { color: var(--et); }
.ttd-empty { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; }
.ttd-empty svg { color: var(--ga); }
.ttd-empty p { margin: 0; }

.ttd-table-foot {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.6rem;
  padding: 0.7rem 0.9rem; border-top: 1px solid var(--gb); background: #fff;
}
.ttd-foot-info { font-size: 12.5px; color: var(--tu); }
.ttd-pager { display: flex; align-items: center; gap: 0.4rem; }
.ttd-page-btn, .ttd-page-num {
  padding: 0.35rem 0.7rem; border: 1px solid var(--gb); border-radius: 6px;
  background: #fff; cursor: pointer; font-size: 12.5px; color: var(--td);
}
.ttd-page-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.ttd-page-num { background: var(--ga); color: #fff; border-color: var(--ga); font-weight: 700; min-width: 32px; }

/* Preview modal */
.ttd-preview-overlay {
  position: fixed; inset: 0; background: rgba(14,58,102,0.55);
  display: flex; align-items: center; justify-content: center; z-index: 1050;
}
.ttd-preview-modal {
  width: min(880px, 96vw); max-height: 92vh;
  background: #fff; border-radius: 10px; overflow: hidden;
  display: flex; flex-direction: column;
}
.ttd-preview-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--gb);
  background: var(--gd); color: #fff;
}
.ttd-preview-head h3 { margin: 0; font-size: 16px; color: #fff; }
.ttd-preview-sub { margin: 3px 0 0; font-size: 12.5px; color: var(--ga); }
.ttd-preview-head .ttd-btn-ghost { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.25); color: #fff; }
.ttd-preview-head .ttd-btn-ghost:hover { background: rgba(255,255,255,0.2); }
.ttd-preview-body { padding: 1.5rem 2rem; overflow-y: auto; flex: 1; background: var(--bg); }
.ttd-preview-foot {
  padding: 0.85rem 1.25rem; border-top: 1px solid var(--gb);
  display: flex; justify-content: center; gap: 0.6rem;
}
.ttd-btn-primary {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 0.55rem 1.3rem; background: var(--ga); color: #fff !important; border: 0; border-radius: 7px;
  cursor: pointer; font-size: 14px; font-weight: 700;
}
.ttd-btn-primary:hover { background: var(--ld); }
.ttd-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.ttd-btn-secondary {
  padding: 0.55rem 1.2rem; border-radius: 7px; cursor: pointer; font-size: 14px; font-weight: 700;
  background: var(--bi); color: var(--td); border: 1px solid var(--tu);
}
.ttd-btn-secondary:hover { background: #e6e9ee; border-color: var(--tm); }
.ttd-btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }
.ttd-btn-edit {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 0.55rem 1.1rem; background: var(--gl); color: var(--gd); border: 1px solid var(--ga); border-radius: 7px;
  cursor: pointer; font-size: 14px; font-weight: 700;
}
.ttd-btn-edit:hover { background: #d4ecf8; border-color: var(--ld); }

/* Edit isi DRAFT */
.ttd-edit-wrap { display: flex; flex-direction: column; gap: 0.5rem; height: 100%; }
.ttd-edit-hint {
  margin: 0; font-size: 12px; color: var(--wt); background: var(--wb);
  border: 1px solid var(--wbd); border-radius: 6px; padding: 6px 10px;
}
.ttd-edit-area {
  width: 100%; min-height: 360px; flex: 1; resize: vertical;
  font-family: var(--font-mono); font-size: 12.5px; line-height: 1.5;
  padding: 0.75rem; border: 1px solid var(--gb); border-radius: 8px; color: var(--td);
}
.ttd-edit-area:focus { outline: none; border-color: var(--ga); box-shadow: 0 0 0 2px rgba(31,170,224,0.18); }
</style>
