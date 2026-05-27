<script setup>
/*
  Avatar pasien reusable — menampilkan foto pasien bila ada, jika tidak
  jatuh ke inisial nama. Klik foto untuk membuka tampilan penuh (lightbox).
  Dipakai lintas stasiun (Admisi, Perawat, Refraksi, Dokter, Penunjang,
  Kasir, Rekam Medis) menggantikan kotak inisial lama.
*/
import { ref, computed } from 'vue'

const props = defineProps({
  name:     { type: String,  default: '' },
  src:      { type: String,  default: null },   // photo_url dari API
  size:     { type: Number,  default: 44 },      // px
  radius:   { type: String,  default: '12px' },
  zoomable: { type: Boolean, default: true },
})

const broken    = ref(false)   // foto gagal dimuat → fallback inisial
const lightbox  = ref(false)

const initial = computed(() => (props.name || '?').charAt(0).toUpperCase())
const hasPhoto = computed(() => !!props.src && !broken.value)

const boxStyle = computed(() => ({
  width:  `${props.size}px`,
  height: `${props.size}px`,
  borderRadius: props.radius,
  fontSize: `${Math.round(props.size * 0.42)}px`,
}))

function openLightbox() {
  if (hasPhoto.value && props.zoomable) lightbox.value = true
}
function closeLightbox() { lightbox.value = false }

const saving = ref(false)
async function savePhoto() {
  if (!props.src || saving.value) return
  saving.value = true
  const slug = (props.name || 'pasien').trim().replace(/\s+/g, '-').toLowerCase() || 'pasien'
  const filename = `foto-${slug}.jpg`
  try {
    const res = await fetch(props.src, { mode: 'cors' })
    if (!res.ok) throw new Error('fetch gagal')
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch {
    // Fallback (mis. CORS pada file storage): buka di tab baru agar bisa disimpan manual.
    window.open(props.src, '_blank')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div
    class="pa-box"
    :class="{ 'pa-clickable': hasPhoto && zoomable }"
    :style="boxStyle"
    :title="hasPhoto && zoomable ? 'Lihat foto pasien' : null"
    @click="openLightbox"
  >
    <img v-if="hasPhoto" :src="src" :alt="`Foto ${name}`" class="pa-img" @error="broken = true" />
    <span v-else class="pa-initial">{{ initial }}</span>
    <svg v-if="hasPhoto && zoomable" class="pa-zoom" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/>
    </svg>

    <Teleport to="body">
      <transition name="pa-fade">
        <div v-if="lightbox" class="pa-lightbox" @click="closeLightbox">
          <div class="pa-toolbar" @click.stop>
            <button class="pa-tbtn" title="Simpan foto" aria-label="Simpan foto" :disabled="saving" @click="savePhoto">
              <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            </button>
            <button class="pa-tbtn" title="Tutup" aria-label="Tutup foto" @click="closeLightbox">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <figure class="pa-figure" @click.stop>
            <img :src="src" :alt="`Foto ${name}`" />
            <figcaption v-if="name">{{ name }}</figcaption>
          </figure>
        </div>
      </transition>
    </Teleport>
  </div>
</template>

<style scoped>
.pa-box {
  position: relative; flex-shrink: 0; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
  background: var(--gl, #e8f3ec); color: var(--gd, #1d5b3f);
  font-family: 'DM Serif Display', serif; font-weight: 600; line-height: 1;
  user-select: none;
}
.pa-img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pa-initial { pointer-events: none; }
.pa-clickable { cursor: zoom-in; }
.pa-zoom {
  position: absolute; right: 2px; bottom: 2px; width: 14px; height: 14px;
  padding: 2px; border-radius: 50%; background: rgba(0,0,0,0.55); color: #fff;
  fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round;
  opacity: 0; transition: opacity 0.15s;
}
.pa-clickable:hover .pa-zoom { opacity: 1; }

/* Lightbox */
.pa-lightbox {
  position: fixed; inset: 0; z-index: 9999;
  background: rgba(0,0,0,0.82); backdrop-filter: blur(3px);
  display: flex; align-items: center; justify-content: center; padding: 24px;
}
.pa-figure { margin: 0; max-width: min(90vw, 640px); max-height: 88vh; display: flex; flex-direction: column; align-items: center; gap: 12px; }
.pa-figure img { max-width: 100%; max-height: 78vh; object-fit: contain; border-radius: 12px; box-shadow: 0 24px 64px rgba(0,0,0,0.5); }
.pa-figure figcaption { color: #fff; font-size: 14px; font-weight: 500; }
.pa-toolbar { position: absolute; top: 18px; right: 18px; display: flex; gap: 10px; }
.pa-tbtn {
  width: 40px; height: 40px; border: none; border-radius: 50%; cursor: pointer;
  background: rgba(255,255,255,0.14); color: #fff;
  display: flex; align-items: center; justify-content: center; transition: background 0.15s;
}
.pa-tbtn:hover:not(:disabled) { background: rgba(255,255,255,0.28); }
.pa-tbtn:disabled { opacity: 0.5; cursor: default; }
.pa-tbtn svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

.pa-fade-enter-active, .pa-fade-leave-active { transition: opacity 0.18s; }
.pa-fade-enter-from, .pa-fade-leave-to { opacity: 0; }
</style>
