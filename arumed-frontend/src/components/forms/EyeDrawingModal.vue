<script setup>
/**
 * EyeDrawingModal — kontainer modal lebar untuk menggambar sketsa mata OD & OS
 * berdampingan. Dua <EyeDrawingCanvas> + tombol Simpan/Batal.
 *
 * State kerja lokal di kanvas (signature_pad); baru COMMIT ke parent saat Simpan
 * (Batal = buang perubahan). Modal pakai v-if → kanvas remount tiap buka sehingga
 * selalu rehidrasi dari `modelValue` terkini.
 *
 * Props:
 *   - modelValue : { od:{strokes,png_base64,template}|null, os:{...}|null }
 *   - open       : boolean (tampil/tutup)
 *   - disabled   : read-only (mis. "kunjungan lalu" / pasca finalisasi)
 *   - segment    : 'anterior' | 'posterior' → template latar default
 *   - title      : judul header opsional
 *
 * Emit:
 *   - 'update:modelValue' : { od, os } saat Simpan
 *   - 'update:open'       : boolean
 */
import { computed, ref } from 'vue'
import EyeDrawingCanvas from './EyeDrawingCanvas.vue'

const props = defineProps({
  modelValue: { type: Object, default: () => ({ od: null, os: null }) },
  open:       { type: Boolean, default: false },
  disabled:   { type: Boolean, default: false },
  segment:    { type: String, default: 'anterior' },
  title:      { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'update:open'])

const odRef = ref(null)
const osRef = ref(null)

const defaultTpl = computed(() => (props.segment === 'posterior' ? 'fundus' : 'anterior'))
const heading = computed(() => props.title || `Sketsa Mata — Segmen ${props.segment === 'posterior' ? 'Posterior' : 'Anterior'}`)

function close() {
  emit('update:open', false)
}

function save() {
  if (props.disabled) { close(); return }
  const od = odRef.value?.capture() ?? null
  const os = osRef.value?.capture() ?? null
  // Normalisasi: bila KEDUA mata kosong → simpan null (bukan {od:null,os:null}).
  emit('update:modelValue', (od || os) ? { od, os } : null)
  close()
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="edm-overlay" @click.self="close">
      <div class="edm-modal">
        <header class="edm-head">
          <h3>{{ heading }}</h3>
          <button class="edm-x" aria-label="Tutup" @click="close">×</button>
        </header>

        <div class="edm-body">
          <p v-if="disabled" class="edm-ro">Mode lihat — kunjungan lalu (hanya lihat, tidak menimpa sketsa aktif).</p>
          <div class="edm-grid">
            <EyeDrawingCanvas
              ref="odRef" eye-label="OD (Kanan)"
              :model-value="modelValue?.od ?? null" :disabled="disabled" :template="defaultTpl"
            />
            <EyeDrawingCanvas
              ref="osRef" eye-label="OS (Kiri)"
              :model-value="modelValue?.os ?? null" :disabled="disabled" :template="defaultTpl"
            />
          </div>
        </div>

        <footer class="edm-foot">
          <button class="edm-btn-ghost" @click="close">{{ disabled ? 'Tutup' : 'Batal' }}</button>
          <button v-if="!disabled" class="edm-btn-primary" @click="save">Simpan Sketsa</button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.edm-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.6);
  display: flex; align-items: center; justify-content: center; z-index: 1100;
}
.edm-modal {
  width: min(900px, 96vw); max-height: 94vh;
  background: #fff; border-radius: 10px; overflow: hidden;
  display: flex; flex-direction: column;
}
.edm-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 0.9rem 1.25rem; border-bottom: 1px solid var(--gb);
}
.edm-head h3 { margin: 0; font-family: 'Space Grotesk', serif; font-size: 17px; color: var(--td); }
.edm-x { background: 0; border: 0; font-size: 22px; cursor: pointer; color: var(--tm); line-height: 1; }

.edm-body { padding: 1.1rem 1.25rem; overflow-y: auto; flex: 1; }
.edm-ro {
  margin: 0 0 0.75rem; font-size: 12.5px; color: #946600;
  background: #fff7ec; border: 1px solid #f0d2a5; border-radius: 6px; padding: 0.5rem 0.75rem;
}
.edm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; }

.edm-foot {
  padding: 0.8rem 1.25rem; border-top: 1px solid var(--gb);
  display: flex; justify-content: flex-end; gap: 0.5rem;
}
.edm-btn-ghost, .edm-btn-primary {
  padding: 0.55rem 1.25rem; border-radius: 6px; cursor: pointer; font-size: 13.5px; font-weight: 700;
}
.edm-btn-ghost { background: var(--bi); color: var(--td); border: 1px solid var(--tu); }
.edm-btn-ghost:hover { background: #e6e9ee; border-color: var(--tm); }
.edm-btn-primary { background: var(--ga); color: #fff !important; border: 1px solid var(--ga); }
.edm-btn-primary:hover { background: var(--ld); border-color: var(--ld); }

@media (max-width: 720px) {
  .edm-grid { grid-template-columns: 1fr; gap: 0.85rem; }
  .edm-modal { width: 96vw; }
}
</style>
