<script setup>
/**
 * MapperStep — Step 2 wizard.
 * Layout 2 kolom: identitas + field rows (kiri) | TipTap editor (kanan).
 *
 * Catatan:
 *   - draft di-passing via v-model (parent FormTemplateWizard memiliki state).
 *   - field row punya status icon ✅ (high) ⚠️ (medium) ❌ (none/static) berdasarkan
 *     binding suggestion atau jenis binding yang sudah di-set.
 *   - BindingPickerModal apply pilihan via emit('select', binding).
 *   - Tombol "+ Tambah Field" tambah row baru ke field_schema.fields.
 */
import { computed, ref } from 'vue'
import { useFormTemplateStore } from '@/stores/formTemplateStore'
import LayoutEditor from '@/components/master/form-template/LayoutEditor.vue'
import BindingPickerModal from '@/components/master/form-template/BindingPickerModal.vue'

const props = defineProps({
  modelValue: { type: Object, required: true },
})
const emit = defineEmits(['update:modelValue', 'back', 'next'])

const store = useFormTemplateStore()

const draft = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

// Tipe field yang admin bisa pilih untuk row baru / edit type.
const FIELD_TYPES = [
  'text', 'longtext', 'date', 'time', 'number', 'enum_gender',
  'multi_checkbox', 'radio_with_detail', 'structured_list',
  'signature_canvas', 'signature_placeholder',
  // Fase 5 — SCORED_FORM types
  'scored_radio', 'computed_sum', 'computed_threshold', 'computed_duration',
]

// Daftar key field yang bisa di-reference oleh computed_sum / computed_threshold
const allFieldKeys = computed(() => (draft.value.field_schema?.fields ?? []).map(f => f.key))
const scoredFieldKeys = computed(() =>
  (draft.value.field_schema?.fields ?? []).filter(f => f.type === 'scored_radio').map(f => f.key)
)
const numericFieldKeys = computed(() =>
  (draft.value.field_schema?.fields ?? [])
    .filter(f => f.type === 'computed_sum' || f.type === 'number')
    .map(f => f.key)
)

const KIND_OPTIONS = ['INPUT', 'OUTPUT', 'HYBRID']
const COMPLEXITY_OPTIONS = ['SIMPLE_BINDING', 'SCORED_FORM', 'CUSTOM_COMPONENT']

const placeholders = computed(() => (draft.value.field_schema?.fields ?? []).map(f => f.key))

const codeError = ref('')
function validateCode() {
  const code = draft.value.code?.trim() ?? ''
  if (!code) { codeError.value = 'Code wajib diisi.'; return }
  if (!/^[A-Z0-9_]+$/.test(code)) { codeError.value = 'Format: huruf besar, angka, underscore.'; return }
  codeError.value = ''
}

// ── Field operations ────────────────────────────────────────────────────
const pickerOpen = ref(false)
const pickerField = ref(null)

function openPicker(field) {
  pickerField.value = field
  pickerOpen.value = true
}

function applyBinding(binding) {
  if (!pickerField.value) return
  pickerField.value.binding = binding
}

function addField() {
  const newKey = nextUniqueKey('field')
  draft.value.field_schema.fields.push({
    key: newKey,
    label: 'Field Baru',
    type: 'text',
    binding: { kind: 'static', value: null },
  })
}

function removeField(idx) {
  if (!confirm('Hapus field ini? Placeholder yang sudah disisip di layout akan tetap muncul (kosong).')) return
  draft.value.field_schema.fields.splice(idx, 1)
}

function nextUniqueKey(prefix) {
  const existing = new Set((draft.value.field_schema?.fields ?? []).map(f => f.key))
  let i = 1
  while (existing.has(`${prefix}_${i}`)) i++
  return `${prefix}_${i}`
}

function renameKey(field, newKey) {
  const slug = newKey.trim().toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '')
  if (!slug) return
  if (placeholders.value.includes(slug) && slug !== field.key) {
    alert('Key sudah dipakai field lain.')
    return
  }
  field.key = slug
}

// ── Fase 5: scored_radio options editor ─────────────────────────────────
function addOption(field) {
  if (!Array.isArray(field.options)) field.options = []
  field.options.push({ label: '', score: 0 })
}
function removeOption(field, idx) {
  field.options?.splice(idx, 1)
}

// ── multi_checkbox options editor ───────────────────────────────────────
function addCheckboxOption(field) {
  if (!Array.isArray(field.options)) field.options = []
  field.options.push({ value: '', label: '' })
}
function removeCheckboxOption(field, idx) {
  field.options?.splice(idx, 1)
}

// ── Multi-page wizard ──────────────────────────────────────────────────
// Field tanpa `page` di-treat sebagai page 1. UI grouping read-only;
// save as-is (flat fields[] dengan kolom page) — backend renderer iterasi
// flat list.
const pageCount = computed(() => {
  const pages = (draft.value.field_schema?.fields ?? [])
    .map(f => Number.isInteger(f.page) ? f.page : 1)
  return Math.max(1, ...pages, draft.value.field_schema?.page_count ?? 1)
})

function setFieldPage(field, page) {
  field.page = Math.max(1, parseInt(page) || 1)
}

function addPage() {
  if (!draft.value.field_schema) return
  draft.value.field_schema.page_count = pageCount.value + 1
  draft.value.field_schema.layout_mode = 'multi_page'
}

function removeLastPage() {
  const last = pageCount.value
  if (last <= 1) return
  if (!confirm(`Hapus Page ${last}? Field di page ini akan dipindah ke Page ${last - 1}.`)) return
  for (const f of (draft.value.field_schema?.fields ?? [])) {
    if ((f.page ?? 1) === last) f.page = last - 1
  }
  draft.value.field_schema.page_count = last - 1
  if (last - 1 === 1) {
    draft.value.field_schema.layout_mode = 'single_page'
  }
}

function fieldsForPage(page) {
  return (draft.value.field_schema?.fields ?? []).filter(f => (f.page ?? 1) === page)
}

// ── Fase 5: computed_sum sum_of editor ──────────────────────────────────
function addSumOf(field, key) {
  if (!key) return
  if (!Array.isArray(field.sum_of)) field.sum_of = []
  if (!field.sum_of.includes(key)) field.sum_of.push(key)
}
function removeSumOf(field, key) {
  field.sum_of = (field.sum_of ?? []).filter(k => k !== key)
}

// ── Fase 5: computed_threshold editor ───────────────────────────────────
function addThreshold(field) {
  if (!Array.isArray(field.thresholds)) field.thresholds = []
  field.thresholds.push({ max: 0, label: '' })
}
function removeThreshold(field, idx) {
  field.thresholds?.splice(idx, 1)
}

// ── Status badge per field ──────────────────────────────────────────────
function fieldStatus(f) {
  const kind = f.binding?.kind
  if (kind === 'db' || kind === 'clinic' || kind === 'aggregate') return 'ok'
  if (f._suggestion?.tier === 'high')   return 'high'
  if (f._suggestion?.tier === 'medium') return 'medium'
  return 'none'
}

function isReady() {
  if (!draft.value.code || codeError.value) return false
  if (!draft.value.name?.trim()) return false
  if (!draft.value.document_type_id) return false
  if (!draft.value.layout_html?.trim()) return false
  return true
}

function onNext() {
  validateCode()
  if (!isReady()) {
    alert('Lengkapi Code, Nama, Jenis Dokumen, dan layout sebelum lanjut.')
    return
  }
  emit('next')
}
</script>

<template>
  <div class="ms-wrap">
    <div class="ms-grid">
      <!-- ── Kiri: Identitas + Field rows ── -->
      <div class="ms-left">
        <section class="ms-card">
          <h4>Identitas Template</h4>

          <label class="ms-field">
            <span>Code <em>*</em></span>
            <input v-model="draft.code" @input="validateCode" :disabled="draft.code_locked_at" placeholder="SURAT_X" />
            <small v-if="codeError" class="ms-err">{{ codeError }}</small>
            <small v-else-if="draft.code_locked_at" class="ms-hint">Code terkunci sejak {{ draft.code_locked_at }}</small>
            <small v-else class="ms-hint">Huruf besar, angka, underscore. Tidak dapat diubah setelah aktif.</small>
          </label>

          <label class="ms-field">
            <span>Nama <em>*</em></span>
            <input v-model="draft.name" placeholder="Surat Keterangan ..." />
          </label>

          <label class="ms-field">
            <span>Jenis Dokumen (parent) <em>*</em></span>
            <select v-model="draft.document_type_id">
              <option :value="null" disabled>— Pilih kategori —</option>
              <option v-for="d in store.documentTypes ?? []" :key="d.id" :value="d.id">
                {{ d.code }} — {{ d.name }}
              </option>
            </select>
          </label>

          <div class="ms-field-row">
            <label class="ms-field">
              <span>Jenis (kind)</span>
              <select v-model="draft.kind">
                <option v-for="k in KIND_OPTIONS" :key="k" :value="k">{{ k }}</option>
              </select>
            </label>

            <label class="ms-field">
              <span>Kompleksitas</span>
              <select v-model="draft.complexity_kind">
                <option v-for="c in COMPLEXITY_OPTIONS" :key="c" :value="c">{{ c }}</option>
              </select>
            </label>
          </div>
        </section>

        <section class="ms-card">
          <div class="ms-card-head">
            <h4>Field &amp; Binding ({{ placeholders.length }})</h4>
            <div class="ms-page-ctrl">
              <span class="ms-page-info" v-if="pageCount > 1">{{ pageCount }} halaman</span>
              <button class="ms-btn-sm" @click="addPage" title="Tambah halaman">+ Page</button>
              <button v-if="pageCount > 1" class="ms-btn-sm ms-btn-danger" @click="removeLastPage" title="Hapus halaman terakhir">− Page</button>
              <button class="ms-btn-sm" @click="addField">+ Tambah Field</button>
            </div>
          </div>

          <div v-if="placeholders.length === 0" class="ms-empty">
            Belum ada field. Klik "+ Tambah Field" atau upload .docx untuk parser auto-extract.
          </div>

          <div v-else class="ms-fields">
            <div v-for="(f, idx) in draft.field_schema.fields" :key="idx" class="ms-row">
              <span class="ms-row-status" :class="`status-${fieldStatus(f)}`">
                <span v-if="fieldStatus(f) === 'ok'">✓</span>
                <span v-else-if="fieldStatus(f) === 'high'">★</span>
                <span v-else-if="fieldStatus(f) === 'medium'">!</span>
                <span v-else>?</span>
              </span>

              <div class="ms-row-main">
                <div class="ms-row-line1">
                  <input
                    class="ms-key"
                    :value="f.key"
                    @change="renameKey(f, $event.target.value)"
                    placeholder="key"
                  />
                  <select v-model="f.type" class="ms-type">
                    <option v-for="t in FIELD_TYPES" :key="t" :value="t">{{ t }}</option>
                  </select>
                  <select
                    v-if="pageCount > 1"
                    class="ms-page-sel"
                    :value="f.page ?? 1"
                    @change="setFieldPage(f, $event.target.value)"
                    title="Halaman"
                  >
                    <option v-for="p in pageCount" :key="p" :value="p">Hal {{ p }}</option>
                  </select>
                  <button class="ms-del" @click="removeField(idx)" aria-label="Hapus">×</button>
                </div>
                <input class="ms-label" v-model="f.label" placeholder="Label di form" />
                <button class="ms-bind" @click="openPicker(f)">
                  <span class="ms-bind-kind">{{ f.binding.kind ?? 'static' }}</span>
                  <code>{{ f.binding.source ?? (f.binding.kind === 'static' ? '(null)' : '?') }}</code>
                  <span v-if="f.binding.format" class="ms-bind-fmt">· {{ f.binding.format }}</span>
                </button>

                <!-- Fase 5: scored_radio options editor -->
                <div v-if="f.type === 'scored_radio'" class="ms-extra">
                  <label class="ms-extra-label">Pilihan + Skor</label>
                  <div v-for="(opt, oi) in (f.options ?? [])" :key="oi" class="ms-opt-row">
                    <input class="ms-opt-label" v-model="opt.label" placeholder="Label pilihan" />
                    <input class="ms-opt-score" type="number" v-model.number="opt.score" placeholder="Skor" />
                    <button class="ms-del-sm" @click="removeOption(f, oi)">×</button>
                  </div>
                  <button class="ms-btn-link" @click="addOption(f)">+ Tambah Pilihan</button>
                </div>

                <!-- multi_checkbox options editor -->
                <div v-if="f.type === 'multi_checkbox'" class="ms-extra">
                  <label class="ms-extra-label">Pilihan Checkbox (kosongkan untuk mode single-boolean legacy)</label>
                  <div v-for="(opt, oi) in (f.options ?? [])" :key="oi" class="ms-opt-row">
                    <input class="ms-opt-label" v-model="opt.value" placeholder="Value (key)" />
                    <input class="ms-opt-label" v-model="opt.label" placeholder="Label tampilan" />
                    <button class="ms-del-sm" @click="removeCheckboxOption(f, oi)">×</button>
                  </div>
                  <button class="ms-btn-link" @click="addCheckboxOption(f)">+ Tambah Pilihan</button>
                </div>

                <!-- Fase 5: computed_sum sum_of editor -->
                <div v-if="f.type === 'computed_sum'" class="ms-extra">
                  <label class="ms-extra-label">Jumlahkan dari field</label>
                  <div class="ms-chips">
                    <span v-for="key in (f.sum_of ?? [])" :key="key" class="ms-chip">
                      {{ key }}
                      <button class="ms-chip-x" @click="removeSumOf(f, key)">×</button>
                    </span>
                  </div>
                  <select class="ms-select" @change="addSumOf(f, $event.target.value); $event.target.value = ''">
                    <option value="">+ Pilih field scored…</option>
                    <option v-for="k in scoredFieldKeys" :key="k" :value="k" :disabled="(f.sum_of ?? []).includes(k)">
                      {{ k }}
                    </option>
                  </select>
                </div>

                <!-- Fase 5: computed_threshold editor -->
                <div v-if="f.type === 'computed_threshold'" class="ms-extra">
                  <label class="ms-extra-label">Berdasarkan field</label>
                  <select class="ms-select" v-model="f.based_on">
                    <option :value="null">— Pilih —</option>
                    <option v-for="k in numericFieldKeys" :key="k" :value="k">{{ k }}</option>
                  </select>

                  <label class="ms-extra-label">Threshold</label>
                  <div v-for="(th, ti) in (f.thresholds ?? [])" :key="ti" class="ms-th-row">
                    <span class="ms-th-prefix">≤</span>
                    <input class="ms-th-max" type="number" v-model.number="th.max" placeholder="Max" />
                    <input class="ms-th-label" v-model="th.label" placeholder="Label (mis. Risiko Rendah)" />
                    <button class="ms-del-sm" @click="removeThreshold(f, ti)">×</button>
                  </div>
                  <button class="ms-btn-link" @click="addThreshold(f)">+ Tambah Threshold</button>
                </div>

                <!-- Fase 5: computed_duration editor -->
                <div v-if="f.type === 'computed_duration'" class="ms-extra">
                  <label class="ms-extra-label">Dari → Sampai</label>
                  <div class="ms-duration">
                    <select class="ms-select" v-model="f.from">
                      <option :value="null">— Dari —</option>
                      <option v-for="k in allFieldKeys" :key="k" :value="k">{{ k }}</option>
                    </select>
                    <span>→</span>
                    <select class="ms-select" v-model="f.to">
                      <option :value="null">— Sampai —</option>
                      <option v-for="k in allFieldKeys" :key="k" :value="k">{{ k }}</option>
                    </select>
                  </div>
                </div>

                <!-- Signature canvas signer_type selector -->
                <div v-if="f.type === 'signature_canvas'" class="ms-extra">
                  <label class="ms-extra-label">Signer Type</label>
                  <select class="ms-select" v-model="f.signer_type">
                    <option :value="null">— Pilih —</option>
                    <option value="patient">Patient</option>
                    <option value="guardian">Guardian</option>
                    <option value="witness">Witness</option>
                    <option value="doctor">Doctor</option>
                    <option value="nurse">Nurse</option>
                    <option value="staff">Staff</option>
                  </select>
                  <label class="ms-extra-required">
                    <input type="checkbox" v-model="f.required" />
                    <span>Wajib sebelum finalize</span>
                  </label>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>

      <!-- ── Kanan: TipTap editor ── -->
      <div class="ms-right">
        <section class="ms-card ms-editor-card">
          <h4>Layout HTML</h4>
          <p class="ms-hint">Klik "+ Insert Field" di toolbar untuk sisipkan placeholder. Edit teks statis (heading, paragraf, tabel) seperti Word.</p>
          <LayoutEditor v-model="draft.layout_html" :placeholders="placeholders" />
        </section>
      </div>
    </div>

    <footer class="ms-foot">
      <button class="ms-btn" @click="emit('back')">← Step 1</button>
      <button class="ms-btn-primary" @click="onNext">Lanjut Step 3 →</button>
    </footer>

    <BindingPickerModal
      v-model="pickerOpen"
      :field="pickerField ?? {}"
      :registry="store.fieldRegistry ?? { columns: {}, aggregates: {} }"
      @select="applyBinding"
    />
  </div>
</template>

<style scoped>
.ms-wrap { display: flex; flex-direction: column; gap: 1rem; }
.ms-grid { display: grid; grid-template-columns: minmax(380px, 1fr) 1.4fr; gap: 1.25rem; }
@media (max-width: 1100px) { .ms-grid { grid-template-columns: 1fr; } }

.ms-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 8px; padding: 1rem 1.25rem; }
.ms-card h4 { margin: 0 0 0.75rem; font-family: 'DM Serif Display', serif; font-size: 16px; color: var(--td); }
.ms-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }

.ms-field { display: flex; flex-direction: column; margin-bottom: 0.75rem; }
.ms-field span { font-size: 12.5px; color: var(--tm); margin-bottom: 4px; }
.ms-field span em { color: #d4495a; font-style: normal; }
.ms-field input, .ms-field select {
  padding: 0.45rem 0.65rem; border: 1px solid var(--gb); border-radius: 6px; font-size: 13.5px;
  background: white;
}
.ms-field input:disabled { background: var(--bg); color: var(--tm); }
.ms-field small.ms-hint { margin-top: 4px; font-size: 11.5px; color: var(--tm); }
.ms-field small.ms-err  { margin-top: 4px; font-size: 11.5px; color: #d4495a; }

.ms-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.ms-page-ctrl { display: flex; gap: 0.4rem; align-items: center; }
.ms-page-info { font-size: 11px; color: var(--tm); padding: 2px 8px; background: var(--bg); border-radius: 999px; }
.ms-btn-danger { color: #c83b3b; border-color: #c83b3b; }
.ms-page-sel { padding: 2px 6px; font-size: 11px; border: 1px solid var(--gb); border-radius: 4px; background: #fff7ec; color: #946600; }

/* Field rows */
.ms-fields { display: flex; flex-direction: column; gap: 0.5rem; }
.ms-row {
  display: flex; gap: 0.5rem; padding: 0.6rem 0.75rem;
  border: 1px solid var(--gb); border-radius: 6px; background: white;
}
.ms-row-status {
  display: inline-flex; align-items: center; justify-content: center;
  width: 26px; height: 26px; border-radius: 50%;
  font-size: 13px; font-weight: 700; flex-shrink: 0;
}
.ms-row-status.status-ok     { background: #ecf9ee; color: #1e8a3a; }
.ms-row-status.status-high   { background: #f0f7ff; color: #1763d4; }
.ms-row-status.status-medium { background: #fff4d8; color: #946600; }
.ms-row-status.status-none   { background: #f0f1f4; color: #8a8d92; }

.ms-row-main { flex: 1; display: flex; flex-direction: column; gap: 0.35rem; }
.ms-row-line1 { display: flex; gap: 0.4rem; }
.ms-key {
  flex: 1; padding: 0.3rem 0.5rem; border: 1px solid var(--gb); border-radius: 4px;
  font-family: monospace; font-size: 12px;
}
.ms-type {
  width: 110px; padding: 0.3rem 0.4rem; border: 1px solid var(--gb); border-radius: 4px; font-size: 12px;
}
.ms-del { width: 26px; background: 0; border: 1px solid var(--gb); border-radius: 4px; cursor: pointer; color: var(--tm); }
.ms-del:hover { background: #fff0f0; color: #b42323; border-color: #fbb; }

.ms-label {
  padding: 0.3rem 0.5rem; border: 1px solid var(--gb); border-radius: 4px; font-size: 13px;
}
.ms-bind {
  display: flex; gap: 0.5rem; align-items: center; padding: 0.35rem 0.6rem;
  background: var(--bg); border: 1px solid var(--gb); border-radius: 4px;
  cursor: pointer; text-align: left;
}
.ms-bind:hover { background: #eef2f6; }
.ms-bind-kind { font-size: 10.5px; padding: 1px 6px; background: white; border-radius: 999px; text-transform: uppercase; font-weight: 600; color: var(--pri); }
.ms-bind code { font-size: 12px; color: var(--td); flex: 1; }
.ms-bind-fmt { font-size: 11px; color: var(--tm); }

.ms-empty { padding: 1rem; text-align: center; color: var(--tm); font-size: 13px; }

.ms-editor-card { display: flex; flex-direction: column; }
.ms-editor-card .ms-hint { color: var(--tm); font-size: 12px; margin: -0.25rem 0 0.5rem; }

.ms-foot { display: flex; justify-content: space-between; padding-top: 0.5rem; }
.ms-btn, .ms-btn-primary, .ms-btn-sm {
  padding: 0.55rem 1rem; border: 1px solid var(--gb); border-radius: 6px;
  background: var(--bc); cursor: pointer; font-size: 13.5px;
}
.ms-btn-sm { padding: 0.35rem 0.7rem; font-size: 12.5px; }
.ms-btn-primary { background: var(--pri); color: white; border-color: var(--pri); }
.ms-btn-primary:hover { filter: brightness(1.08); }

/* Fase 5 — scored/computed editor */
.ms-extra {
  margin-top: 0.5rem; padding: 0.5rem 0.65rem; background: #f6faff; border-radius: 5px;
  display: flex; flex-direction: column; gap: 0.35rem;
}
.ms-extra-label { font-size: 11px; color: var(--tm); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }

.ms-opt-row { display: grid; grid-template-columns: 1fr 70px 26px; gap: 0.3rem; align-items: center; }
.ms-opt-label, .ms-opt-score { padding: 0.3rem 0.45rem; border: 1px solid var(--gb); border-radius: 4px; font-size: 12px; }
.ms-opt-score { text-align: right; font-variant-numeric: tabular-nums; }

.ms-th-row { display: grid; grid-template-columns: 22px 70px 1fr 26px; gap: 0.3rem; align-items: center; }
.ms-th-prefix { color: var(--tm); font-size: 12px; text-align: right; }
.ms-th-max, .ms-th-label { padding: 0.3rem 0.45rem; border: 1px solid var(--gb); border-radius: 4px; font-size: 12px; }

.ms-del-sm {
  width: 24px; height: 24px; background: 0; border: 1px solid var(--gb); border-radius: 4px;
  cursor: pointer; color: var(--tm); font-size: 14px;
}
.ms-del-sm:hover { background: #fff0f0; color: #b42323; border-color: #fbb; }

.ms-btn-link {
  background: 0; border: 0; color: var(--pri); cursor: pointer; font-size: 12px;
  text-align: left; padding: 0.2rem 0; font-weight: 600;
}
.ms-btn-link:hover { text-decoration: underline; }

.ms-chips { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.ms-chip {
  display: inline-flex; align-items: center; gap: 0.3rem;
  padding: 2px 4px 2px 8px; background: white; border: 1px solid var(--gb); border-radius: 999px;
  font-size: 11.5px; font-family: monospace;
}
.ms-chip-x { background: 0; border: 0; cursor: pointer; color: var(--tm); padding: 0 4px; }

.ms-select {
  padding: 0.3rem 0.45rem; border: 1px solid var(--gb); border-radius: 4px; font-size: 12px;
  background: white;
}

.ms-duration { display: flex; gap: 0.3rem; align-items: center; }

.ms-extra-required {
  display: flex; align-items: center; gap: 0.4rem; font-size: 11.5px; color: var(--tm); margin-top: 0.2rem;
}
</style>
