<script setup>
/**
 * FormFieldRenderer — render 1 field di INPUT mode.
 *
 * Props:
 *   - field    : { key, label, type, required, binding, options?, items?, ... }
 *   - modelValue : nilai field
 *   - error    : pesan error inline (server-side validation)
 *   - readonly : disable input
 *
 * Type yang didukung Fase 3:
 *   - text, longtext, date, time, number, enum_gender, multi_checkbox,
 *     radio_with_detail, structured_list
 *   - signature_canvas / signature_placeholder → render placeholder visual
 *     ("(akan di-tanda-tangan saat finalize)"), TIDAK kirim ke submit. Fase 4.
 */
import { ref } from 'vue'
import SignatureCaptureModal from './signature/SignatureCaptureModal.vue'

const props = defineProps({
  field:      { type: Object, required: true },
  modelValue: { default: null },
  error:      { type: String, default: '' },
  readonly:   { type: Boolean, default: false },
  documentName: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'capture-signature'])

const sigModalOpen = ref(false)

function onMultiCheckChange(opt, checked) {
  const value = typeof opt === 'object' ? opt.value : opt
  const current = Array.isArray(props.modelValue) ? [...props.modelValue] : []
  const idx = current.indexOf(value)
  if (checked && idx === -1) current.push(value)
  if (!checked && idx !== -1) current.splice(idx, 1)
  emit('update:modelValue', current)
}
</script>

<template>
  <div class="ffr-field" :class="{ 'has-error': error }">
    <label class="ffr-label">
      <span>{{ field.label }}<em v-if="field.required" class="ffr-req">*</em></span>
      <code class="ffr-key">{{ field.key }}</code>
    </label>

    <!-- TEXT -->
    <input
      v-if="field.type === 'text'"
      type="text"
      :value="modelValue ?? ''"
      :disabled="readonly"
      @input="$emit('update:modelValue', $event.target.value)"
      class="ffr-input"
    />

    <!-- LONGTEXT -->
    <textarea
      v-else-if="field.type === 'longtext' || field.type === 'block_freetext'"
      :value="modelValue ?? ''"
      :disabled="readonly"
      @input="$emit('update:modelValue', $event.target.value)"
      class="ffr-textarea"
      rows="3"
    ></textarea>

    <!-- DATE -->
    <input
      v-else-if="field.type === 'date'"
      type="date"
      :value="modelValue ?? ''"
      :disabled="readonly"
      @input="$emit('update:modelValue', $event.target.value)"
      class="ffr-input"
    />

    <!-- TIME -->
    <input
      v-else-if="field.type === 'time'"
      type="time"
      :value="modelValue ?? ''"
      :disabled="readonly"
      @input="$emit('update:modelValue', $event.target.value)"
      class="ffr-input"
    />

    <!-- NUMBER -->
    <input
      v-else-if="field.type === 'number'"
      type="number"
      :value="modelValue ?? ''"
      :disabled="readonly"
      @input="$emit('update:modelValue', $event.target.value === '' ? null : Number($event.target.value))"
      class="ffr-input"
    />

    <!-- ENUM_GENDER (L/P) -->
    <div v-else-if="field.type === 'enum_gender'" class="ffr-radios">
      <label>
        <input type="radio" :checked="modelValue === 'L'" :disabled="readonly" @change="$emit('update:modelValue', 'L')" />
        Laki-laki (L)
      </label>
      <label>
        <input type="radio" :checked="modelValue === 'P'" :disabled="readonly" @change="$emit('update:modelValue', 'P')" />
        Perempuan (P)
      </label>
    </div>

    <!-- MULTI_CHECKBOX -->
    <!-- field.options: list<string> atau list<{value,label}>. Kalau kosong → -->
    <!-- fallback single checkbox boolean (legacy/consent). -->
    <div v-else-if="field.type === 'multi_checkbox'" class="ffr-multicheck">
      <template v-if="Array.isArray(field.options) && field.options.length">
        <label
          v-for="(opt, i) in field.options"
          :key="i"
          class="ffr-checkbox"
        >
          <input
            type="checkbox"
            :checked="Array.isArray(modelValue) && modelValue.includes(typeof opt === 'object' ? opt.value : opt)"
            :disabled="readonly"
            @change="onMultiCheckChange(opt, $event.target.checked)"
          />
          <span>{{ typeof opt === 'object' ? (opt.label ?? opt.value) : opt }}</span>
        </label>
      </template>
      <label v-else class="ffr-checkbox">
        <input
          type="checkbox"
          :checked="!!modelValue"
          :disabled="readonly"
          @change="$emit('update:modelValue', $event.target.checked)"
        />
        <span>Setuju</span>
      </label>
    </div>

    <!-- RADIO_WITH_DETAIL -->
    <div v-else-if="field.type === 'radio_with_detail'" class="ffr-radio-detail">
      <div class="ffr-radios">
        <label v-for="opt in (field.options ?? ['Ya', 'Tidak'])" :key="opt">
          <input
            type="radio"
            :checked="modelValue === opt"
            :disabled="readonly"
            @change="$emit('update:modelValue', opt)"
          />
          {{ opt }}
        </label>
      </div>
    </div>

    <!-- STRUCTURED_LIST -->
    <!-- Render list bernomor; user isi nilai per item. Disimpan sebagai array string. -->
    <div v-else-if="field.type === 'structured_list'" class="ffr-list">
      <div v-for="(item, idx) in (field.items ?? [])" :key="idx" class="ffr-list-row">
        <span class="ffr-list-label">{{ item }}</span>
        <input
          type="text"
          :value="(modelValue ?? [])[idx] ?? ''"
          :disabled="readonly"
          @input="updateListItem(idx, $event.target.value)"
          class="ffr-input"
        />
      </div>
    </div>

    <!-- SCORED_RADIO — option dengan skor (Fase 5) -->
    <div v-else-if="field.type === 'scored_radio'" class="ffr-scored">
      <label v-for="opt in (field.options ?? [])" :key="opt.label + opt.score" class="ffr-scored-opt">
        <input
          type="radio"
          :checked="modelValue === opt.score"
          :disabled="readonly"
          @change="$emit('update:modelValue', opt.score)"
        />
        <span class="ffr-scored-text">{{ opt.label }}</span>
        <span class="ffr-scored-pts">{{ opt.score }} pts</span>
      </label>
    </div>

    <!-- COMPUTED_* — read-only display (dihitung di parent via ScoringEngine) -->
    <div v-else-if="field.type === 'computed_sum'" class="ffr-computed ffr-computed-sum">
      <strong>{{ modelValue ?? 0 }}</strong>
      <span class="ffr-computed-hint">Hasil dari: {{ (field.sum_of ?? []).join(' + ') }}</span>
    </div>

    <div v-else-if="field.type === 'computed_threshold'" class="ffr-computed ffr-computed-threshold">
      <strong>{{ modelValue ?? '—' }}</strong>
      <span class="ffr-computed-hint">Berdasar: <code>{{ field.based_on }}</code></span>
    </div>

    <div v-else-if="field.type === 'computed_duration'" class="ffr-computed ffr-computed-duration">
      <strong>{{ modelValue !== null && modelValue !== undefined ? modelValue + ' menit' : '—' }}</strong>
      <span class="ffr-computed-hint"><code>{{ field.from }}</code> &rarr; <code>{{ field.to }}</code></span>
    </div>

    <!-- SIGNATURE_CANVAS — Fase 4 capture flow -->
    <div v-else-if="field.type === 'signature_canvas'" class="ffr-signature-row">
      <div v-if="modelValue" class="ffr-sig-captured">
        <span>Tertanda ✓</span>
        <button type="button" class="ffr-sig-btn-ghost" :disabled="readonly" @click="sigModalOpen = true">Ulangi TTD</button>
      </div>
      <button v-else type="button" class="ffr-sig-btn" :disabled="readonly" @click="sigModalOpen = true">
        Buka Canvas Tanda Tangan
      </button>
      <SignatureCaptureModal
        v-model="sigModalOpen"
        :signer-type="field.signer_type || 'patient'"
        :signer-label="field.label"
        :document-name="documentName"
        :ask-external-identity="['witness', 'guardian'].includes(field.signer_type)"
        @capture="(payload) => { $emit('update:modelValue', payload); $emit('capture-signature', { field, payload }); }"
      />
    </div>

    <!-- SIGNATURE_PLACEHOLDER — untuk print (TTD basah di kertas) -->
    <div v-else-if="field.type === 'signature_placeholder'" class="ffr-signature">
      <em>(Area tanda tangan basah — akan dicetak kosong untuk diisi manual)</em>
    </div>

    <!-- Fallback -->
    <input
      v-else
      type="text"
      :value="modelValue ?? ''"
      :disabled="readonly"
      @input="$emit('update:modelValue', $event.target.value)"
      class="ffr-input"
    />

    <small v-if="error" class="ffr-error">{{ error }}</small>
  </div>
</template>

<script>
// Helper: update array structured_list at index → emit replacement array.
export default {
  methods: {
    updateListItem(idx, val) {
      const arr = Array.isArray(this.modelValue) ? [...this.modelValue] : []
      arr[idx] = val
      this.$emit('update:modelValue', arr)
    },
  },
}
</script>

<style scoped>
.ffr-field { display: flex; flex-direction: column; margin-bottom: 0.75rem; }
.ffr-field.has-error .ffr-input,
.ffr-field.has-error .ffr-textarea { border-color: #d4495a; }

.ffr-label {
  display: flex; justify-content: space-between; align-items: baseline;
  font-size: 12.5px; color: var(--tm); margin-bottom: 4px;
}
.ffr-label span { color: var(--td); font-weight: 600; }
.ffr-req { color: #d4495a; font-style: normal; margin-left: 2px; }
.ffr-key { font-size: 10.5px; color: var(--tm); font-family: monospace; }

.ffr-input, .ffr-textarea {
  padding: 0.45rem 0.65rem; border: 1px solid var(--gb); border-radius: 6px;
  font-size: 13.5px; background: white; width: 100%;
}
.ffr-input:disabled, .ffr-textarea:disabled { background: var(--bg); color: var(--tm); cursor: not-allowed; }
.ffr-textarea { resize: vertical; font-family: inherit; }

.ffr-radios { display: flex; gap: 1rem; flex-wrap: wrap; }
.ffr-radios label { display: flex; align-items: center; gap: 0.4rem; font-size: 13.5px; cursor: pointer; }
.ffr-checkbox { display: flex; align-items: center; gap: 0.5rem; font-size: 13.5px; cursor: pointer; }
.ffr-multicheck { display: flex; flex-direction: column; gap: 0.3rem; }

.ffr-list { display: flex; flex-direction: column; gap: 0.35rem; }
.ffr-list-row { display: grid; grid-template-columns: 140px 1fr; gap: 0.5rem; align-items: center; }
.ffr-list-label { font-size: 12.5px; color: var(--tm); }

.ffr-signature {
  padding: 0.75rem 1rem; background: #fff7ec; border: 1px dashed #d4ad6c; border-radius: 6px;
  font-size: 12.5px; color: #946600;
}

.ffr-scored { display: flex; flex-direction: column; gap: 0.3rem; }
.ffr-scored-opt {
  display: flex; align-items: center; gap: 0.6rem;
  padding: 0.45rem 0.65rem; background: white; border: 1px solid var(--gb); border-radius: 6px;
  cursor: pointer; font-size: 13px; transition: all 120ms;
}
.ffr-scored-opt:hover { background: var(--bg); border-color: #b8c1cf; }
.ffr-scored-opt input[type=radio]:checked + .ffr-scored-text { font-weight: 600; color: var(--pri); }
.ffr-scored-text { flex: 1; }
.ffr-scored-pts {
  padding: 2px 8px; background: #f0f1f4; color: var(--tm); border-radius: 999px; font-size: 11px; font-weight: 600;
}

.ffr-computed {
  display: flex; align-items: baseline; gap: 0.6rem;
  padding: 0.65rem 0.9rem; border: 1px solid var(--gb); border-radius: 6px;
}
.ffr-computed-sum       { background: #f0f7ff; border-color: #c7dbf5; }
.ffr-computed-threshold { background: #fff7ec; border-color: #f0d2a5; }
.ffr-computed-duration  { background: #ecf9ee; border-color: #cce8d2; }
.ffr-computed strong { font-size: 17px; color: var(--td); }
.ffr-computed-hint { font-size: 11px; color: var(--tm); font-family: monospace; }
.ffr-signature-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.ffr-sig-btn {
  padding: 0.55rem 1rem; background: var(--pri); color: white; border: 0; border-radius: 6px;
  cursor: pointer; font-size: 13px;
}
.ffr-sig-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.ffr-sig-btn-ghost {
  padding: 0.35rem 0.75rem; border: 1px solid var(--gb); background: white; border-radius: 4px;
  cursor: pointer; font-size: 12px; color: var(--tm);
}
.ffr-sig-captured {
  display: flex; align-items: center; gap: 0.75rem;
  padding: 0.45rem 0.85rem; background: #ecf9ee; border: 1px solid #cce8d2; border-radius: 6px;
  font-size: 12.5px; color: #1e8a3a; font-weight: 600;
}

.ffr-error { margin-top: 4px; font-size: 11.5px; color: #d4495a; }
</style>
