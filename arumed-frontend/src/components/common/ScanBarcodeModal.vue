<script setup>
/*
  ScanBarcodeModal — modal scan barcode 2D (DataMatrix GS1/UDI) via kamera.

  Dipakai untuk membaca label UDI alat implan (IOL): GTIN + expiry + lot + serial.
  Memakai @zxing/library (BrowserMultiFormatReader) dengan hint DATA_MATRIX.

  Pola kamera mengikuti PhotoCaptureModal.vue (prefer kamera belakang, cleanup
  stream saat tutup/unmount, error NotAllowedError).

  ⚠️ getUserMedia HANYA jalan di HTTPS atau localhost. Di LAN http://192.168.x
  kamera GAGAL → komponen menyediakan FALLBACK input manual (ketik/tempel kode).

  Emit:
    decoded (rawString) — string mentah hasil scan/ketik; parsing diserahkan ke parent/BE.
    update:open
*/
import { ref, watch, onBeforeUnmount, nextTick } from 'vue'
import { BrowserMultiFormatReader, DecodeHintType, BarcodeFormat } from '@zxing/library'

const props = defineProps({
  open:  { type: Boolean, default: false },
  title: { type: String, default: 'Scan Barcode' },
  hint:  { type: String, default: 'Arahkan kamera ke kode DataMatrix (UDI) pada label.' },
})
const emit = defineEmits(['update:open', 'decoded'])

const videoEl   = ref(null)
const stream    = ref(null)
const cameraOn  = ref(false)
const camError  = ref('')
const starting  = ref(false)
const manual    = ref('')        // input fallback ketik manual
const secure    = ref(true)      // apakah konteks aman (HTTPS/localhost)

let reader = null
let decodedOnce = false   // guard: cegah double-emit bila >1 frame decode sebelum cleanup

// Hint format: prioritaskan DataMatrix, sertakan QR sebagai cadangan.
function makeReader() {
  const hints = new Map()
  hints.set(DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.DATA_MATRIX, BarcodeFormat.QR_CODE])
  hints.set(DecodeHintType.TRY_HARDER, true)
  return new BrowserMultiFormatReader(hints)
}

async function startCamera() {
  camError.value = ''
  // Konteks aman? getUserMedia butuh HTTPS/localhost.
  secure.value = window.isSecureContext === true
  if (!secure.value) {
    camError.value = 'Kamera butuh koneksi aman (HTTPS). Gunakan input manual di bawah, atau akses lewat domain HTTPS.'
    return
  }
  if (!navigator.mediaDevices?.getUserMedia) {
    camError.value = 'Browser tidak mendukung akses kamera. Gunakan input manual.'
    return
  }

  starting.value = true
  decodedOnce = false
  try {
    // Prefer kamera belakang (tablet/HP); webcam desktop pakai default.
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
      startDecodeLoop()
    }
  } catch (e) {
    cameraOn.value = false
    camError.value = e?.name === 'NotAllowedError'
      ? 'Akses kamera ditolak. Izinkan kamera di browser, atau ketik kode manual.'
      : 'Kamera tidak tersedia. Silakan ketik kode manual.'
  } finally {
    starting.value = false
  }
}

// Decode berkelanjutan dari elemen video yang sudah punya stream.
function startDecodeLoop() {
  if (!reader) reader = makeReader()
  try {
    reader.decodeFromStream(stream.value, videoEl.value, (result, err) => {
      if (result) {
        const text = result.getText()
        if (text) onDecoded(text)
      }
      // err NotFoundException tiap frame = normal (belum ketemu) → abaikan.
    })
  } catch {
    // Fallback API lama zxing.
    reader.decodeFromVideoElementContinuously?.(videoEl.value, (result) => {
      if (result?.getText) onDecoded(result.getText())
    })
  }
}

function onDecoded(text) {
  // Guard: callback decode bisa terpicu beberapa frame berturut sebelum reader
  // benar-benar berhenti → tanpa flag ini emit/close bisa jalan ganda.
  if (decodedOnce) return
  decodedOnce = true
  stopCamera()
  emit('decoded', text)
  close()
}

function stopCamera() {
  try { reader?.reset() } catch { /* noop */ }
  if (stream.value) {
    stream.value.getTracks().forEach(t => t.stop())
    stream.value = null
  }
  // Lepas referensi stream dari elemen video agar indikator kamera benar-benar padam.
  if (videoEl.value) {
    try { videoEl.value.srcObject = null } catch { /* noop */ }
  }
  cameraOn.value = false
}

function submitManual() {
  const v = manual.value.trim()
  if (!v) return
  emit('decoded', v)
  close()
}

function close() {
  stopCamera()
  manual.value = ''
  camError.value = ''
  emit('update:open', false)
}

watch(() => props.open, (isOpen) => {
  if (isOpen) {
    manual.value = ''
    startCamera()
  } else {
    stopCamera()
  }
})

onBeforeUnmount(stopCamera)
</script>

<template>
  <Teleport to="body">
    <transition name="sb-fade">
      <div v-if="open" class="sb-backdrop" @click.self="close">
        <div class="sb-shell">
          <div class="sb-head">
            <div>
              <div class="sb-title">{{ title }}</div>
              <div class="sb-sub">{{ hint }}</div>
            </div>
            <button class="sb-x" aria-label="Tutup" @click="close">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>

          <div class="sb-stage">
            <video v-show="cameraOn" ref="videoEl" class="sb-media" autoplay playsinline muted></video>
            <!-- Bingkai bidik -->
            <div v-if="cameraOn" class="sb-reticle"></div>

            <div v-if="!cameraOn" class="sb-placeholder">
              <span v-if="starting" class="sb-spin"></span>
              <template v-else>
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><line x1="14" y1="14" x2="14" y2="21"/><line x1="21" y1="14" x2="21" y2="21"/><line x1="17.5" y1="17.5" x2="17.5" y2="17.5"/></svg>
                <p>{{ camError || 'Menyalakan kamera…' }}</p>
              </template>
            </div>
          </div>

          <!-- Fallback input manual (selalu tersedia) -->
          <div class="sb-manual">
            <label class="sb-manual-label">Atau ketik / tempel kode barcode:</label>
            <div class="sb-manual-row">
              <input
                v-model="manual"
                type="text"
                class="sb-manual-input"
                placeholder="mis. (01)00380652555821(17)290213(10)LOT(21)SN"
                @keyup.enter="submitManual"
              />
              <button class="sb-btn sb-btn-primary" :disabled="!manual.trim()" @click="submitManual">Pakai</button>
            </div>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<style scoped>
.sb-backdrop { position: fixed; inset: 0; z-index: 9998; background: rgba(0,0,0,0.6); backdrop-filter: blur(2px); display: flex; align-items: center; justify-content: center; padding: 20px; }
.sb-shell { width: 100%; max-width: 480px; background: var(--bc, #fff); border-radius: 18px; overflow: hidden; box-shadow: 0 24px 64px rgba(0,0,0,0.3); display: flex; flex-direction: column; }
.sb-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 1.1rem 1.3rem; border-bottom: 1px solid var(--gb); }
.sb-title { font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--gd); }
.sb-sub { font-size: 11.5px; color: var(--tu); margin-top: 3px; max-width: 360px; }
.sb-x { background: none; border: none; cursor: pointer; color: var(--tu); padding: 4px; border-radius: 7px; }
.sb-x:hover { background: var(--bi); }
.sb-x svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

.sb-stage { position: relative; aspect-ratio: 4 / 3; background: #0f1a22; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.sb-media { width: 100%; height: 100%; object-fit: cover; }
.sb-reticle { position: absolute; width: 56%; aspect-ratio: 1; border: 2px solid var(--ga); border-radius: 14px; box-shadow: 0 0 0 9999px rgba(0,0,0,0.28); }
.sb-placeholder { display: flex; flex-direction: column; align-items: center; gap: 10px; color: rgba(255,255,255,0.72); padding: 1.5rem; text-align: center; }
.sb-placeholder svg { width: 42px; height: 42px; fill: none; stroke: currentColor; stroke-width: 1.5; stroke-linecap: round; }
.sb-placeholder p { font-size: 12.5px; max-width: 300px; }

.sb-manual { padding: 0.9rem 1.1rem; border-top: 1px solid var(--gb); background: var(--bi); display: flex; flex-direction: column; gap: 6px; }
.sb-manual-label { font-size: 11.5px; color: var(--tm); font-weight: 500; }
.sb-manual-row { display: flex; gap: 8px; }
.sb-manual-input { flex: 1; height: 38px; padding: 0 12px; border: 1.5px solid var(--gb); border-radius: 10px; font-size: 12.5px; color: var(--td); background: var(--bc); }
.sb-manual-input:focus { outline: none; border-color: var(--ga); }

.sb-btn { display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 16px; border-radius: 10px; font-size: 13px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; transition: all 0.15s; }
.sb-btn-primary { background: var(--ga); color: #fff; }
.sb-btn-primary:hover:not(:disabled) { background: var(--gm); }
.sb-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }

.sb-spin { width: 26px; height: 26px; border: 3px solid rgba(255,255,255,0.25); border-top-color: #fff; border-radius: 50%; animation: sb-rot 0.7s linear infinite; }
@keyframes sb-rot { to { transform: rotate(360deg); } }

.sb-fade-enter-active, .sb-fade-leave-active { transition: opacity 0.18s; }
.sb-fade-enter-from, .sb-fade-leave-to { opacity: 0; }
</style>
