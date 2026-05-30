<script setup>
/**
 * MasterFormModal — generic modal form untuk CRUD master data.
 *
 * Props:
 *   open         : boolean (controls visibility)
 *   title        : string
 *   fields       : Array<FieldConfig>
 *   modelValue   : object (form values, v-model)
 *   submitting   : boolean
 *   errors       : object | null  — Laravel 422 errors: { field: [msg] }
 *   submitLabel  : string (default 'Simpan')
 *   width        : string (CSS width, default '520px')
 *
 * FieldConfig:
 *   { key, label, type?: 'text'|'number'|'textarea'|'select'|'checkbox'|'date',
 *     required?, placeholder?, options?: [{value,label}], min?, max?, step?,
 *     hint?, cols?: 1|2 (grid span, default 2 = full width), disabled?,
 *     showIf?: (form) => boolean  — sembunyikan field secara dinamis }
 *
 * Emits:
 *   update:modelValue (obj)
 *   update:open       (bool)
 *   submit            (obj)  — payload final
 *   close             ()
 *   field-action      ({ key, form })  — tombol aksi opsional di samping field
 *                                         (field config: action: { label, event? })
 */
import { computed, watch, ref } from 'vue'

const props = defineProps({
  open:        { type: Boolean, default: false },
  title:       { type: String, required: true },
  fields:      { type: Array, required: true },
  modelValue:  { type: Object, default: () => ({}) },
  submitting:  { type: Boolean, default: false },
  errors:      { type: Object, default: null },
  submitLabel: { type: String, default: 'Simpan' },
  width:       { type: String, default: '520px' },
})

const emit = defineEmits(['update:modelValue', 'update:open', 'submit', 'close', 'field-action'])

const form = ref({ ...props.modelValue })

// Sync external -> local HANYA saat nilai luar benar-benar berbeda dari form
// (mis. modal dibuka dengan data baru, atau parent setModalField). Tanpa guard
// ini, emit `update:modelValue` di bawah memicu watcher ini balik → ping-pong
// tak berujung ("Maximum recursive updates exceeded").
watch(() => props.modelValue, (v) => {
  if (JSON.stringify(v) !== JSON.stringify(form.value)) {
    form.value = { ...v }
  }
}, { deep: true })

// Sync local -> external — skip emit kalau nilainya sudah identik dgn prop
// (hindari memicu watcher di atas tanpa perlu).
watch(form, (v) => {
  if (JSON.stringify(v) !== JSON.stringify(props.modelValue)) {
    emit('update:modelValue', { ...v })
  }
}, { deep: true })

function fieldError(key) {
  if (!props.errors) return null
  const msgs = props.errors[key]
  if (!msgs) return null
  return Array.isArray(msgs) ? msgs[0] : String(msgs)
}

function close() {
  emit('update:open', false)
  emit('close')
}

function submit() {
  emit('submit', { ...form.value })
}

function onBackdrop(e) {
  if (e.target === e.currentTarget) close()
}

function onKeydown(e) {
  if (e.key === 'Escape') close()
}

// Fields yang lolos showIf (kalau ada). Bergantung ke form.value supaya reaktif.
const visibleFields = computed(() =>
  props.fields.filter((f) => (typeof f.showIf === 'function' ? f.showIf(form.value) : true))
)

const colsMap = computed(() => {
  // Build grid template based on widest field cols requested
  return visibleFields.value.some((f) => (f.cols ?? 2) === 1) ? '1fr 1fr' : '1fr'
})
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="mfm-overlay" @click="onBackdrop" @keydown="onKeydown" tabindex="-1">
      <div class="mfm-modal" :style="{ width }">
        <div class="mfm-head">
          <h3>{{ title }}</h3>
          <button class="mfm-close" @click="close" aria-label="Tutup">
            <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
          </button>
        </div>

        <form class="mfm-body" :style="{ gridTemplateColumns: colsMap }" @submit.prevent="submit">
          <div
            v-for="f in visibleFields"
            :key="f.key"
            class="mfm-field"
            :style="{ gridColumn: (f.cols ?? 2) === 2 ? '1 / -1' : 'auto' }"
          >
            <label v-if="f.type !== 'checkbox'" :for="`mfm-${f.key}`">
              {{ f.label }}
              <span v-if="f.required" class="mfm-req">*</span>
            </label>

            <!-- TEXTAREA -->
            <textarea
              v-if="f.type === 'textarea'"
              :id="`mfm-${f.key}`"
              v-model="form[f.key]"
              :placeholder="f.placeholder"
              :disabled="f.disabled"
              :rows="f.rows ?? 3"
              :class="{ 'has-error': fieldError(f.key) }"
            ></textarea>

            <!-- SELECT -->
            <select
              v-else-if="f.type === 'select'"
              :id="`mfm-${f.key}`"
              v-model="form[f.key]"
              :disabled="f.disabled"
              :class="{ 'has-error': fieldError(f.key) }"
            >
              <option v-if="!f.required" value="">— pilih —</option>
              <option v-for="o in (f.options ?? [])" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>

            <!-- CHECKBOX -->
            <label v-else-if="f.type === 'checkbox'" class="mfm-check">
              <input
                type="checkbox"
                v-model="form[f.key]"
                :disabled="f.disabled"
              />
              <span>{{ f.label }}</span>
            </label>

            <!-- INPUT (text/number/date) — dgn tombol aksi opsional di samping -->
            <div v-else :class="{ 'mfm-input-row': f.action }">
              <input
                :id="`mfm-${f.key}`"
                :type="f.type ?? 'text'"
                v-model="form[f.key]"
                :placeholder="f.placeholder"
                :disabled="f.disabled"
                :min="f.min"
                :max="f.max"
                :step="f.step"
                :list="f.datalistId"
                :class="{ 'has-error': fieldError(f.key) }"
              />
              <button
                v-if="f.action"
                type="button"
                class="mfm-field-action"
                @click="emit('field-action', { key: f.key, form })"
              >{{ f.action.label }}</button>
            </div>
            <datalist v-if="f.datalistId && f.datalistOptions" :id="f.datalistId">
              <option v-for="o in f.datalistOptions" :key="o" :value="o" />
            </datalist>

            <p v-if="fieldError(f.key)" class="mfm-err">{{ fieldError(f.key) }}</p>
            <p v-else-if="f.hint" class="mfm-hint">{{ f.hint }}</p>
          </div>

          <!-- Footer actions span full grid -->
          <div class="mfm-actions">
            <span class="mfm-legend"><span class="mfm-req">*</span> wajib diisi</span>
            <button type="button" class="mfm-btn-secondary" :disabled="submitting" @click="close">Batal</button>
            <button type="submit" class="mfm-btn-primary" :disabled="submitting">
              <span v-if="submitting" class="mfm-spinner"></span>
              {{ submitting ? 'Menyimpan…' : submitLabel }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.mfm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 9000; backdrop-filter: blur(3px); padding: 1rem; }
.mfm-modal { background: var(--bc); border-radius: 16px; max-width: 95vw; max-height: 90vh; border: 1px solid var(--gb); box-shadow: 0 20px 60px rgba(0,0,0,0.22); overflow: hidden; display: flex; flex-direction: column; }
.mfm-head { padding: 1.1rem 1.4rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; background: var(--bs); }
.mfm-head h3 { font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--td); margin: 0; }
.mfm-close { width: 28px; height: 28px; border: none; background: transparent; border-radius: 7px; cursor: pointer; color: var(--tm); display: flex; align-items: center; justify-content: center; }
.mfm-close:hover { background: var(--gl); color: var(--td); }
.mfm-close svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

.mfm-body { padding: 1.3rem 1.4rem; display: grid; gap: 0.95rem 1rem; overflow-y: auto; }

.mfm-field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
.mfm-field label { font-size: 12px; font-weight: 600; color: var(--tm); text-transform: uppercase; letter-spacing: 0.03em; }
.mfm-req { color: #dc2626; font-weight: 700; margin-left: 2px; }

.mfm-field input,
.mfm-field select,
.mfm-field textarea {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid var(--gb);
  border-radius: 9px;
  background: var(--bc);
  font-size: 13px;
  color: var(--td);
  font-family: inherit;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.mfm-field input:focus,
.mfm-field select:focus,
.mfm-field textarea:focus {
  outline: none;
  border-color: var(--ga);
  box-shadow: 0 0 0 3px rgba(31,125,74,0.12);
}
.mfm-field input:disabled,
.mfm-field select:disabled,
.mfm-field textarea:disabled {
  background: var(--bs);
  color: var(--tu);
  cursor: not-allowed;
}
.mfm-field textarea { resize: vertical; min-height: 70px; }
.mfm-field input.has-error,
.mfm-field select.has-error,
.mfm-field textarea.has-error { border-color: var(--ebd); background: var(--eb); }

.mfm-check { display: flex !important; align-items: center; gap: 8px; flex-direction: row !important; text-transform: none !important; letter-spacing: normal !important; font-size: 13px !important; color: var(--td) !important; font-weight: 400 !important; cursor: pointer; }
.mfm-check input { width: 16px; height: 16px; accent-color: var(--ga); margin: 0; }

.mfm-err { font-size: 11px; color: var(--et); margin: 0; }
.mfm-hint { font-size: 11px; color: var(--tu); margin: 0; }
.mfm-input-row { display: flex; gap: 6px; align-items: stretch; }
.mfm-input-row input { flex: 1; }
.mfm-field-action { flex-shrink: 0; padding: 0 12px; border: 1px solid #1763d4; background: #fff; color: #1763d4; border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.mfm-field-action:hover { background: #1763d4; color: #fff; }

.mfm-actions { grid-column: 1 / -1; display: flex; align-items: center; justify-content: flex-end; gap: 0.6rem; padding-top: 0.5rem; border-top: 1px solid var(--gb); margin-top: 0.3rem; }
.mfm-legend { margin-right: auto; font-size: 11.5px; color: var(--tm); }
.mfm-btn-primary,
.mfm-btn-secondary { padding: 9px 18px; border-radius: 9px; font-size: 13px; font-weight: 500; cursor: pointer; border: 1px solid; display: inline-flex; align-items: center; gap: 7px; transition: background 0.15s, border-color 0.15s; }
.mfm-btn-primary { background: var(--ga); color: white; border-color: var(--ga); }
.mfm-btn-primary:hover:not(:disabled) { background: var(--gm); border-color: var(--gm); }
.mfm-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.mfm-btn-secondary { background: var(--bc); color: var(--tm); border-color: var(--gb); }
.mfm-btn-secondary:hover:not(:disabled) { background: var(--bs); }
.mfm-btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }

.mfm-spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid rgba(255,255,255,0.4); border-top-color: white; border-radius: 50%; animation: mfm-spin 0.7s linear infinite; }
@keyframes mfm-spin { to { transform: rotate(360deg); } }
</style>
