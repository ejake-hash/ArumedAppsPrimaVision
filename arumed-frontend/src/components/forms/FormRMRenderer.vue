<script setup>
/**
 * FormRMRenderer — render satu form runtime untuk (template, visit).
 *
 * Mode auto-detect dari template.kind:
 *   - OUTPUT  → fetch /rekam-medis/form/{code}/render, tampilkan HTML + Cetak.
 *   - INPUT   → render dynamic form via FormFieldRenderer, tombol Simpan
 *     (POST /form/{code}/submit) → create patient_document DRAFT.
 *     Setelah simpan, tampilkan tombol "Render OUTPUT" + "Finalisasi (lock)".
 *   - HYBRID  → tampilkan tab "Isi Data" + "Cetak", masing-masing dengan mode di atas.
 *
 * Catatan untuk INPUT field dengan binding `db`: nilai awal di-prefill via
 * "preview render" — kita pakai endpoint render untuk dapat nilai resolved
 * (HTML), tapi prefill JSON belum ada endpoint khusus. Untuk Fase 3 sederhana:
 * field dengan binding.kind = 'db' dimulai EMPTY string, user/staff isi ulang.
 * Optimization prefill dari snapshot DB → Fase 3 polish atau Fase 4.
 */
import { computed, ref, watch, onMounted } from 'vue'
import { formTemplateApi } from '@/services/api'
import FormFieldRenderer from './FormFieldRenderer.vue'
import { useScoring } from './useScoring.js'
import { resolveCustomComponent } from './custom/customComponents.js'

const props = defineProps({
  template:  { type: Object, required: true },
  visitId:   { type: String, required: true },
  // patient_id dari context Visit (parent FormSection) — dipakai signer_type=patient
  // supaya backend SignatureService set signer_patient_id otomatis tanpa
  // butuh external_identity manual.
  patientId: { type: String, default: null },
  // autoOpen: langsung buka modal form saat mount (dipakai DokterView pasca-finalisasi
  // untuk menampilkan Resume Medis RM 1.7 tanpa klik kartu).
  autoOpen:  { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const open    = ref(false)
const tab     = ref('input')   // INPUT mode start at 'input'; toggle to 'output' setelah submit
const html    = ref('')
const loading = ref(false)
const error   = ref('')

// Status doc dari listByStationSection.existing_document (jika ada).
// FINALIZED → buka tombol "Buat Addendum" (PMK 24/2022 koreksi post-finalize).
const docStatus = computed(() => props.template.existing_document?.status ?? null)
const existingDocId = computed(() => props.template.existing_document?.id ?? null)
const isFinalized = computed(() => docStatus.value === 'FINALIZED' || docStatus.value === 'FINAL')

// Addendum modal state
const addendumOpen   = ref(false)
const addendumAlasan = ref('')
const addendumIsi    = ref('')
const addendumBusy   = ref(false)
const addendumError  = ref('')

// Audit log state
const auditEntries = ref([])
const auditLoading = ref(false)
const auditError   = ref('')
const auditLoadedFor = ref(null)

// INPUT mode state
const formData    = ref({})        // { fieldKey: value }
const prefillDefaults = ref({})    // nilai autofill awal dari /prefill (pembanding deteksi manual)
const serverManualKeys = ref([])   // key MANUAL warisan draft sebelumnya (dari server)
const fieldErrors = ref({})        // { fieldKey: message }
const submitting  = ref(false)
const submittedDocId = ref(null)
const submitInfo  = ref(null)      // hasil { sync: { synced, skipped, warnings } }
const finalizing  = ref(false)
const autosaving  = ref(false)     // indikator "menyimpan…" (auto-save draft accordion)
const autosaveReady = ref(false)   // true setelah openAndLoad selesai (cegah autosave saat prefill awal)
let   autosaveTimer = null

const isInput  = computed(() => props.template.kind === 'INPUT' || props.template.kind === 'HYBRID')
const isOutput = computed(() => props.template.kind === 'OUTPUT' || props.template.kind === 'HYBRID')

// Field schema fields untuk INPUT mode
const inputFields = computed(() => {
  const schema = props.template.field_schema ?? {}
  if (Array.isArray(schema.fields)) return schema.fields
  if (Array.isArray(schema.pages)) {
    const all = []
    for (const p of schema.pages) if (Array.isArray(p.fields)) all.push(...p.fields)
    return all
  }
  return []
})

// Field yang ditampilkan di INPUT mode (section data):
// - SKIP yang binding.kind = 'clinic'/'aggregate' (read-only resolve via renderer)
// - SKIP signature_canvas (section TTD terpisah, butuh submit dulu)
// - SKIP signature_placeholder (untuk print, tidak input)
// - INCLUDE scored_radio (jawaban user) + computed_* (display read-only)
const visibleFields = computed(() => {
  return inputFields.value.filter(f => {
    const kind = f.binding?.kind
    // display_only: field auto yang HANYA tampil di output cetak (identitas, kop,
    // meta) — di-resolve renderer, tidak diisi manual di section data.
    if (f.display_only) return false
    if (kind === 'clinic' || kind === 'aggregate') return false
    if (f.type === 'signature_canvas' || f.type === 'signature_placeholder') return false
    return true
  })
})

// ── UX accordion (form besar RANAP) — field dikelompokkan per atribut `group`.
// BACKWARD-COMPATIBLE: form tanpa `group` (mis. Resume RJ) → hasGroups=false →
// render datar seperti semula (tak ada accordion / autosave).
const hasGroups = computed(() => visibleFields.value.some(f => !!f.group))
const activeGroup = ref(null)

const isEmptyVal = (v) => Array.isArray(v) ? v.length === 0 : String(v ?? '').trim() === ''

// Kelompok field: bucket visibleFields by `group` (urutan kemunculan pertama).
// Tiap grup: indikator terisi (✓ semua / • sebagian / ○ kosong) + skor live
// (computed_sum + computed_threshold) untuk header (Norton/MST).
const fieldGroups = computed(() => {
  const order = []
  const map = {}
  for (const f of visibleFields.value) {
    const g = f.group || 'Umum'
    if (!map[g]) { map[g] = []; order.push(g) }
    map[g].push(f)
  }
  return order.map((name) => {
    const fields = map[name]
    const inputs = fields.filter(f => !String(f.type ?? '').startsWith('computed_'))
    const filled = inputs.filter(f => !isEmptyVal(formData.value[f.key])).length
    const status = filled === 0 ? 'empty' : (filled === inputs.length ? 'full' : 'partial')
    const sumF = fields.find(f => f.type === 'computed_sum')
    const thrF = fields.find(f => f.type === 'computed_threshold')
    return {
      name, fields, status,
      score: sumF ? (formData.value[sumF.key] ?? 0) : null,
      scoreLabel: thrF ? (formData.value[thrF.key] ?? '') : '',
    }
  })
})

// Jaga activeGroup tetap valid saat daftar grup berubah.
watch(fieldGroups, (groups) => {
  if (!groups.length) { activeGroup.value = null; return }
  if (!groups.some(g => g.name === activeGroup.value)) activeGroup.value = groups[0].name
}, { immediate: true })

// Live compute scored fields → inject ke formData supaya FormFieldRenderer
// computed_* nampilkan hasil real-time.
const { computed: computedScores } = useScoring(
  () => props.template.field_schema ?? {},
  () => formData.value,
)

// Saat scored_radio berubah → merge computed ke formData.
// Pakai effect/watch dengan computedScores supaya dependency tracking benar.
watch(computedScores, (newComputed) => {
  for (const [k, v] of Object.entries(newComputed)) {
    formData.value[k] = v
  }
}, { deep: true, immediate: true })

// CUSTOM_COMPONENT — lazy import via registry
const customComponent = computed(() => {
  if (props.template.complexity_kind !== 'CUSTOM_COMPONENT') return null
  return resolveCustomComponent(props.template.custom_component_name)
})

// Field signature_canvas — di-render di section TTD (visible setelah submit).
const signatureFields = computed(() => {
  return inputFields.value.filter(f => f.type === 'signature_canvas')
})
const hasSignatureFields = computed(() => signatureFields.value.length > 0)

// State untuk signature capture per field
const capturedSignatures = ref({})   // { fieldKey: { signature_id, signer_type } }
const signingField = ref(null)
const signError = ref('')

async function openAndLoad() {
  open.value = true
  fieldErrors.value = {}
  error.value = ''

  // Default tab: INPUT kalau template INPUT/HYBRID, OUTPUT kalau pure OUTPUT.
  tab.value = isInput.value ? 'input' : 'output'

  // Prefill field editable dari data klinis yang sudah ada (hanya bila belum
  // ada dokumen final & tab input dipakai) — supaya dokter tidak isi ulang.
  if (isInput.value && !isFinalized.value) {
    await loadPrefill()
  }

  // Auto-fetch HTML kalau perlu (output tab visible).
  if (tab.value === 'output' && !html.value) {
    await loadRender()
  }

  // Aktifkan auto-save SETELAH prefill awal selesai (supaya prefill tidak langsung
  // memicu submit). Hanya untuk form ber-grup (RANAP) — form datar (RJ) tak berubah.
  autosaveReady.value = true
}

// ── Auto-save draft (form accordion) — debounce ~800ms. Tujuan: submittedDocId
//    selalu ada tanpa user klik Simpan, supaya section TTD & "Selesai & TTD" siap.
//    Best-effort: kegagalan diabaikan diam-diam (tak ganggu pengisian). Hanya aktif
//    untuk form ber-`group` (RANAP); form datar (Resume RJ) tidak ikut autosave.
watch(formData, () => {
  if (!autosaveReady.value || !hasGroups.value) return
  if (isFinalized.value || submitting.value || finalizing.value) return
  clearTimeout(autosaveTimer)
  autosaveTimer = setTimeout(autoSaveDraft, 800)
}, { deep: true })

async function autoSaveDraft() {
  if (submitting.value || finalizing.value) return
  autosaving.value = true
  try {
    const { data } = await formTemplateApi.submitForm(props.template.code, props.visitId, {
      ...formData.value,
      _manual_fields: collectManualFields(),
    })
    submittedDocId.value = data.data?.document_id
    submitInfo.value = data.data
  } catch (_) { /* autosave best-effort — abaikan */ }
  finally { autosaving.value = false }
}

// Muat nilai awal field editable dari /form/{code}/prefill. Nilai prefill hanya
// jadi default UI; kalau user sudah mengisi field (mis. computed), nilai user menang.
// Server sudah meng-overlay tulisan MANUAL dari draft non-final terbaru (anti-hilang)
// dan mengirim manual_keys — disimpan agar saat submit berikutnya status manual
// tetap terbawa (lihat _manual_fields di submitInput).
async function loadPrefill() {
  try {
    const { data } = await formTemplateApi.prefillForm(props.template.code, props.visitId)
    const defaults = data.data?.defaults ?? {}
    prefillDefaults.value = defaults
    serverManualKeys.value = Array.isArray(data.data?.manual_keys) ? data.data.manual_keys : []
    // Default di BAWAH nilai yang sudah ada di formData (computed/edit user menang).
    formData.value = { ...defaults, ...formData.value }
  } catch (_) { /* prefill best-effort — abaikan kegagalan */ }
}

// Deteksi field MANUAL: nilai sekarang ≠ autofill awal (dan tidak kosong), plus
// warisan manual_keys dari draft sebelumnya yang nilainya masih terisi. Field yang
// DIKOSONGKAN dokter keluar dari daftar manual → autofill mengisi lagi di buka
// berikutnya (kosong = serahkan ke autofill, konsisten dgn DocumentRenderer).
function collectManualFields() {
  const isEmpty = (v) => Array.isArray(v) ? v.length === 0 : String(v ?? '').trim() === ''
  const norm = (v) => Array.isArray(v) ? JSON.stringify(v) : String(v ?? '').trim()
  const edited = Object.keys(formData.value).filter((k) =>
    !isEmpty(formData.value[k]) && norm(formData.value[k]) !== norm(prefillDefaults.value[k]))
  const kept = serverManualKeys.value.filter((k) => !isEmpty(formData.value[k]))
  return [...new Set([...kept, ...edited])]
}

async function loadRender() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await formTemplateApi.renderForm(props.template.code, props.visitId)
    html.value = data.data?.html ?? ''
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal render template.'
  } finally {
    loading.value = false
  }
}

async function switchTab(t) {
  tab.value = t
  if (t === 'output' && !html.value) {
    await loadRender()
  }
  if (t === 'audit') {
    await loadAuditLog()
  }
}

function close() {
  open.value = false
  emit('close')
}

// autoOpen → buka modal langsung saat mount (mis. Resume Medis pasca-finalisasi).
onMounted(() => {
  if (props.autoOpen) openAndLoad()
})

function printIt() {
  if (!html.value) return
  const w = window.open('', '_blank', 'width=900,height=1200')
  if (!w) return
  w.document.open()
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${props.template.name}</title>
<style>@page { size: A4; margin: 1.5cm; } body { font-family: Arial, sans-serif; }</style>
</head><body>${html.value}</body></html>`)
  w.document.close()
  w.focus()
  setTimeout(() => w.print(), 200)
}

function validateBeforeSubmit() {
  fieldErrors.value = {}
  let ok = true
  for (const f of visibleFields.value) {
    const v = formData.value[f.key]
    // Field wajib dianggap kosong bila null/''/array kosong (mis. multi_checkbox).
    const empty = v === undefined || v === null || v === '' || (Array.isArray(v) && v.length === 0)
    if (f.required && empty) {
      fieldErrors.value[f.key] = 'Field wajib diisi'
      ok = false
    }
  }
  return ok
}

async function submitInput({ stayOnInput = false } = {}) {
  if (!validateBeforeSubmit()) {
    error.value = 'Beberapa field wajib belum diisi.'
    return
  }
  submitting.value = true
  error.value = ''
  try {
    const { data } = await formTemplateApi.submitForm(props.template.code, props.visitId, {
      ...formData.value,
      _manual_fields: collectManualFields(),   // meta utk prefill draft (anti tulisan manual hilang)
    })
    submittedDocId.value = data.data?.document_id
    submitInfo.value = data.data
    // Re-load output HTML (data klinis baru tersinkron → render terbaru).
    html.value = ''
    await loadRender()
    // Default: pindah ke preview. Saat alur "Tanda Tangani & Finalisasi", tetap di
    // tab input agar section Tanda Tangan (+ modal PIN) langsung tampil & terbuka.
    if (!stayOnInput) tab.value = 'output'
  } catch (e) {
    // Server-side validation errors map per-field (Laravel validate 422)
    const resp = e.response?.data
    if (resp?.errors && typeof resp.errors === 'object') {
      for (const [k, msgs] of Object.entries(resp.errors)) {
        fieldErrors.value[k.replace(/^data\./, '')] = Array.isArray(msgs) ? msgs[0] : String(msgs)
      }
    }
    error.value = resp?.message ?? 'Gagal simpan form.'
  } finally {
    submitting.value = false
  }
}

async function finalize({ silent = false } = {}) {
  if (!submittedDocId.value) return
  if (!silent && !confirm('Finalisasi dokumen ini? Setelah final, rendered_html ter-snapshot immutable.')) return
  finalizing.value = true
  try {
    await formTemplateApi.finalize(submittedDocId.value)
    if (!silent) alert('Dokumen ter-finalisasi (status FINALIZED).')
    close()
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal finalize.'
  } finally {
    finalizing.value = false
  }
}

// ── "Tanda Tangani & Finalisasi" — alur 1-tombol: simpan draft → buka modal PIN
//    tanda tangan dokter → setelah tertandatangani, auto-finalisasi & kunci. ──────
const autoFinalizeAfterSign = ref(false)
const triggerSignKey = ref(null)   // key field TTD yang modal PIN-nya harus dibuka

// Field TTD wajib pertama (dokter). Dipakai untuk auto-buka modal PIN.
const primarySignKey = computed(() => {
  const f = signatureFields.value.find(s => s.required) ?? signatureFields.value[0]
  return f?.key ?? null
})

async function signAndFinalize() {
  error.value = ''
  // 1) Pastikan draft tersimpan (tetap di tab input agar section TTD tampil).
  if (!submittedDocId.value) {
    await submitInput({ stayOnInput: true })
    if (!submittedDocId.value) return   // validasi gagal — pesan sudah tampil
  }
  // 2) Sudah lengkap TTD? langsung finalisasi. Belum → buka modal PIN TTD dokter.
  autoFinalizeAfterSign.value = true
  if (canFinalize.value) {
    await finalize({ silent: true })
    return
  }
  if (primarySignKey.value) triggerSignKey.value = primarySignKey.value
}

// Capture signature → POST /document/{id}/sign → simpan ke capturedSignatures map.
async function onCaptureSignature(field, payload) {
  if (!submittedDocId.value) {
    signError.value = 'Simpan form (Draft) dulu sebelum tanda tangan.'
    return
  }
  signError.value = ''
  signingField.value = field.key

  const isNakes = ['doctor', 'nurse', 'staff'].includes(field.signer_type)

  const body = {
    signer_type: field.signer_type || 'patient',
    // Nakes → TTD via PIN (tanpa goresan). Pasien/saksi → goresan SVG.
    signature_svg: isNakes ? null : payload.signature_svg,
    signature_png_base64: isNakes ? null : payload.signature_png_base64,
    signature_pin: isNakes ? payload.signature_pin : undefined,
    biometric_metadata: payload.biometric_metadata,
    audit_log: payload.audit_log,
  }

  // Identity routing:
  // - patient → signer_patient_id dari props.patientId (FormSection forward
  //   visit.patient_id). Fallback ke external_identity kalau patientId null.
  // - witness/guardian → external_identity dari modal
  // - doctor/nurse/staff → signer_user_id dari current auth user (backend juga
  //   fallback ke user login bila kosong)
  if (payload.external_identity) {
    body.signer_external_identity = payload.external_identity
  }
  if (field.signer_type === 'patient' && props.patientId) {
    body.signer_patient_id = props.patientId
  }
  if (isNakes) {
    const userJson = localStorage.getItem('auth_user')
    try {
      const u = JSON.parse(userJson ?? '{}')
      if (u?.id) body.signer_user_id = u.id
    } catch (_) { /* ignore */ }
  }

  try {
    const { data } = await formTemplateApi.sign(submittedDocId.value, body)
    capturedSignatures.value = {
      ...capturedSignatures.value,
      [field.key]: {
        signature_id: data.data?.signature_id,
        signer_type:  data.data?.signer_type,
      },
    }
  } catch (e) {
    signError.value = e.response?.data?.message ?? 'Gagal capture signature.'
  } finally {
    signingField.value = null
  }
}

const requiredSignersUnsigned = computed(() => {
  return signatureFields.value
    .filter(f => f.required && !capturedSignatures.value[f.key])
    .map(f => f.label || f.key)
})

const canFinalize = computed(() => {
  if (!submittedDocId.value) return false
  return requiredSignersUnsigned.value.length === 0
})

// Begitu semua TTD wajib lengkap dalam alur "Tanda Tangani & Finalisasi" → finalisasi otomatis.
// CATATAN: watch ini WAJIB diletakkan SETELAH `const canFinalize` di atas. Bila diletakkan
// sebelumnya, `watch(canFinalize, …)` membaca `canFinalize` saat masih di temporal-dead-zone →
// ReferenceError saat setup() → SELURUH FormRMRenderer gagal render (kartu dokumen blank).
watch(canFinalize, async (ok) => {
  if (ok && autoFinalizeAfterSign.value && !finalizing.value) {
    autoFinalizeAfterSign.value = false
    triggerSignKey.value = null
    await finalize({ silent: true })
  }
})

function openAddendum() {
  addendumAlasan.value = ''
  addendumIsi.value = ''
  addendumError.value = ''
  addendumOpen.value = true
}

async function loadAuditLog() {
  const docId = existingDocId.value || submittedDocId.value
  if (!docId) return
  if (auditLoadedFor.value === docId) return
  auditLoading.value = true
  auditError.value = ''
  try {
    const { data } = await formTemplateApi.auditLog(docId)
    auditEntries.value = data.data ?? []
    auditLoadedFor.value = docId
  } catch (e) {
    auditError.value = e.response?.data?.message ?? 'Gagal load audit log.'
  } finally {
    auditLoading.value = false
  }
}

function auditActionLabel(action) {
  const map = {
    FORM_TEMPLATE_CREATED:     'Template dibuat',
    FORM_TEMPLATE_UPDATED:     'Template diupdate',
    FORM_TEMPLATE_ACTIVATED:   'Template diaktifkan',
    FORM_TEMPLATE_DEACTIVATED: 'Template dinonaktifkan',
    FORM_DOC_SUBMITTED:        'Form disubmit (Draft)',
    FORM_DOC_RENDERED:         'Dokumen dirender',
    FORM_DOC_FINALIZED:        'Dokumen difinalisasi',
    FORM_SIG_CAPTURED:         'Tanda tangan ditangkap',
    FORM_ADDENDUM_CREATED:     'Addendum dibuat',
  }
  return map[action] ?? action
}

function formatAuditTime(ts) {
  if (!ts) return ''
  try {
    return new Date(ts).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'medium' })
  } catch {
    return ts
  }
}

async function submitAddendum() {
  if (!existingDocId.value) return
  if (!addendumAlasan.value.trim() || !addendumIsi.value.trim()) {
    addendumError.value = 'Alasan dan isi koreksi wajib diisi.'
    return
  }
  addendumBusy.value = true
  addendumError.value = ''
  try {
    await formTemplateApi.createAddendum(existingDocId.value, {
      alasan: addendumAlasan.value.trim(),
      isi_koreksi: addendumIsi.value.trim(),
    })
    addendumOpen.value = false
    alert('Addendum berhasil dibuat. Lanjutkan dengan tanda tangan jika diperlukan.')
  } catch (e) {
    addendumError.value = e.response?.data?.message ?? 'Gagal buat addendum.'
  } finally {
    addendumBusy.value = false
  }
}
</script>

<template>
  <div class="frr-wrap">
    <button class="frr-card" :class="`frr-card-${template.kind?.toLowerCase()}`" @click="openAndLoad">
      <span class="frr-card-icon" :class="`mode-${template.kind?.toLowerCase()}`" aria-hidden="true">
        <!-- OUTPUT: file-text · INPUT: edit-3 · HYBRID: layers -->
        <svg v-if="template.kind === 'OUTPUT'" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg>
        <svg v-else-if="template.kind === 'INPUT'" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 113 3L7 19l-4 1 1-4z"/></svg>
        <svg v-else viewBox="0 0 24 24"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
      </span>
      <div class="frr-card-main">
        <strong class="frr-card-name">{{ template.name }}</strong>
        <span class="frr-card-meta">
          <span class="frr-card-code">{{ template.code }}</span>
          <span class="frr-card-dot">·</span>
          <span>v{{ template.version }}</span>
          <span class="frr-card-dot">·</span>
          <span class="frr-card-mode" :class="`mode-${template.kind?.toLowerCase()}`">{{ template.kind }}</span>
        </span>
      </div>
      <div class="frr-card-trailing">
        <span
          v-if="docStatus"
          class="frr-card-status"
          :class="`st-${docStatus.toLowerCase()}`"
        >{{ docStatus }}</span>
        <svg class="frr-card-chev" viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
      </div>
    </button>

    <Teleport to="body">
      <!-- Addendum modal — koreksi post-finalisasi (PMK 24/2022) -->
      <div v-if="addendumOpen" class="frr-overlay frr-overlay-addendum" @click.self="addendumOpen = false">
        <div class="frr-modal-sm">
          <header class="frr-modal-head">
            <div>
              <h3>Buat Addendum</h3>
              <p class="frr-modal-sub">Koreksi tambahan untuk: {{ template.name }}</p>
            </div>
            <button class="frr-btn-ghost" @click="addendumOpen = false">Tutup</button>
          </header>
          <div class="frr-body frr-input-body">
            <label class="frr-add-label">
              Alasan koreksi <span class="frr-req">*</span>
              <input
                v-model="addendumAlasan"
                type="text"
                maxlength="500"
                placeholder="Contoh: salah ketik diagnosa, koreksi dosis obat, dst."
                :disabled="addendumBusy"
              />
            </label>
            <label class="frr-add-label">
              Isi koreksi <span class="frr-req">*</span>
              <textarea
                v-model="addendumIsi"
                rows="6"
                placeholder="Tulis koreksi/penambahan informasi yang akan masuk addendum."
                :disabled="addendumBusy"
              ></textarea>
            </label>
            <p class="frr-add-note">
              Addendum bersifat append-only dan akan menjadi bagian dari rekam medis pasien.
              Lanjutkan dengan tanda tangan setelah addendum dibuat.
            </p>
            <div v-if="addendumError" class="frr-err">{{ addendumError }}</div>
            <div class="frr-footer">
              <button class="frr-btn-ghost" :disabled="addendumBusy" @click="addendumOpen = false">Batal</button>
              <button class="frr-btn-primary" :disabled="addendumBusy" @click="submitAddendum">
                {{ addendumBusy ? 'Menyimpan…' : 'Simpan Addendum' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="open" class="frr-overlay" @click.self="close">
        <div class="frr-modal">
          <header class="frr-modal-head">
            <div>
              <h3>{{ template.name }}</h3>
              <p class="frr-modal-sub">{{ template.code }} · v{{ template.version }} · {{ template.kind }}</p>
            </div>
            <div class="frr-modal-actions">
              <template v-if="tab === 'output' && html">
                <button class="frr-btn-ghost" @click="printIt">Cetak</button>
              </template>
              <button
                v-if="isFinalized && existingDocId"
                class="frr-btn-warn"
                @click="openAddendum"
                title="Buat addendum koreksi post-finalisasi"
              >
                Buat Addendum
              </button>
              <button class="frr-btn-ghost" @click="close">Tutup</button>
            </div>
          </header>

          <!-- Tab switcher: hanya untuk HYBRID atau INPUT-after-submit, atau OUTPUT dengan existing doc -->
          <nav v-if="(isInput && isOutput) || (isInput && submittedDocId) || existingDocId" class="frr-tabs">
            <button v-if="isInput" :class="{ active: tab === 'input' }" @click="switchTab('input')">Isi Data</button>
            <button :class="{ active: tab === 'output' }" @click="switchTab('output')">Preview / Cetak</button>
            <button
              v-if="existingDocId || submittedDocId"
              :class="{ active: tab === 'audit' }"
              @click="switchTab('audit')"
            >
              Audit Log
            </button>
          </nav>

          <!-- INPUT mode -->
          <div v-if="tab === 'input'" class="frr-body frr-input-body">
            <!-- CUSTOM_COMPONENT — render dynamic Vue component dari registry -->
            <component
              v-if="customComponent && template.complexity_kind === 'CUSTOM_COMPONENT'"
              :is="customComponent"
              v-model="formData"
              :template="template"
              :readonly="submitting || finalizing"
            />

            <div class="frr-info" v-if="submittedDocId">
              <strong>Tersimpan sebagai DRAFT.</strong>
              Dokumen ID: <code>{{ submittedDocId }}</code>
              <p v-if="submitInfo?.sync" class="frr-sync-info">
                Synced: {{ Object.keys(submitInfo.sync.synced ?? {}).join(', ') || '(tidak ada — semua static)' }}
                <span v-if="(submitInfo.sync.warnings ?? []).length"> · {{ submitInfo.sync.warnings.length }} warning(s)</span>
              </p>
            </div>

            <!-- Accordion + nav samping (form besar RANAP, field ber-`group`) -->
            <div v-if="hasGroups" class="frr-grouped">
              <aside class="frr-groupnav">
                <button
                  v-for="g in fieldGroups"
                  :key="g.name"
                  type="button"
                  class="frr-gn-btn"
                  :class="{ active: activeGroup === g.name }"
                  @click="activeGroup = g.name"
                >
                  <span class="frr-gn-ind" :class="`ind-${g.status}`">
                    {{ g.status === 'full' ? '✓' : (g.status === 'partial' ? '•' : '○') }}
                  </span>
                  <span class="frr-gn-name">{{ g.name }}</span>
                  <span v-if="g.score !== null" class="frr-gn-score">{{ g.score }}</span>
                </button>
                <button type="button" class="frr-gn-doc" @click="switchTab('output')">Lihat Dokumen →</button>
              </aside>

              <div class="frr-grouppanel">
                <template v-for="g in fieldGroups" :key="g.name">
                  <section v-show="activeGroup === g.name">
                    <h4 class="frr-grouphead">
                      <span>{{ g.name }}</span>
                      <span v-if="g.score !== null" class="frr-group-score">
                        Skor: <strong>{{ g.score }}</strong><em v-if="g.scoreLabel"> — {{ g.scoreLabel }}</em>
                      </span>
                    </h4>
                    <FormFieldRenderer
                      v-for="f in g.fields"
                      :key="f.key"
                      :field="f"
                      v-model="formData[f.key]"
                      :error="fieldErrors[f.key]"
                      :readonly="submitting || finalizing"
                      :document-name="template.name"
                    />
                  </section>
                </template>
              </div>
            </div>

            <!-- Render datar (backward-compatible: form tanpa `group`, mis. Resume RJ) -->
            <template v-else>
              <FormFieldRenderer
                v-for="f in visibleFields"
                :key="f.key"
                :field="f"
                v-model="formData[f.key]"
                :error="fieldErrors[f.key]"
                :readonly="submitting || finalizing"
                :document-name="template.name"
              />
            </template>

            <!-- Section Tanda Tangan — visible setelah submit DRAFT -->
            <div v-if="hasSignatureFields && submittedDocId" class="frr-sig-section">
              <h4>Tanda Tangan</h4>
              <p v-if="requiredSignersUnsigned.length" class="frr-sig-warn">
                Wajib ter-tanda-tangan sebelum finalize:
                <strong>{{ requiredSignersUnsigned.join(', ') }}</strong>
              </p>
              <FormFieldRenderer
                v-for="sf in signatureFields"
                :key="sf.key"
                :field="sf"
                :model-value="capturedSignatures[sf.key]"
                @update:model-value="(v) => { /* ignore raw — capture event yang relevan */ }"
                @capture-signature="(e) => onCaptureSignature(e.field, e.payload)"
                :readonly="finalizing"
                :auto-open-sign="triggerSignKey === sf.key"
                :document-name="template.name"
              />
              <div v-if="signError" class="frr-err">{{ signError }}</div>
            </div>

            <div v-if="hasSignatureFields && !submittedDocId" class="frr-sig-hint">
              Simpan form sebagai Draft dulu untuk membuka section Tanda Tangan.
            </div>

            <div v-if="error" class="frr-err">{{ error }}</div>

            <div class="frr-footer">
              <span v-if="hasGroups && autosaving" class="frr-autosave">Menyimpan…</span>
              <span v-else-if="hasGroups && submittedDocId" class="frr-autosave frr-autosave-ok">Tersimpan otomatis ✓</span>
              <button class="frr-btn-ghost" :disabled="submitting || finalizing" @click="submitInput()">
                {{ submitting ? 'Menyimpan…' : (submittedDocId ? 'Update Draft' : 'Simpan Draft') }}
              </button>
              <button
                v-if="hasSignatureFields"
                class="frr-btn-primary"
                :disabled="submitting || finalizing"
                @click="signAndFinalize"
                title="Simpan draft, tanda tangani dengan PIN, lalu finalisasi & kunci"
              >
                {{ finalizing ? 'Memfinalisasi…' : (submitting ? 'Menyimpan…' : 'Selesai & TTD') }}
              </button>
              <!-- Dokumen tanpa field TTD: finalisasi langsung (jarang utk resume). -->
              <button
                v-else-if="submittedDocId"
                class="frr-btn-danger"
                :disabled="finalizing"
                @click="finalize()"
              >
                {{ finalizing ? 'Memfinalisasi…' : 'Finalisasi & Lock' }}
              </button>
            </div>
          </div>

          <!-- OUTPUT mode -->
          <div v-else-if="tab === 'output'" class="frr-body">
            <div v-if="loading" class="frr-busy">Memuat…</div>
            <div v-else-if="error" class="frr-err">{{ error }}</div>
            <div v-else v-html="html"></div>
          </div>

          <!-- AUDIT LOG -->
          <div v-else-if="tab === 'audit'" class="frr-body frr-audit-body">
            <div v-if="auditLoading" class="frr-busy">Memuat audit log…</div>
            <div v-else-if="auditError" class="frr-err">{{ auditError }}</div>
            <div v-else-if="auditEntries.length === 0" class="frr-busy">
              Belum ada catatan audit untuk dokumen ini.
            </div>
            <ul v-else class="frr-audit-list">
              <li v-for="entry in auditEntries" :key="entry.id" class="frr-audit-item">
                <div class="frr-audit-head">
                  <span class="frr-audit-action">{{ auditActionLabel(entry.action) }}</span>
                  <span class="frr-audit-time">{{ formatAuditTime(entry.created_at) }}</span>
                </div>
                <div class="frr-audit-meta">
                  <span v-if="entry.user">{{ entry.user.name }}<span v-if="entry.user.email"> · {{ entry.user.email }}</span></span>
                  <span v-else class="frr-audit-system">System</span>
                </div>
                <div v-if="entry.description" class="frr-audit-desc">{{ entry.description }}</div>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.frr-wrap { display: contents; }

/* ── Document trigger card ───────────────────────────────────────────────── */
.frr-card {
  display: flex; align-items: center; gap: 0.7rem;
  width: 100%; padding: 0.65rem 0.85rem;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 9px;
  cursor: pointer; text-align: left;
  font-family: inherit;
  transition: border-color .14s ease, background .14s ease, box-shadow .14s ease, transform .08s ease;
}
.frr-card:hover {
  border-color: #1763d4;
  background: #f3f8ff;
  box-shadow: 0 1px 2px rgba(23,99,212,0.05);
}
.frr-card:hover .frr-card-chev { transform: translateX(2px); color: #1763d4; }
.frr-card:active { transform: scale(0.995); }
.frr-card:focus-visible { outline: 2px solid #1763d4; outline-offset: 2px; }

/* leading icon container, tinted per mode */
.frr-card-icon {
  flex-shrink: 0; width: 34px; height: 34px; border-radius: 8px;
  display: inline-flex; align-items: center; justify-content: center;
  background: var(--bg);
}
.frr-card-icon svg {
  width: 17px; height: 17px; fill: none; stroke: currentColor;
  stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
}
.frr-card-icon.mode-output { background: #eef6ff; color: #1763d4; }
.frr-card-icon.mode-input  { background: #fff7ec; color: #b46f00; }
.frr-card-icon.mode-hybrid { background: #f0ecff; color: #5d3fc9; }

.frr-card-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.frr-card-name {
  color: var(--td); font-size: 13.5px; font-weight: 600; line-height: 1.25;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.frr-card-meta {
  display: inline-flex; align-items: center; gap: 5px; flex-wrap: wrap;
  font-size: 11px; color: var(--tm); line-height: 1.2;
}
.frr-card-code { font-family: monospace; font-size: 10.5px; color: var(--tu); }
.frr-card-dot { color: var(--th); }
.frr-card-mode {
  padding: 1px 7px; border-radius: 999px; font-size: 9.5px; font-weight: 700;
  letter-spacing: 0.04em;
}
.frr-card-mode.mode-output { background: #eef6ff; color: #1763d4; }
.frr-card-mode.mode-input  { background: #fff7ec; color: #b46f00; }
.frr-card-mode.mode-hybrid { background: #f0ecff; color: #5d3fc9; }

.frr-card-trailing {
  display: flex; align-items: center; gap: 6px; flex-shrink: 0;
}
.frr-card-chev {
  width: 14px; height: 14px; fill: none; stroke: var(--tu);
  stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
  transition: transform .14s ease, color .14s ease;
}

.frr-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.5);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000;
}
.frr-modal {
  width: min(900px, 94vw); max-height: 92vh;
  background: white; border-radius: 8px; overflow: hidden;
  display: flex; flex-direction: column;
}
.frr-modal-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--gb);
}
.frr-modal-head h3 { margin: 0; font-family: 'Space Grotesk', serif; font-size: 17px; }
.frr-modal-sub { margin: 2px 0 0; font-size: 11.5px; color: var(--tm); font-family: monospace; }
.frr-modal-actions { display: flex; gap: 0.5rem; }

.frr-btn-ghost, .frr-btn-primary, .frr-btn-danger {
  padding: 0.45rem 1rem; border: 1px solid var(--gb); border-radius: 6px;
  background: var(--bc); cursor: pointer; font-size: 13px; font-family: inherit;
}
.frr-btn-primary { background: #1763d4; color: #fff !important; border-color: #1763d4; font-weight: 600; }
.frr-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.frr-btn-primary:hover:not(:disabled) { filter: brightness(1.08); }
.frr-btn-danger { background: #c83b3b; color: white; border-color: #c83b3b; }
.frr-btn-danger:hover:not(:disabled) { filter: brightness(1.08); }
.frr-btn-ghost:hover { background: var(--bg); }

.frr-tabs { display: flex; border-bottom: 1px solid var(--gb); background: var(--bc); }
.frr-tabs button {
  flex: 1; padding: 0.7rem 1.1rem; background: transparent; border: 0;
  cursor: pointer; font-size: 13px; color: var(--tm);
  border-bottom: 2px solid transparent; margin-bottom: -1px;
  transition: color .14s ease, border-color .14s ease;
}
.frr-tabs button:hover { color: var(--td); }
.frr-tabs button.active { color: #1763d4; border-bottom-color: #1763d4; font-weight: 600; }

.frr-body { padding: 1.25rem 1.5rem; overflow-y: auto; flex: 1; background: #fafafa; }
.frr-input-body { background: white; padding: 1.25rem 1.5rem; }

.frr-info {
  background: #ecf9ee; border: 1px solid #cce8d2; border-radius: 6px;
  padding: 0.6rem 0.85rem; margin-bottom: 1rem; font-size: 12.5px; color: #1e6a35;
}
.frr-info code { font-size: 11px; color: var(--td); }
.frr-sync-info { margin: 4px 0 0; font-size: 11.5px; color: #4a6553; }

.frr-busy, .frr-err { padding: 2rem; text-align: center; color: var(--tm); }
.frr-err { color: #b42323; background: #fff0f0; border: 1px solid #fbb; border-radius: 6px; padding: 0.5rem 0.85rem; margin-top: 0.5rem; }

.frr-footer {
  margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--gb);
  display: flex; gap: 0.75rem; justify-content: flex-end;
}

.frr-sig-section {
  margin-top: 1.25rem; padding: 1rem; border: 1px solid var(--gb); border-radius: 8px;
  background: #fafbfc;
}
.frr-sig-section h4 {
  margin: 0 0 0.75rem; font-family: 'Space Grotesk', serif; font-size: 14.5px; color: var(--td);
}
.frr-sig-warn {
  padding: 0.5rem 0.85rem; background: #fff7ec; border: 1px solid #f0d2a5; border-radius: 6px;
  font-size: 12.5px; color: #946600; margin-bottom: 0.75rem;
}
.frr-sig-hint {
  margin-top: 1rem; padding: 0.6rem 0.85rem; background: #f0f1f4; border-radius: 6px;
  font-size: 12.5px; color: var(--tm); font-style: italic;
}

/* ── Accordion + nav samping (form RANAP ber-group) ───────────────────────── */
.frr-grouped { display: grid; grid-template-columns: 210px 1fr; gap: 1rem; align-items: start; }
.frr-groupnav {
  position: sticky; top: 0; display: flex; flex-direction: column; gap: 2px;
  border-right: 1px solid var(--gb); padding-right: 0.6rem;
}
.frr-gn-btn {
  display: flex; align-items: center; gap: 0.5rem; width: 100%; text-align: left;
  padding: 0.5rem 0.6rem; border: 0; background: transparent; border-radius: 6px;
  cursor: pointer; font-size: 12.5px; color: var(--td); font-family: inherit;
  transition: background .12s ease;
}
.frr-gn-btn:hover { background: var(--bg); }
.frr-gn-btn.active { background: #eef6ff; color: #1763d4; font-weight: 600; }
.frr-gn-ind { flex-shrink: 0; width: 16px; text-align: center; font-size: 12px; }
.frr-gn-ind.ind-full    { color: #1e8a3a; }
.frr-gn-ind.ind-partial { color: #b46f00; }
.frr-gn-ind.ind-empty   { color: var(--th); }
.frr-gn-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.frr-gn-score {
  flex-shrink: 0; padding: 1px 7px; background: #f0f1f4; color: var(--tm);
  border-radius: 999px; font-size: 10.5px; font-weight: 700;
}
.frr-gn-doc {
  margin-top: 0.5rem; padding: 0.45rem 0.6rem; border: 1px dashed var(--gb);
  background: transparent; border-radius: 6px; cursor: pointer; font-size: 12px;
  color: var(--tm); font-family: inherit;
}
.frr-gn-doc:hover { background: var(--bg); color: var(--td); }

.frr-grouppanel { min-width: 0; }
.frr-grouphead {
  display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 0.5rem;
  margin: 0 0 0.85rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--gb);
  font-family: 'Space Grotesk', serif; font-size: 14.5px; color: var(--td);
}
.frr-group-score { font-size: 12px; color: var(--tm); font-weight: 400; }
.frr-group-score strong { color: #b46f00; font-size: 14px; }
.frr-group-score em { font-style: normal; color: var(--td); }

.frr-autosave { margin-right: auto; font-size: 12px; color: var(--tm); align-self: center; }
.frr-autosave-ok { color: #1e8a3a; }

/* Status badge & addendum modal */
.frr-card-badges { display: flex; gap: 4px; align-items: center; }
.frr-card-status {
  padding: 2px 8px; border-radius: 999px; font-size: 9.5px; font-weight: 700;
  letter-spacing: 0.05em; text-transform: uppercase; line-height: 1.4;
}
.frr-card-status.st-draft             { background: #f0f1f4; color: #555; }
.frr-card-status.st-rendered          { background: #eef6ff; color: #1763d4; }
.frr-card-status.st-pending_signature { background: #fff7ec; color: #b46f00; }
.frr-card-status.st-finalized,
.frr-card-status.st-final             { background: #ecf9ee; color: #1e6a35; }
.frr-card-status.st-void,
.frr-card-status.st-rejected          { background: #fff0f0; color: #b42323; }

.frr-btn-warn {
  padding: 0.45rem 1rem; border: 1px solid #d4970f; border-radius: 6px;
  background: #fff7ec; color: #b46f00; cursor: pointer; font-size: 13px; font-weight: 600;
}
.frr-btn-warn:hover { background: #fdedce; }

.frr-overlay-addendum { z-index: 1100; }
.frr-modal-sm {
  width: min(560px, 92vw); max-height: 88vh;
  background: white; border-radius: 8px; overflow: hidden;
  display: flex; flex-direction: column;
}
.frr-add-label {
  display: flex; flex-direction: column; gap: 4px;
  margin-bottom: 0.85rem; font-size: 12.5px; color: var(--td); font-weight: 600;
}
.frr-add-label input, .frr-add-label textarea {
  font: inherit; font-weight: normal;
  padding: 0.5rem 0.7rem; border: 1px solid var(--gb); border-radius: 6px;
  resize: vertical;
}
.frr-add-label input:focus, .frr-add-label textarea:focus {
  outline: none; border-color: var(--pri);
}
.frr-req { color: #c83b3b; }
.frr-add-note {
  margin: 0.5rem 0 1rem; padding: 0.55rem 0.8rem;
  background: #f0f1f4; border-left: 3px solid var(--pri); border-radius: 4px;
  font-size: 11.5px; color: var(--tm); line-height: 1.5;
}

/* Audit log */
.frr-audit-body { background: white; }
.frr-audit-list {
  list-style: none; margin: 0; padding: 0;
  display: flex; flex-direction: column; gap: 0.4rem;
}
.frr-audit-item {
  padding: 0.55rem 0.75rem; background: #fafbfc;
  border: 1px solid var(--gb); border-left: 3px solid var(--pri);
  border-radius: 4px;
}
.frr-audit-head {
  display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;
}
.frr-audit-action {
  font-size: 12.5px; font-weight: 700; color: var(--td);
}
.frr-audit-time {
  font-size: 11px; color: var(--tm); font-family: monospace;
}
.frr-audit-meta {
  margin-top: 2px; font-size: 11.5px; color: var(--tm);
}
.frr-audit-system { font-style: italic; }
.frr-audit-desc {
  margin-top: 4px; font-size: 11.5px; color: #4a4a4a;
  word-break: break-word; line-height: 1.45;
}
</style>
