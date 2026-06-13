<script setup>
/**
 * WilayahPicker — cascade combobox Provinsi → Kabupaten/Kota → Kecamatan.
 *
 * Sumber data: emsifa/api-wilayah-indonesia (services/wilayah.js).
 *
 * Pola pakai (v-model 3-bagian):
 *   <WilayahPicker
 *     v-model:province="form.province"
 *     v-model:regency="form.regency"
 *     v-model:district="form.district"
 *   />
 *
 * Setiap binding berisi NAMA (string) bukan ID — karena tabel patients pakai
 * kolom `province` string. Kalau butuh ID, tambah v-model:provinceId, dst.
 *
 * Tiap level = combobox: ketik untuk MENYARING daftar (bukan hanya lompat huruf
 * pertama seperti <select>). Layout 3 kolom horizontal (responsif → vertikal).
 */
import { ref, computed, watch, onMounted } from 'vue'
import { wilayahApi } from '@/services/wilayah'

const props = defineProps({
  province: { type: String, default: '' },
  regency:  { type: String, default: '' },
  district: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  showDistrict: { type: Boolean, default: true },
})

const emit = defineEmits([
  'update:province', 'update:regency', 'update:district',
  // Dipancarkan setelah load awal: true jika props.province ketemu di master
  // (dropdown ter-prefill), false jika tidak (mis. data migrasi ejaan beda).
  'prefill-status',
])

const provinces = ref([])    // [{id, name}]
const regencies = ref([])
const districts = ref([])

const loading = ref({ prov: false, reg: false, dist: false })
const errorMsg = ref(null)

const selectedProvId = ref('')
const selectedRegId  = ref('')

// Teks pada masing-masing input combobox. Diinisialisasi dari props supaya nilai
// lama (termasuk yang ejaannya tak cocok master) tetap tampil utuh.
const provQuery = ref(props.province || '')
const regQuery  = ref(props.regency  || '')
const distQuery = ref(props.district || '')

// Dropdown mana yang sedang terbuka: 'prov' | 'reg' | 'dist' | null.
const openLevel = ref(null)

// Nama yang sedang TERPILIH per level (sumber kebenaran untuk merevert ketikan
// parsial yang tak jadi dipilih).
function currentName(level) {
  if (level === 'prov') return provinces.value.find((x) => x.id === selectedProvId.value)?.name ?? props.province ?? ''
  if (level === 'reg')  return regencies.value.find((x) => x.id === selectedRegId.value)?.name ?? props.regency ?? ''
  return props.district ?? ''
}

// Saring daftar: tampilkan SEMUA saat belum mengetik / teks sama dgn pilihan,
// selain itu filter case-insensitive berdasarkan substring nama.
function filterList(list, q, level) {
  const s = String(q ?? '').trim()
  if (!s || s === currentName(level)) return list
  const low = s.toLowerCase()
  return list.filter((x) => x.name.toLowerCase().includes(low))
}

const provFiltered = computed(() => filterList(provinces.value, provQuery.value, 'prov'))
const regFiltered  = computed(() => filterList(regencies.value, regQuery.value, 'reg'))
const distFiltered = computed(() => filterList(districts.value, distQuery.value, 'dist'))

// Coba cocokkan props.province ke master & prefill chain. Emit prefill-status
// supaya parent tahu apakah data lama ketemu (true) atau tidak (false).
async function prefillFromProvince() {
  if (!props.province) return
  const p = provinces.value.find((x) => x.name === props.province)
  if (p) {
    selectedProvId.value = p.id
    provQuery.value = p.name
    await loadRegencies(p.id, true)
    emit('prefill-status', true)
  } else {
    // Nama lama tak cocok master → dropdown kosong, nilai lama dibiarkan utuh.
    selectedProvId.value = ''
    selectedRegId.value = ''
    regencies.value = []
    districts.value = []
    emit('prefill-status', false)
  }
}

async function loadProvinces() {
  loading.value.prov = true
  errorMsg.value = null
  try {
    provinces.value = await wilayahApi.provinces()
    // Kalau prop sudah ada nilai province (mode edit), prefill dropdown.
    await prefillFromProvince()
  } catch (e) {
    errorMsg.value = 'Gagal memuat daftar provinsi (cek koneksi internet).'
  } finally {
    loading.value.prov = false
  }
}

async function loadRegencies(provId, preserveSelection = false) {
  if (!provId) { regencies.value = []; districts.value = []; return }
  loading.value.reg = true
  try {
    regencies.value = await wilayahApi.regencies(provId)
    if (preserveSelection && props.regency) {
      const r = regencies.value.find((x) => x.name === props.regency)
      if (r) {
        selectedRegId.value = r.id
        regQuery.value = r.name
        await loadDistricts(r.id, true)
      }
    }
  } catch (e) {
    errorMsg.value = 'Gagal memuat kabupaten/kota.'
  } finally {
    loading.value.reg = false
  }
}

async function loadDistricts(regId, preserveSelection = false) {
  if (!regId) { districts.value = []; return }
  if (!props.showDistrict) return
  loading.value.dist = true
  try {
    districts.value = await wilayahApi.districts(regId)
    if (preserveSelection && props.district) {
      distQuery.value = props.district
    } else if (!preserveSelection && props.district) {
      // Clear district kalau bukan preserving (saat user ganti regency)
      distQuery.value = ''
      emit('update:district', '')
    }
  } catch (e) {
    errorMsg.value = 'Gagal memuat kecamatan.'
  } finally {
    loading.value.dist = false
  }
}

function selectProvince(p) {
  selectedProvId.value = p.id
  selectedRegId.value = ''
  regencies.value = []
  districts.value = []
  provQuery.value = p.name
  regQuery.value = ''
  distQuery.value = ''

  emit('update:province', p.name)
  emit('update:regency',  '')
  emit('update:district', '')

  openLevel.value = null
  loadRegencies(p.id)
}

function selectRegency(r) {
  selectedRegId.value = r.id
  districts.value = []
  regQuery.value = r.name
  distQuery.value = ''

  emit('update:regency',  r.name)
  emit('update:district', '')

  openLevel.value = null
  loadDistricts(r.id)
}

function selectDistrict(d) {
  distQuery.value = d.name
  emit('update:district', d.name)
  openLevel.value = null
}

// Fokus input → buka dropdown & select-all (ketikan pertama langsung menyaring).
function onFocus(level, e) {
  if (props.disabled) return
  if (level === 'reg' && !selectedProvId.value) return
  if (level === 'dist' && !selectedRegId.value) return
  openLevel.value = level
  e?.target?.select?.()
}

// Blur → tutup (beri jeda agar klik opsi sempat terproses) & revert ketikan
// parsial yang tak jadi dipilih ke nama terpilih.
function onBlur(level) {
  setTimeout(() => {
    if (openLevel.value === level) openLevel.value = null
    if (level === 'prov') provQuery.value = currentName('prov')
    else if (level === 'reg') regQuery.value = currentName('reg')
    else distQuery.value = currentName('dist')
  }, 150)
}

// Enter → pilih opsi pertama yang tersaring (kalau ada).
function onEnter(level) {
  if (level === 'prov' && provFiltered.value.length) selectProvince(provFiltered.value[0])
  else if (level === 'reg' && regFiltered.value.length) selectRegency(regFiltered.value[0])
  else if (level === 'dist' && distFiltered.value.length) selectDistrict(distFiltered.value[0])
}

onMounted(loadProvinces)

// Parent mengubah props.province dari luar (reset form / ganti pasien di wizard).
watch(() => props.province, (newName) => {
  if (!newName) {
    selectedProvId.value = ''
    selectedRegId.value = ''
    regencies.value = []
    districts.value = []
    provQuery.value = ''
    regQuery.value = ''
    distQuery.value = ''
    return
  }
  // Abaikan kalau perubahan ini berasal dari pilihan user sendiri (dropdown sudah
  // sinkron). Hanya re-prefill bila nilai baru ≠ provinsi yang sedang terpilih.
  const current = provinces.value.find((x) => x.id === selectedProvId.value)
  if (current?.name === newName) return
  provQuery.value = newName
  regQuery.value = props.regency || ''
  distQuery.value = props.district || ''
  if (provinces.value.length) prefillFromProvince()
})

// Sinkronkan teks input bila parent mengubah regency/district dari luar
// (mis. prefill async selesai setelah komponen sudah mount).
watch(() => props.regency,  (v) => { if (openLevel.value !== 'reg')  regQuery.value  = v || '' })
watch(() => props.district, (v) => { if (openLevel.value !== 'dist') distQuery.value = v || '' })
</script>

<template>
  <div class="wp-wrap">
    <div class="wp-row">
      <!-- Provinsi -->
      <div class="wp-field">
        <label>Provinsi</label>
        <div class="wp-combo" :class="{ open: openLevel === 'prov' }">
          <input
            v-model="provQuery"
            type="text"
            class="wp-input"
            :placeholder="loading.prov ? 'Memuat…' : 'Cari / pilih provinsi…'"
            :disabled="disabled || loading.prov"
            autocomplete="off"
            @focus="onFocus('prov', $event)"
            @blur="onBlur('prov')"
            @keydown.enter.prevent="onEnter('prov')"
            @keydown.esc="openLevel = null"
          />
          <svg class="wp-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          <ul v-if="openLevel === 'prov'" class="wp-drop">
            <li v-if="!provFiltered.length" class="wp-empty">Tidak ada hasil</li>
            <li
              v-for="p in provFiltered"
              :key="p.id"
              :class="{ sel: p.id === selectedProvId }"
              @mousedown.prevent="selectProvince(p)"
            >{{ p.name }}</li>
          </ul>
        </div>
      </div>

      <!-- Kabupaten/Kota -->
      <div class="wp-field">
        <label>Kabupaten / Kota</label>
        <div class="wp-combo" :class="{ open: openLevel === 'reg', disabled: disabled || !selectedProvId }">
          <input
            v-model="regQuery"
            type="text"
            class="wp-input"
            :placeholder="loading.reg ? 'Memuat…' : (!selectedProvId ? 'Pilih provinsi dulu' : 'Cari / pilih kab/kota…')"
            :disabled="disabled || !selectedProvId || loading.reg"
            autocomplete="off"
            @focus="onFocus('reg', $event)"
            @blur="onBlur('reg')"
            @keydown.enter.prevent="onEnter('reg')"
            @keydown.esc="openLevel = null"
          />
          <svg class="wp-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          <ul v-if="openLevel === 'reg'" class="wp-drop">
            <li v-if="!regFiltered.length" class="wp-empty">Tidak ada hasil</li>
            <li
              v-for="r in regFiltered"
              :key="r.id"
              :class="{ sel: r.id === selectedRegId }"
              @mousedown.prevent="selectRegency(r)"
            >{{ r.name }}</li>
          </ul>
        </div>
      </div>

      <!-- Kecamatan -->
      <div v-if="showDistrict" class="wp-field">
        <label>Kecamatan</label>
        <div class="wp-combo" :class="{ open: openLevel === 'dist', disabled: disabled || !selectedRegId }">
          <input
            v-model="distQuery"
            type="text"
            class="wp-input"
            :placeholder="loading.dist ? 'Memuat…' : (!selectedRegId ? 'Pilih kab/kota dulu' : 'Cari / pilih kecamatan…')"
            :disabled="disabled || !selectedRegId || loading.dist"
            autocomplete="off"
            @focus="onFocus('dist', $event)"
            @blur="onBlur('dist')"
            @keydown.enter.prevent="onEnter('dist')"
            @keydown.esc="openLevel = null"
          />
          <svg class="wp-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          <ul v-if="openLevel === 'dist'" class="wp-drop">
            <li v-if="!distFiltered.length" class="wp-empty">Tidak ada hasil</li>
            <li
              v-for="d in distFiltered"
              :key="d.id"
              :class="{ sel: d.name === district }"
              @mousedown.prevent="selectDistrict(d)"
            >{{ d.name }}</li>
          </ul>
        </div>
      </div>
    </div>

    <p v-if="errorMsg" class="wp-error">
      {{ errorMsg }}
      <button class="wp-retry" @click="loadProvinces">Coba lagi</button>
    </p>
  </div>
</template>

<style scoped>
.wp-wrap { display: flex; flex-direction: column; gap: 0.5rem; }
.wp-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.8rem; }
@media (max-width: 720px) { .wp-row { grid-template-columns: 1fr; } }

.wp-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.wp-field label { font-size: 11px; font-weight: 600; color: var(--tm); text-transform: uppercase; letter-spacing: 0.03em; }

.wp-combo { position: relative; min-width: 0; }
.wp-input {
  width: 100%;
  padding: 8px 30px 8px 11px;
  border: 1px solid var(--gb);
  border-radius: 9px;
  background: var(--bc);
  font-size: 13px;
  color: var(--td);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.wp-input:focus {
  outline: none;
  border-color: var(--ga);
  box-shadow: 0 0 0 3px rgba(31,125,74,0.12);
}
.wp-input:disabled { background: var(--bs); color: var(--tu); cursor: not-allowed; }

.wp-caret {
  position: absolute; right: 9px; top: 50%; transform: translateY(-50%);
  width: 16px; height: 16px; pointer-events: none;
  fill: none; stroke: var(--tm); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
  transition: transform 0.15s;
}
.wp-combo.open .wp-caret { transform: translateY(-50%) rotate(180deg); }
.wp-combo.disabled .wp-caret { opacity: 0.4; }

.wp-drop {
  position: absolute; z-index: 50; top: calc(100% + 4px); left: 0; right: 0;
  margin: 0; padding: 4px; list-style: none;
  max-height: 240px; overflow-y: auto;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 9px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.12);
}
.wp-drop li {
  padding: 7px 10px; border-radius: 6px; font-size: 13px; color: var(--td); cursor: pointer;
}
.wp-drop li:hover { background: var(--bs); }
.wp-drop li.sel { background: rgba(31,125,74,0.12); color: var(--ga); font-weight: 600; }
.wp-drop li.wp-empty { color: var(--tu); cursor: default; text-align: center; }
.wp-drop li.wp-empty:hover { background: transparent; }

.wp-error { font-size: 12px; color: var(--et); margin: 0; display: flex; align-items: center; gap: 0.5rem; }
.wp-retry { padding: 3px 10px; border: 1px solid var(--ebd); background: var(--bc); color: var(--et); border-radius: 6px; cursor: pointer; font-size: 11px; }
.wp-retry:hover { background: var(--eb); }
</style>
