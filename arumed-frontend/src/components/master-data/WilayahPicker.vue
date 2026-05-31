<script setup>
/**
 * WilayahPicker — cascade dropdown Provinsi → Kabupaten/Kota → Kecamatan.
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
 * Layout: 3 select horizontal (responsif jadi vertikal di layar sempit).
 */
import { ref, watch, onMounted } from 'vue'
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

// Coba cocokkan props.province ke master & prefill chain. Emit prefill-status
// supaya parent tahu apakah data lama ketemu (true) atau tidak (false).
async function prefillFromProvince() {
  if (!props.province) return
  const p = provinces.value.find((x) => x.name === props.province)
  if (p) {
    selectedProvId.value = p.id
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
    if (!preserveSelection && props.district) {
      // Clear district kalau bukan preserving (saat user ganti regency)
      emit('update:district', '')
    }
  } catch (e) {
    errorMsg.value = 'Gagal memuat kecamatan.'
  } finally {
    loading.value.dist = false
  }
}

function onProvinceChange(e) {
  const id = e.target.value
  selectedProvId.value = id
  selectedRegId.value = ''
  regencies.value = []
  districts.value = []

  const p = provinces.value.find((x) => x.id === id)
  emit('update:province', p?.name ?? '')
  emit('update:regency',  '')
  emit('update:district', '')

  if (id) loadRegencies(id)
}

function onRegencyChange(e) {
  const id = e.target.value
  selectedRegId.value = id
  districts.value = []

  const r = regencies.value.find((x) => x.id === id)
  emit('update:regency',  r?.name ?? '')
  emit('update:district', '')

  if (id) loadDistricts(id)
}

function onDistrictChange(e) {
  const name = e.target.value
  emit('update:district', name)
}

onMounted(loadProvinces)

// Parent mengubah props.province dari luar (reset form / ganti pasien di wizard).
watch(() => props.province, (newName) => {
  if (!newName) {
    selectedProvId.value = ''
    selectedRegId.value = ''
    regencies.value = []
    districts.value = []
    return
  }
  // Abaikan kalau perubahan ini berasal dari pilihan user sendiri (dropdown sudah
  // sinkron). Hanya re-prefill bila nilai baru ≠ provinsi yang sedang terpilih.
  const current = provinces.value.find((x) => x.id === selectedProvId.value)
  if (current?.name === newName) return
  if (provinces.value.length) prefillFromProvince()
})
</script>

<template>
  <div class="wp-wrap">
    <div class="wp-row">
      <!-- Provinsi -->
      <div class="wp-field">
        <label>Provinsi</label>
        <select
          :value="selectedProvId"
          :disabled="disabled || loading.prov"
          @change="onProvinceChange"
        >
          <option value="">{{ loading.prov ? 'Memuat…' : '— pilih provinsi —' }}</option>
          <option v-for="p in provinces" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </div>

      <!-- Kabupaten/Kota -->
      <div class="wp-field">
        <label>Kabupaten / Kota</label>
        <select
          :value="selectedRegId"
          :disabled="disabled || !selectedProvId || loading.reg"
          @change="onRegencyChange"
        >
          <option value="">{{ loading.reg ? 'Memuat…' : '— pilih kab/kota —' }}</option>
          <option v-for="r in regencies" :key="r.id" :value="r.id">{{ r.name }}</option>
        </select>
      </div>

      <!-- Kecamatan -->
      <div v-if="showDistrict" class="wp-field">
        <label>Kecamatan</label>
        <select
          :value="district"
          :disabled="disabled || !selectedRegId || loading.dist"
          @change="onDistrictChange"
        >
          <option value="">{{ loading.dist ? 'Memuat…' : '— pilih kecamatan —' }}</option>
          <option v-for="d in districts" :key="d.id" :value="d.name">{{ d.name }}</option>
        </select>
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
.wp-field select {
  width: 100%;
  padding: 8px 11px;
  border: 1px solid var(--gb);
  border-radius: 9px;
  background: var(--bc);
  font-size: 13px;
  color: var(--td);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.wp-field select:focus {
  outline: none;
  border-color: var(--ga);
  box-shadow: 0 0 0 3px rgba(31,125,74,0.12);
}
.wp-field select:disabled { background: var(--bs); color: var(--tu); cursor: not-allowed; }

.wp-error { font-size: 12px; color: var(--et); margin: 0; display: flex; align-items: center; gap: 0.5rem; }
.wp-retry { padding: 3px 10px; border: 1px solid var(--ebd); background: var(--bc); color: var(--et); border-radius: 6px; cursor: pointer; font-size: 11px; }
.wp-retry:hover { background: var(--eb); }
</style>
