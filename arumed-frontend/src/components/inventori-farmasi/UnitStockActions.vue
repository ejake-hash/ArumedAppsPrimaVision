<script setup>
/**
 * UnitStockActions — entry-point TUNGGAL "Pesan Barang ke Gudang Farmasi".
 *
 * Drop-in 1 komponen per view (Triase/Refraksionis/Ranap/IGD/Admisi/Farmasi/Bedah):
 * satu tombol → menu kecil (Pesan Barang / Retur Barang) yang membuka modal reusable
 * RequestStockModal / ReturStockModal dgn stasiun = prop `station`.
 *
 * Self-contained: hanya tampil bila user punya `request_unit.read`, dan punya toast
 * mini sendiri sehingga tak perlu wiring di view induk. Tetap emit `changed` bila
 * induk ingin ikut refresh.
 */
import { ref, computed, onBeforeUnmount } from 'vue'
import { useAuthStore } from '@/stores/auth'
import RequestStockModal from './RequestStockModal.vue'
import ReturStockModal from './ReturStockModal.vue'

const props = defineProps({
  station:   { type: String, required: true },
  label:     { type: String, default: 'Pesan Barang ke Gudang Farmasi' },
  // Default Obat+BHP. IOL hanya utk unit bedah terjadwal (BedahTerjadwalView pass eksplisit).
  itemTypes: { type: Array,  default: () => ['MEDICATION', 'BHP'] },
  // 'primary' (tombol penuh) | 'soft' (tombol ringan utk header padat)
  variant:   { type: String, default: 'primary' },
  // icon-only utk header sempit (mis. kartu antrean); label tetap di tooltip
  compact:   { type: Boolean, default: false },
})
const emit = defineEmits(['changed'])

const auth = useAuthStore()
const canSee = computed(() => auth.can('request_unit.read'))

const menuOpen   = ref(false)
const requestOpen = ref(false)
const returOpen   = ref(false)
const btnEl    = ref(null)
const menuEl   = ref(null)
const menuStyle = ref({})

// Menu di-Teleport ke body (fixed) supaya tak terpotong container ber-`overflow`
// (mis. `.card { overflow:hidden }` di kartu antrean Perawat/Refraksionis).
function updateMenuPos() {
  const el = btnEl.value
  if (!el) return
  const r = el.getBoundingClientRect()
  menuStyle.value = { top: (r.bottom + 6) + 'px', right: Math.max(8, window.innerWidth - r.right) + 'px' }
}
function openMenu() {
  menuOpen.value = true
  updateMenuPos()
  window.addEventListener('scroll', updateMenuPos, true)
  window.addEventListener('resize', updateMenuPos)
}
function closeMenu() {
  menuOpen.value = false
  window.removeEventListener('scroll', updateMenuPos, true)
  window.removeEventListener('resize', updateMenuPos)
}
function toggleMenu() { menuOpen.value ? closeMenu() : openMenu() }
function openRequest() { closeMenu(); requestOpen.value = true }
function openRetur()   { closeMenu(); returOpen.value = true }

// ─── Toast mini (mandiri) ─────────────────────────────────────────────────
const toastMsg  = ref('')
const toastType = ref('s')
let toastTimer = null
function showToast(type, message) {
  toastType.value = type || 'i'
  toastMsg.value = message
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toastMsg.value = '' }, 3200)
}
function onChanged(payload = {}) {
  if (payload.message) showToast(payload.type, payload.message)
  emit('changed', payload)
}

// ─── Klik di luar menutup menu ────────────────────────────────────────────
function onDocClick(e) {
  if (!menuOpen.value) return
  const inWrap = e.target.closest('.usa-wrap')
  const inMenu = menuEl.value && menuEl.value.contains(e.target)
  if (!inWrap && !inMenu) closeMenu()
}
document.addEventListener('click', onDocClick)
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick)
  window.removeEventListener('scroll', updateMenuPos, true)
  window.removeEventListener('resize', updateMenuPos)
  clearTimeout(toastTimer)
})
</script>

<template>
  <div v-if="canSee" class="usa-wrap">
    <button ref="btnEl" :class="['usa-btn', variant, { compact }]" @click.stop="toggleMenu" :title="label">
      <svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
      <span v-if="!compact" class="usa-btn-txt">{{ label }}</span>
      <svg v-if="!compact" class="usa-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
    </button>

    <Teleport to="body">
      <div v-if="menuOpen" ref="menuEl" class="usa-menu" :style="menuStyle" @click.stop>
        <button class="usa-menu-item" @click="openRequest">
          <svg viewBox="0 0 24 24"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
          Pesan Barang
        </button>
        <button class="usa-menu-item" @click="openRetur">
          <svg viewBox="0 0 24 24"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>
          Retur Barang
        </button>
      </div>
    </Teleport>

    <RequestStockModal :open="requestOpen" :station="station" :item-types="itemTypes" @close="requestOpen = false" @changed="onChanged" />
    <ReturStockModal   :open="returOpen"   :station="station" :item-types="itemTypes" @close="returOpen = false"   @changed="onChanged" />

    <Teleport to="body">
      <div v-if="toastMsg" class="usa-toast" :class="`t-${toastType}`">{{ toastMsg }}</div>
    </Teleport>
  </div>
</template>

<style scoped>
.usa-wrap { position: relative; display: inline-block; }

.usa-btn { display: inline-flex; align-items: center; gap: 7px; padding: 7px 12px; font-size: 12.5px; font-weight: 600; border-radius: 8px; cursor: pointer; font-family: 'Inter', sans-serif; border: 1.5px solid var(--gd); background: var(--gd); color: #fff; transition: background .15s, border-color .15s, color .15s; }
.usa-btn:hover { background: var(--gm); border-color: var(--gm); }
.usa-btn svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
.usa-caret { width: 13px !important; height: 13px !important; opacity: .85; }
/* Varian ringan untuk header yang padat */
.usa-btn.soft { background: var(--bc); color: var(--gd); border-color: var(--gb); }
.usa-btn.soft:hover { border-color: var(--ga); color: var(--ga); background: var(--bs); }
/* Icon-only utk header sempit */
.usa-btn.compact { padding: 7px; background: var(--bc); color: var(--gd); border-color: var(--gb); }
.usa-btn.compact:hover { border-color: var(--ga); color: var(--ga); background: var(--bs); }

.usa-toast { position: fixed; bottom: 22px; right: 22px; z-index: 2000; padding: 11px 16px; border-radius: 9px; font-size: 13px; font-weight: 500; color: #fff; box-shadow: 0 8px 24px rgba(0,0,0,.22); max-width: 340px; }
.t-s { background: #16a34a; }
.t-w { background: #dc2626; }
.t-i { background: #334155; }
</style>

<!-- Menu di-teleport ke body → style un-scoped (prefiks .usa-menu). -->
<style>
.usa-menu { position: fixed; z-index: 3000; min-width: 190px; background: var(--bc); border: 1px solid var(--gb); border-radius: 10px; box-shadow: 0 10px 28px rgba(15,23,42,.16); padding: 5px; display: flex; flex-direction: column; gap: 2px; }
.usa-menu .usa-menu-item { display: flex; align-items: center; gap: 9px; width: 100%; padding: 8px 10px; font-size: 12.5px; font-weight: 500; color: var(--td); background: none; border: none; border-radius: 7px; cursor: pointer; text-align: left; font-family: 'Inter', sans-serif; }
.usa-menu .usa-menu-item:hover { background: var(--bs); color: var(--ga); }
.usa-menu .usa-menu-item svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; color: var(--tm); }
.usa-menu .usa-menu-item:hover svg { color: var(--ga); }
</style>
