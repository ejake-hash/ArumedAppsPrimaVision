<script setup>
/**
 * SignatureCaptureModal — full-screen modal untuk capture TTD digital.
 *
 * Props:
 *   - modelValue : boolean (v-model open/close)
 *   - signerType : 'patient'|'guardian'|'witness'|'doctor'|'nurse'|'staff'
 *   - signerLabel : label custom ("Tanda Tangan Pasien")
 *   - documentName : nama dokumen untuk header (mis. "Persetujuan Umum")
 *   - confirmReadCopy : string copy untuk checkbox baca dokumen (default sesuai design doc Section 9.2)
 *   - askExternalIdentity : kalau true, prompt nama+NIK+hubungan (untuk witness/guardian)
 *
 * Emit:
 *   - update:modelValue
 *   - capture : { signature_svg, signature_png_base64, biometric_metadata, audit_log, external_identity? }
 */
import { computed, nextTick, ref, watch } from 'vue'
import SignatureCanvas from './SignatureCanvas.vue'

const props = defineProps({
  modelValue:           { type: Boolean, default: false },
  signerType:           { type: String, default: 'patient' },
  signerLabel:          { type: String, default: '' },
  documentName:         { type: String, default: '' },
  confirmReadCopy:      { type: String, default: 'Saya sudah membaca isi dokumen dan menyetujui dengan kesadaran penuh.' },
  askExternalIdentity:  { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'capture'])

const canvasRef = ref(null)
const confirmed = ref(false)
const isEmpty   = ref(true)

const externalIdentity = ref({ nama: '', nik: '', hubungan: '' })

const computedLabel = computed(() => props.signerLabel || `Tanda Tangan ${labelForType(props.signerType)}`)

watch(() => props.modelValue, async (open) => {
  if (!open) return
  confirmed.value = false
  isEmpty.value = true
  externalIdentity.value = { nama: '', nik: '', hubungan: '' }
  await nextTick()
  canvasRef.value?.clear()
})

function labelForType(t) {
  return {
    patient: 'Pasien',
    guardian: 'Wali',
    witness: 'Saksi',
    doctor: 'Dokter',
    nurse: 'Perawat',
    staff: 'Petugas',
  }[t] ?? t
}

function onCanvasChange({ isEmpty: empty }) {
  isEmpty.value = empty
}

function canSubmit() {
  if (!confirmed.value) return false
  if (isEmpty.value) return false
  if (props.askExternalIdentity && !externalIdentity.value.nama?.trim()) return false
  return true
}

function submit() {
  if (!canSubmit()) return
  const captured = canvasRef.value?.capture()
  if (!captured) return

  const payload = { ...captured }
  if (props.askExternalIdentity) {
    payload.external_identity = { ...externalIdentity.value }
  }
  emit('capture', payload)
  emit('update:modelValue', false)
}

function close() {
  emit('update:modelValue', false)
}
</script>

<template>
  <Teleport to="body">
    <div v-if="modelValue" class="scm-overlay" @click.self="close">
      <div class="scm-modal">
        <header class="scm-head">
          <div>
            <h3>{{ computedLabel }}</h3>
            <p v-if="documentName" class="scm-sub">Dokumen: {{ documentName }}</p>
          </div>
          <button class="scm-x" aria-label="Tutup" @click="close">×</button>
        </header>

        <div class="scm-body">
          <div v-if="askExternalIdentity" class="scm-identity">
            <label>
              <span>Nama {{ labelForType(signerType) }} <em>*</em></span>
              <input v-model="externalIdentity.nama" placeholder="Nama lengkap" />
            </label>
            <label>
              <span>NIK (opsional)</span>
              <input v-model="externalIdentity.nik" placeholder="16 digit" maxlength="16" />
            </label>
            <label v-if="signerType === 'witness' || signerType === 'guardian'">
              <span>Hubungan dengan Pasien</span>
              <input v-model="externalIdentity.hubungan" placeholder="contoh: ayah, ibu, anak" />
            </label>
          </div>

          <div class="scm-canvas-wrap">
            <SignatureCanvas
              ref="canvasRef"
              :width="560"
              :height="220"
              :signer-label="computedLabel"
              @change="onCanvasChange"
            />
          </div>

          <label class="scm-confirm">
            <input type="checkbox" v-model="confirmed" />
            <span>{{ confirmReadCopy }}</span>
          </label>
        </div>

        <footer class="scm-foot">
          <button class="scm-btn-ghost" @click="close">Batal</button>
          <button class="scm-btn-primary" :disabled="!canSubmit()" @click="submit">
            Konfirmasi &amp; Tanda Tangan
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.scm-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.6);
  display: flex; align-items: center; justify-content: center;
  z-index: 1100;
}
.scm-modal {
  width: min(680px, 96vw); max-height: 94vh;
  background: white; border-radius: 10px; overflow: hidden;
  display: flex; flex-direction: column;
}

.scm-head {
  display: flex; justify-content: space-between; align-items: flex-start;
  padding: 1rem 1.25rem; border-bottom: 1px solid var(--gb);
}
.scm-head h3 { margin: 0; font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--td); }
.scm-sub { margin: 4px 0 0; font-size: 12.5px; color: var(--tm); }
.scm-x { background: 0; border: 0; font-size: 22px; cursor: pointer; color: var(--tm); line-height: 1; }

.scm-body { padding: 1.25rem 1.5rem; overflow-y: auto; flex: 1; }

.scm-identity { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem; }
.scm-identity label { display: flex; flex-direction: column; }
.scm-identity span { font-size: 12.5px; color: var(--tm); margin-bottom: 4px; }
.scm-identity span em { color: #d4495a; font-style: normal; }
.scm-identity input {
  padding: 0.45rem 0.65rem; border: 1px solid var(--gb); border-radius: 6px;
  font-size: 13.5px;
}

.scm-canvas-wrap { display: flex; justify-content: center; margin: 0.5rem 0; }

.scm-confirm {
  display: flex; gap: 0.5rem; align-items: flex-start;
  background: #fff7ec; border: 1px solid #f0d2a5; border-radius: 6px;
  padding: 0.6rem 0.8rem; font-size: 12.5px; color: #946600;
  cursor: pointer; margin-top: 1rem;
}

.scm-foot {
  padding: 0.85rem 1.25rem; border-top: 1px solid var(--gb);
  display: flex; justify-content: flex-end; gap: 0.5rem;
}
.scm-btn-ghost, .scm-btn-primary {
  padding: 0.55rem 1.25rem; border-radius: 6px;
  cursor: pointer; font-size: 13.5px; font-weight: 700;
}
.scm-btn-ghost {
  background: var(--bi); color: var(--td); border: 1px solid var(--tu);
}
.scm-btn-ghost:hover { background: #e6e9ee; border-color: var(--tm); }
.scm-btn-primary {
  background: var(--ga); color: #fff !important; border: 1px solid var(--ga);
}
.scm-btn-primary:hover:not(:disabled) { background: var(--ld); border-color: var(--ld); }
.scm-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
