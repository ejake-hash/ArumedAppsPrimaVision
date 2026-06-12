<script setup>
/**
 * ItemSearchSelect — combobox barang (Obat/BHP/IOL) yang BISA DIKETIK untuk mencari.
 *
 * Filter client-side atas `items` yang sudah dimuat + emit `search` (debounce) supaya
 * modal bisa memuat ulang dari server (lewat 500 master). Menyimpan item terpilih
 * sendiri agar label tetap tampil walau daftar menyempit pasca-search.
 *
 * Popup di-Teleport ke <body> dgn posisi FIXED (dihitung dari rect input) supaya TIDAK
 * terpotong oleh container ber-`overflow` (mis. body modal yang scroll).
 */
import { ref, computed, watch, onBeforeUnmount } from 'vue'

const props = defineProps({
  modelValue:  { type: [String, Number], default: '' },
  items:       { type: Array,  default: () => [] },   // [{id, code, name, unit, qty}]
  placeholder: { type: String, default: 'cari / pilih barang…' },
  disabled:    { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'select', 'search'])

const open    = ref(false)
const query   = ref('')
const rootEl  = ref(null)
const controlEl = ref(null)
const popEl   = ref(null)
const selected = ref(null)   // item object terpilih (tahan walau lepas dari `items`)
const popStyle = ref({})

let searchDebounce = null

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  const list = props.items || []
  if (!q) return list.slice(0, 80)
  return list.filter((it) =>
    (it.name ?? '').toLowerCase().includes(q) || (it.code ?? '').toLowerCase().includes(q),
  ).slice(0, 80)
})

const displayText = computed(() => selected.value?.name ?? '')

function updatePos() {
  const el = controlEl.value
  if (!el) return
  const r = el.getBoundingClientRect()
  // Flip ke atas bila ruang bawah sempit.
  const below = window.innerHeight - r.bottom
  const flip = below < 250 && r.top > below
  popStyle.value = flip
    ? { position: 'fixed', bottom: (window.innerHeight - r.top + 3) + 'px', left: r.left + 'px', width: r.width + 'px' }
    : { position: 'fixed', top: (r.bottom + 3) + 'px', left: r.left + 'px', width: r.width + 'px' }
}

function openMenu() {
  if (props.disabled) return
  open.value = true
  query.value = ''
  updatePos()
  window.addEventListener('scroll', updatePos, true)
  window.addEventListener('resize', updatePos)
}
function closeMenu() {
  open.value = false
  query.value = ''
  window.removeEventListener('scroll', updatePos, true)
  window.removeEventListener('resize', updatePos)
}
function onInput(e) {
  query.value = e.target.value
  if (!open.value) openMenu()
  else updatePos()
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => emit('search', query.value.trim()), 300)
}
function pick(it) {
  selected.value = it
  emit('update:modelValue', it.id)
  emit('select', it)
  closeMenu()
}
function clearSel() {
  selected.value = null
  emit('update:modelValue', '')
  emit('select', null)
  closeMenu()
}

// Sinkron bila parent mereset modelValue (mis. ganti jenis → '') atau set dari luar.
watch(() => props.modelValue, (v) => {
  if (!v) { selected.value = null; return }
  if (selected.value?.id === v) return
  const found = (props.items || []).find((it) => it.id === v)
  if (found) selected.value = found
})

function onDocClick(e) {
  if (!open.value) return
  const inRoot = rootEl.value && rootEl.value.contains(e.target)
  const inPop  = popEl.value && popEl.value.contains(e.target)
  if (!inRoot && !inPop) closeMenu()
}
document.addEventListener('click', onDocClick)
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick)
  window.removeEventListener('scroll', updatePos, true)
  window.removeEventListener('resize', updatePos)
  clearTimeout(searchDebounce)
})
</script>

<template>
  <div ref="rootEl" class="iss" :class="{ disabled }">
    <div ref="controlEl" class="iss-control" @click="openMenu">
      <input
        class="iss-input"
        :value="open ? query : displayText"
        :placeholder="placeholder"
        :disabled="disabled"
        @focus="openMenu"
        @input="onInput"
      />
      <button v-if="selected && !open" class="iss-clear" type="button" @click.stop="clearSel" aria-label="Hapus pilihan">×</button>
      <svg v-else class="iss-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
    </div>

    <Teleport to="body">
      <div v-if="open" ref="popEl" class="iss-pop" :style="popStyle">
        <div v-if="!filtered.length" class="iss-empty">Tidak ada barang cocok</div>
        <button
          v-for="it in filtered"
          :key="it.id"
          type="button"
          class="iss-opt"
          :class="{ sel: it.id === modelValue }"
          @click="pick(it)"
        >
          <span class="iss-opt-name">{{ it.name }}</span>
          <span v-if="it.code" class="iss-opt-code">{{ it.code }}</span>
        </button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.iss { position: relative; width: 100%; }
.iss-control { position: relative; display: flex; align-items: center; }
.iss-input { width: 100%; height: 30px; font-size: 12px; border: 1.5px solid var(--gb); border-radius: 6px; padding: 0 26px 0 8px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.iss-input:focus { border-color: var(--ga); background: #fff; }
.iss.disabled .iss-input { opacity: .6; cursor: not-allowed; }
.iss-caret { position: absolute; right: 7px; width: 13px; height: 13px; fill: none; stroke: var(--tu); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; pointer-events: none; }
.iss-clear { position: absolute; right: 5px; width: 18px; height: 18px; border: none; background: var(--gl); color: var(--tm); border-radius: 4px; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center; }
.iss-clear:hover { background: var(--eb); color: var(--et); }
</style>

<!-- Popup di-teleport ke body → style TIDAK boleh scoped (un-scoped global, prefiks .iss-pop). -->
<style>
.iss-pop { position: fixed; z-index: 3000; max-height: 240px; overflow-y: auto; background: var(--bc); border: 1px solid var(--gb); border-radius: 8px; box-shadow: 0 8px 22px rgba(15,23,42,.18); padding: 4px; }
.iss-pop .iss-empty { padding: 14px; text-align: center; font-size: 12px; color: var(--tu); }
.iss-pop .iss-opt { display: flex; flex-direction: column; align-items: flex-start; gap: 1px; width: 100%; padding: 6px 9px; border: none; background: none; border-radius: 6px; cursor: pointer; text-align: left; font-family: 'Inter', sans-serif; }
.iss-pop .iss-opt:hover { background: var(--bs); }
.iss-pop .iss-opt.sel { background: var(--gl); }
.iss-pop .iss-opt-name { font-size: 12.5px; color: var(--td); line-height: 1.25; }
.iss-pop .iss-opt-code { font-size: 10.5px; color: var(--tu); font-family: 'JetBrains Mono', monospace; }
</style>
