<script setup>
/*
  Modal ambil foto pasien — kamera (webcam / kamera tablet-HP via getUserMedia)
  dengan fallback unggah file. Foto dikecilkan ke maks 640px sisi terpanjang
  (JPEG 0.85) agar payload ringan, lalu di-emit sebagai data URL base64.
*/
import { ref, watch, onBeforeUnmount, nextTick } from 'vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  patientName: { type: String, default: '' },
})
const emit = defineEmits(['update:open', 'captured'])

const MAX_DIM = 640
const QUALITY = 0.85

const videoEl   = ref(null)
const stream    = ref(null)
const cameraOn  = ref(false)
const camError  = ref('')
const preview   = ref(null)   // data URL hasil capture/upload
const starting  = ref(false)

async function startCamera() {
  camError.value = ''
  starting.value = true
  try {
    // Prefer kamera belakang (tablet/HP); webcam desktop akan pakai default.
    let s
    try {
      s = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
    } catch {
      s = await navigator.mediaDevices.getUserMedia({ video: true, audio: false })
    }
    stream.value = s
    cameraOn.value = true
    await nextTick()
    if (videoEl.value) {
      videoEl.value.srcObject = s
      await videoEl.value.play().catch(() => {})
    }
  } catch (e) {
    cameraOn.value = false
    camError.value = e?.name === 'NotAllowedError'
      ? 'Akses kamera ditolak. Izinkan kamera di browser, atau unggah file.'
      : 'Kamera tidak tersedia. Silakan unggah file foto.'
  } finally {
    starting.value = false
  }
}

function stopCamera() {
  if (stream.value) {
    stream.value.getTracks().forEach(t => t.stop())
    stream.value = null
  }
  cameraOn.value = false
}

/* Gambar source (video / image) ke canvas dengan downscale, balikkan data URL. */
function toScaledDataUrl(source, w, h) {
  const scale = Math.min(1, MAX_DIM / Math.max(w, h))
  const cw = Math.round(w * scale)
  const ch = Math.round(h * scale)
  const canvas = document.createElement('canvas')
  canvas.width = cw
  canvas.height = ch
  canvas.getContext('2d').drawImage(source, 0, 0, cw, ch)
  return canvas.toDataURL('image/jpeg', QUALITY)
}

function capture() {
  const v = videoEl.value
  if (!v || !v.videoWidth) return
  preview.value = toScaledDataUrl(v, v.videoWidth, v.videoHeight)
  stopCamera()
}

function onFile(e) {
  const file = e.target.files?.[0]
  if (!file) return
  if (!file.type.startsWith('image/')) { camError.value = 'File harus berupa gambar.'; return }
  const reader = new FileReader()
  reader.onload = () => {
    const img = new Image()
    img.onload = () => { preview.value = toScaledDataUrl(img, img.naturalWidth, img.naturalHeight) }
    img.src = reader.result
  }
  reader.readAsDataURL(file)
  e.target.value = ''   // izinkan pilih file sama lagi
}

function retake() {
  preview.value = null
  startCamera()
}

function confirm() {
  if (!preview.value) return
  emit('captured', preview.value)
  close()
}

function close() {
  stopCamera()
  preview.value = null
  camError.value = ''
  emit('update:open', false)
}

watch(() => props.open, (isOpen) => {
  if (isOpen) {
    preview.value = null
    startCamera()
  } else {
    stopCamera()
  }
})

onBeforeUnmount(stopCamera)
</script>

<template>
  <Teleport to="body">
    <transition name="pc-fade">
      <div v-if="open" class="pc-backdrop" @click.self="close">
        <div class="pc-shell">
          <div class="pc-head">
            <div>
              <div class="pc-title">Ambil Foto Pasien</div>
              <div class="pc-sub">{{ patientName || 'Foto akan tersimpan bersama data pasien' }}</div>
            </div>
            <button class="pc-x" aria-label="Tutup" @click="close">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>

          <div class="pc-stage">
            <!-- Preview hasil -->
            <img v-if="preview" :src="preview" alt="Pratinjau foto" class="pc-media" />

            <!-- Live kamera -->
            <video v-show="!preview && cameraOn" ref="videoEl" class="pc-media" autoplay playsinline muted></video>

            <!-- Placeholder / error -->
            <div v-if="!preview && !cameraOn" class="pc-placeholder">
              <span v-if="starting" class="pc-spin"></span>
              <template v-else>
                <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                <p>{{ camError || 'Menyalakan kamera…' }}</p>
              </template>
            </div>
          </div>

          <div class="pc-foot">
            <label class="pc-btn pc-btn-ghost">
              <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              Unggah File
              <input type="file" accept="image/*" capture="environment" hidden @change="onFile" />
            </label>

            <span class="pc-spacer"></span>

            <template v-if="preview">
              <button class="pc-btn pc-btn-ghost" @click="retake">Ulangi</button>
              <button class="pc-btn pc-btn-primary" @click="confirm">
                <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                Gunakan Foto
              </button>
            </template>
            <template v-else>
              <button class="pc-btn pc-btn-primary" :disabled="!cameraOn" @click="capture">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/></svg>
                Jepret
              </button>
            </template>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<style scoped>
.pc-backdrop { position: fixed; inset: 0; z-index: 9998; background: rgba(0,0,0,0.6); backdrop-filter: blur(2px); display: flex; align-items: center; justify-content: center; padding: 20px; }
.pc-shell { width: 100%; max-width: 460px; background: var(--bc, #fff); border-radius: 18px; overflow: hidden; box-shadow: 0 24px 64px rgba(0,0,0,0.3); display: flex; flex-direction: column; }
.pc-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 1.1rem 1.3rem; border-bottom: 1px solid var(--gb, #e3e8e5); }
.pc-title { font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--gd, #1d5b3f); }
.pc-sub { font-size: 11.5px; color: var(--tu, #8a948f); margin-top: 3px; }
.pc-x { background: none; border: none; cursor: pointer; color: var(--tu, #8a948f); padding: 4px; border-radius: 7px; }
.pc-x:hover { background: var(--bi, #f2f5f3); }
.pc-x svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

.pc-stage { aspect-ratio: 4 / 3; background: #11201a; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.pc-media { width: 100%; height: 100%; object-fit: cover; }
.pc-placeholder { display: flex; flex-direction: column; align-items: center; gap: 10px; color: rgba(255,255,255,0.7); padding: 1.5rem; text-align: center; }
.pc-placeholder svg { width: 42px; height: 42px; fill: none; stroke: currentColor; stroke-width: 1.5; stroke-linecap: round; }
.pc-placeholder p { font-size: 12.5px; max-width: 280px; }

.pc-foot { display: flex; align-items: center; gap: 0.5rem; padding: 0.9rem 1.1rem; border-top: 1px solid var(--gb, #e3e8e5); background: var(--bi, #f7f9f8); }
.pc-spacer { flex: 1; }
.pc-btn { display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 16px; border-radius: 10px; font-size: 13px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; transition: all 0.15s; }
.pc-btn svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.pc-btn-primary { background: var(--ga, #2e8b62); color: #fff; }
.pc-btn-primary:hover:not(:disabled) { background: var(--gm, #256e4e); }
.pc-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.pc-btn-ghost { background: transparent; color: var(--tm, #4b554f); border-color: var(--gb, #e3e8e5); }
.pc-btn-ghost:hover { border-color: var(--ga, #2e8b62); color: var(--gd, #1d5b3f); }

.pc-spin { width: 26px; height: 26px; border: 3px solid rgba(255,255,255,0.25); border-top-color: #fff; border-radius: 50%; animation: pc-rot 0.7s linear infinite; }
@keyframes pc-rot { to { transform: rotate(360deg); } }

.pc-fade-enter-active, .pc-fade-leave-active { transition: opacity 0.18s; }
.pc-fade-enter-from, .pc-fade-leave-to { opacity: 0; }
</style>
