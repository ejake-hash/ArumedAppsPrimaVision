<script setup>
/**
 * SignatureCanvas — wrap signature_pad untuk capture TTD digital.
 *
 * Props:
 *   - width / height : ukuran canvas (default 400 × 180)
 *   - disabled       : disable input
 *   - signerLabel    : label di atas canvas ("Tanda Tangan Pasien" dst)
 *
 * Expose (defineExpose):
 *   - clear()           : kosongkan canvas + reset biometric
 *   - isEmpty()         : boolean
 *   - capture()         : { svg, png_base64, biometric_metadata, audit_log }
 *
 * Emit:
 *   - 'change' : { isEmpty: boolean }
 *
 * Biometric metadata: stroke_count, total_duration_ms, total_points,
 * average_speed_px_per_ms. Audit log: timeline event ['canvas_mounted',
 * 'first_stroke_at', 'last_stroke_at']. captured_at SERVER-side (bukan
 * dari sini — di backend pakai now()).
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import SignaturePad from 'signature_pad'

const props = defineProps({
  width:       { type: Number, default: 400 },
  height:      { type: Number, default: 180 },
  disabled:    { type: Boolean, default: false },
  signerLabel: { type: String, default: '' },
})
const emit = defineEmits(['change'])

const canvasRef = ref(null)
let pad = null

// Biometric tracking
let mountedAt = 0
let firstStrokeAt = 0
let lastStrokeAt = 0
let strokeCount = 0
let totalPoints = 0

const auditEvents = []
function logEvent(name) {
  auditEvents.push({ event: name, at: Date.now() })
}

onMounted(() => {
  if (!canvasRef.value) return

  // Setup high-DPI canvas
  resizeCanvas()

  pad = new SignaturePad(canvasRef.value, {
    minWidth: 0.8,
    maxWidth: 2.5,
    penColor: '#000',
    backgroundColor: 'rgba(255,255,255,0)',
  })

  pad.addEventListener('beginStroke', () => {
    strokeCount += 1
    if (!firstStrokeAt) {
      firstStrokeAt = Date.now()
      logEvent('first_stroke')
    }
  })
  pad.addEventListener('endStroke', () => {
    lastStrokeAt = Date.now()
    const pts = pad.toData()
    totalPoints = pts.reduce((sum, stroke) => sum + (stroke.points?.length ?? 0), 0)
    emit('change', { isEmpty: pad.isEmpty() })
  })

  mountedAt = Date.now()
  logEvent('canvas_mounted')

  if (props.disabled) pad.off()

  window.addEventListener('resize', resizeCanvas)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', resizeCanvas)
  pad?.off()
})

function resizeCanvas() {
  if (!canvasRef.value) return
  const ratio = Math.max(window.devicePixelRatio || 1, 1)
  const data = pad?.toData()
  canvasRef.value.width  = props.width  * ratio
  canvasRef.value.height = props.height * ratio
  canvasRef.value.getContext('2d').scale(ratio, ratio)
  if (data) pad?.fromData(data)
}

function clear() {
  pad?.clear()
  firstStrokeAt = 0
  lastStrokeAt = 0
  strokeCount = 0
  totalPoints = 0
  auditEvents.length = 0
  logEvent('canvas_cleared')
  emit('change', { isEmpty: true })
}

function isEmpty() {
  return !pad || pad.isEmpty()
}

function capture() {
  if (!pad || pad.isEmpty()) return null

  const svg = pad.toSVG()
  const png = pad.toDataURL('image/png')   // "data:image/png;base64,..."
  const png_base64 = png.includes(',') ? png.substring(png.indexOf(',') + 1) : png

  const totalDuration = lastStrokeAt - firstStrokeAt
  const biometric = {
    stroke_count: strokeCount,
    total_points: totalPoints,
    total_duration_ms: Math.max(0, totalDuration),
    avg_speed_px_per_ms: totalDuration > 0 && totalPoints > 0
      ? Math.round(((totalPoints * props.width) / totalDuration) * 100) / 100
      : null,
  }

  logEvent('signature_captured')

  return {
    signature_svg: svg,
    signature_png_base64: png_base64,
    biometric_metadata: biometric,
    audit_log: auditEvents.slice(),
  }
}

defineExpose({ clear, isEmpty, capture })
</script>

<template>
  <div class="sc-wrap" :class="{ disabled }">
    <div v-if="signerLabel" class="sc-label">{{ signerLabel }}</div>
    <div class="sc-canvas-frame">
      <canvas
        ref="canvasRef"
        :style="{ width: width + 'px', height: height + 'px' }"
        class="sc-canvas"
      />
      <div class="sc-baseline"></div>
    </div>
    <div class="sc-actions">
      <button type="button" class="sc-btn" :disabled="disabled" @click="clear">Hapus</button>
      <span class="sc-hint">Tulis tanda tangan menggunakan jari atau stylus.</span>
    </div>
  </div>
</template>

<style scoped>
.sc-wrap { display: inline-flex; flex-direction: column; gap: 0.4rem; }
.sc-wrap.disabled { opacity: 0.5; pointer-events: none; }

.sc-label { font-size: 12.5px; font-weight: 600; color: var(--td); }

.sc-canvas-frame {
  position: relative;
  border: 1px solid var(--gb); border-radius: 6px;
  background: white;
  display: inline-block;
}
.sc-canvas {
  display: block;
  cursor: crosshair;
  touch-action: none;
}
.sc-baseline {
  position: absolute; left: 12px; right: 12px; bottom: 28px;
  border-bottom: 1px dashed #aaa;
  pointer-events: none;
}

.sc-actions { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
.sc-btn {
  padding: 0.3rem 0.75rem; border: 1px solid var(--gb); border-radius: 4px;
  background: white; cursor: pointer; font-size: 12px; color: var(--tm);
}
.sc-btn:hover { background: var(--bg); }
.sc-hint { font-size: 11px; color: var(--tm); }
</style>
