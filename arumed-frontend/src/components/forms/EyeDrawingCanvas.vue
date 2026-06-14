<script setup>
/**
 * EyeDrawingCanvas — satu kanvas sketsa/anotasi mata (OD atau OS).
 *
 * Pola di-clone dari SignatureCanvas.vue (signature_pad): kanvas TRANSPARAN di
 * atas diagram latar (Fundus / Segmen Anterior / Kosong). Dokter menggambar di
 * atas diagram; goresan disimpan sebagai VEKTOR (`strokes`, re-editable saat
 * reopen) + raster komposit (`png_base64`, diagram + goresan) untuk display/cetak.
 *
 * Props:
 *   - modelValue : { strokes:<signature_pad toData JSON>, png_base64:String, template:String } | null
 *   - disabled   : kunci input (read-only / pasca finalisasi)
 *   - eyeLabel   : 'OD' | 'OS'
 *   - template   : 'fundus' | 'anterior' | 'blank' (latar default; bisa diganti via picker)
 *
 * Expose:
 *   - clear() / undo() / isEmpty() : kontrol kanvas
 *   - capture() : { strokes, png_base64, template } | null (null bila kosong)
 *
 * Emit:
 *   - 'change' : { isEmpty }
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import SignaturePad from 'signature_pad'

const props = defineProps({
  modelValue: { type: Object, default: null },
  disabled:   { type: Boolean, default: false },
  eyeLabel:   { type: String, default: '' },
  template:   { type: String, default: 'blank' },
})
const emit = defineEmits(['change'])

// ---------------------------------------------------------------------------
// Diagram latar (SVG buatan sendiri — bebas lisensi, garis pucat agar goresan
// dokter berwarna menonjol di atasnya). Dirender via <img> data-URI.
// ---------------------------------------------------------------------------
const SVG_FUNDUS = `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 360 300'>
  <rect width='360' height='300' fill='#fff'/>
  <ellipse cx='180' cy='150' rx='150' ry='135' fill='#fdf1e9' stroke='#d8b39a' stroke-width='2'/>
  <ellipse cx='236' cy='150' rx='26' ry='31' fill='#ffe2c4' stroke='#c8895a' stroke-width='1.5'/>
  <ellipse cx='236' cy='150' rx='12' ry='15' fill='#fff1e2' stroke='#c8895a' stroke-width='1'/>
  <circle cx='118' cy='150' r='18' fill='none' stroke='#b06a4a' stroke-width='1' stroke-dasharray='3 3'/>
  <circle cx='118' cy='150' r='2.5' fill='#9c4f30'/>
  <path d='M236 132 Q180 78 86 68' fill='none' stroke='#dc8585' stroke-width='2'/>
  <path d='M236 168 Q180 222 86 232' fill='none' stroke='#dc8585' stroke-width='2'/>
  <path d='M236 136 Q200 108 150 92' fill='none' stroke='#dc8585' stroke-width='1.4'/>
  <path d='M236 164 Q200 192 150 208' fill='none' stroke='#dc8585' stroke-width='1.4'/>
</svg>`

const SVG_ANTERIOR = `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 360 300'>
  <rect width='360' height='300' fill='#fff'/>
  <circle cx='180' cy='150' r='132' fill='#f4f9fb' stroke='#9bb7c4' stroke-width='2'/>
  <circle cx='180' cy='150' r='92' fill='#e9f1f6' stroke='#7fa0b0' stroke-width='1.5'/>
  <circle cx='180' cy='150' r='38' fill='#cfdbe2' stroke='#5d7a89' stroke-width='1.5'/>
  <g stroke='#bccdd6' stroke-width='1'>
    <line x1='180' y1='62' x2='180' y2='112'/><line x1='180' y1='188' x2='180' y2='238'/>
    <line x1='92' y1='150' x2='142' y2='150'/><line x1='218' y1='150' x2='268' y2='150'/>
    <line x1='118' y1='88' x2='153' y2='123'/><line x1='207' y1='177' x2='242' y2='212'/>
    <line x1='242' y1='88' x2='207' y2='123'/><line x1='153' y1='177' x2='118' y2='212'/>
  </g>
</svg>`

const SVG_BLANK = `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 360 300'><rect width='360' height='300' fill='#fff'/></svg>`

const TEMPLATES = { fundus: SVG_FUNDUS, anterior: SVG_ANTERIOR, blank: SVG_BLANK }

function bgUri(tpl) {
  const svg = TEMPLATES[tpl] ?? SVG_BLANK
  return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`
}

const WIDTH = 360
const HEIGHT = 300

const canvasRef = ref(null)
const bgImgRef = ref(null)
const curTemplate = ref(props.template || 'blank')
const bgSrc = ref(bgUri(curTemplate.value))
let pad = null

const PEN_COLORS = ['#1d4ed8', '#dc2626', '#15803d', '#111827']
const penColor = ref(PEN_COLORS[0])

onMounted(() => {
  if (!canvasRef.value) return
  resizeCanvas()
  pad = new SignaturePad(canvasRef.value, {
    minWidth: 1,
    maxWidth: 2.6,
    penColor: penColor.value,
    backgroundColor: 'rgba(255,255,255,0)',   // transparan → diagram terlihat
  })
  pad.addEventListener('endStroke', () => emit('change', { isEmpty: pad.isEmpty() }))

  // Hidrasi goresan tersimpan (re-editable saat reopen).
  if (props.modelValue?.strokes) {
    try { pad.fromData(props.modelValue.strokes) } catch { /* abaikan data rusak */ }
  }
  if (props.modelValue?.template) {
    curTemplate.value = props.modelValue.template
    bgSrc.value = bgUri(curTemplate.value)
  }
  if (props.disabled) pad.off()

  window.addEventListener('resize', resizeCanvas)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', resizeCanvas)
  pad?.off()
})

watch(penColor, (c) => { if (pad) pad.penColor = c })

function resizeCanvas() {
  if (!canvasRef.value) return
  const ratio = Math.min(Math.max(window.devicePixelRatio || 1, 1), 2)  // cap 2× → payload PNG kecil
  const cssW = canvasRef.value.offsetWidth || WIDTH
  const cssH = HEIGHT
  const data = pad?.toData()
  canvasRef.value.width  = cssW * ratio
  canvasRef.value.height = cssH * ratio
  canvasRef.value.getContext('2d').scale(ratio, ratio)
  if (data) pad?.fromData(data)
}

function setTemplate(tpl) {
  curTemplate.value = tpl
  bgSrc.value = bgUri(tpl)
}

function clear() {
  pad?.clear()
  emit('change', { isEmpty: true })
}

function undo() {
  if (!pad) return
  const d = pad.toData()
  d.pop()
  pad.fromData(d)
  emit('change', { isEmpty: pad.isEmpty() })
}

function isEmpty() {
  return !pad || pad.isEmpty()
}

/**
 * Komposit diagram latar + goresan → PNG (untuk display/cetak). `strokes` vektor
 * disimpan terpisah agar re-editable. Return null bila tak ada goresan.
 */
function capture() {
  if (!pad || pad.isEmpty()) return null
  const w = canvasRef.value.width
  const h = canvasRef.value.height
  const out = document.createElement('canvas')
  out.width = w
  out.height = h
  const ctx = out.getContext('2d')
  ctx.fillStyle = '#fff'
  ctx.fillRect(0, 0, w, h)

  // Reuse <img> diagram yang SUDAH tampil (pasti ter-decode) → komposit andal.
  try {
    const bg = bgImgRef.value
    if (bg && bg.complete && bg.naturalWidth) ctx.drawImage(bg, 0, 0, w, h)
  } catch { /* abaikan bila belum siap */ }
  ctx.drawImage(canvasRef.value, 0, 0)

  return {
    strokes: pad.toData(),
    png_base64: out.toDataURL('image/png'),
    template: curTemplate.value,
  }
}

defineExpose({ clear, undo, isEmpty, capture, setTemplate })
</script>

<template>
  <div class="edc" :class="{ disabled }">
    <div class="edc-head">
      <span class="edc-eye">{{ eyeLabel }}</span>
      <select v-if="!disabled" class="edc-tpl" :value="curTemplate" @change="setTemplate($event.target.value)">
        <option value="fundus">Fundus</option>
        <option value="anterior">Segmen Anterior</option>
        <option value="blank">Kosong</option>
      </select>
    </div>

    <div class="edc-frame" :style="{ aspectRatio: WIDTH + ' / ' + HEIGHT }">
      <img ref="bgImgRef" class="edc-bg" :src="bgSrc" alt="" draggable="false" />
      <canvas ref="canvasRef" class="edc-canvas" :style="{ width: '100%', height: '100%' }" />
    </div>

    <div v-if="!disabled" class="edc-tools">
      <div class="edc-pens">
        <button
          v-for="c in PEN_COLORS" :key="c" type="button"
          class="edc-pen" :class="{ on: penColor === c }"
          :style="{ background: c }" :aria-label="'Warna ' + c"
          @click="penColor = c"
        />
      </div>
      <div class="edc-acts">
        <button type="button" class="edc-btn" @click="undo">↶ Undo</button>
        <button type="button" class="edc-btn" @click="clear">Hapus</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.edc { display: flex; flex-direction: column; gap: 0.4rem; }
.edc.disabled { opacity: 0.92; }

.edc-head { display: flex; justify-content: space-between; align-items: center; }
.edc-eye {
  font-weight: 800; font-size: 13px; color: var(--td);
  background: var(--bi); border: 1px solid var(--gb); border-radius: 5px;
  padding: 1px 8px;
}
.edc-tpl {
  font-size: 12px; padding: 2px 6px; border: 1px solid var(--gb);
  border-radius: 5px; background: #fff; color: var(--td);
}

.edc-frame {
  position: relative; width: 100%;
  border: 1px solid var(--gb); border-radius: 8px; overflow: hidden; background: #fff;
}
.edc-bg { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; user-select: none; }
.edc-canvas { position: relative; display: block; cursor: crosshair; touch-action: none; }

.edc-tools { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
.edc-pens { display: flex; gap: 5px; }
.edc-pen {
  width: 20px; height: 20px; border-radius: 50%; border: 2px solid #fff;
  box-shadow: 0 0 0 1px var(--gb); cursor: pointer; padding: 0;
}
.edc-pen.on { box-shadow: 0 0 0 2px var(--ga); }
.edc-acts { display: flex; gap: 0.4rem; }
.edc-btn {
  padding: 0.25rem 0.6rem; border: 1px solid var(--gb); border-radius: 5px;
  background: #fff; cursor: pointer; font-size: 12px; color: var(--tm);
}
.edc-btn:hover { background: var(--bg); }
</style>
