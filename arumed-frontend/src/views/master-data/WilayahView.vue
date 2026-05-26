<script setup>
/**
 * WilayahView — read-only browser wilayah Indonesia.
 *
 * Tidak ada CRUD — data dari emsifa/api-wilayah-indonesia (services/wilayah.js).
 * Tujuan: petugas master bisa lookup kode wilayah / verifikasi nama wilayah
 * tanpa keluar dari aplikasi.
 *
 * Flow:
 *   1. Pilih provinsi → load list kabupaten
 *   2. Pilih kabupaten → load list kecamatan
 *   3. Pilih kecamatan (opsional) → load list desa/kelurahan
 *
 * Setiap level menampilkan jumlah row + table item terpilih.
 */
import { ref } from 'vue'
import { wilayahApi } from '@/services/wilayah'

const province = ref({ id: '', name: '' })
const regency  = ref({ id: '', name: '' })
const district = ref({ id: '', name: '' })

const provinces = ref([])
const regencies = ref([])
const districts = ref([])
const villages  = ref([])

const loading = ref({ prov: false, reg: false, dist: false, vill: false })
const errMsg  = ref(null)

async function loadProvinces() {
  loading.value.prov = true
  errMsg.value = null
  try {
    provinces.value = await wilayahApi.provinces()
  } catch (e) {
    errMsg.value = 'Gagal memuat provinsi (cek koneksi internet).'
  } finally {
    loading.value.prov = false
  }
}

async function selectProvince(p) {
  province.value = { id: p.id, name: p.name }
  regency.value  = { id: '', name: '' }
  district.value = { id: '', name: '' }
  regencies.value = []
  districts.value = []
  villages.value  = []
  loading.value.reg = true
  try {
    regencies.value = await wilayahApi.regencies(p.id)
  } catch {
    errMsg.value = 'Gagal memuat kabupaten/kota.'
  } finally {
    loading.value.reg = false
  }
}

async function selectRegency(r) {
  regency.value = { id: r.id, name: r.name }
  district.value = { id: '', name: '' }
  districts.value = []
  villages.value = []
  loading.value.dist = true
  try {
    districts.value = await wilayahApi.districts(r.id)
  } catch {
    errMsg.value = 'Gagal memuat kecamatan.'
  } finally {
    loading.value.dist = false
  }
}

async function selectDistrict(d) {
  district.value = { id: d.id, name: d.name }
  villages.value = []
  loading.value.vill = true
  try {
    villages.value = await wilayahApi.villages(d.id)
  } catch {
    errMsg.value = 'Gagal memuat desa/kelurahan.'
  } finally {
    loading.value.vill = false
  }
}

async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text)
  } catch {
    // ignore
  }
}

loadProvinces()
</script>

<template>
  <div class="wv-wrap">
    <div class="wv-head">
      <h2>Wilayah Indonesia</h2>
      <p>
        Browser referensi wilayah (read-only). Sumber data:
        <a href="https://github.com/emsifa/api-wilayah-indonesia" target="_blank" rel="noopener">emsifa/api-wilayah-indonesia</a>.
        Klik baris untuk drill-down ke level berikutnya.
      </p>
    </div>

    <div v-if="errMsg" class="wv-error">
      {{ errMsg }}
      <button class="wv-retry" @click="loadProvinces">Coba lagi</button>
    </div>

    <!-- Breadcrumb -->
    <div class="wv-crumb">
      <span class="wv-crumb-label">Lokasi:</span>
      <span class="wv-crumb-item" :class="{ active: province.id && !regency.id }">
        {{ province.name || '—' }}
      </span>
      <span class="wv-crumb-sep" v-if="province.id">›</span>
      <span class="wv-crumb-item" v-if="province.id" :class="{ active: regency.id && !district.id }">
        {{ regency.name || 'pilih kab/kota' }}
      </span>
      <span class="wv-crumb-sep" v-if="regency.id">›</span>
      <span class="wv-crumb-item" v-if="regency.id" :class="{ active: district.id }">
        {{ district.name || 'pilih kecamatan' }}
      </span>
    </div>

    <!-- 4-column layout -->
    <div class="wv-cols">
      <!-- Provinsi -->
      <div class="wv-col">
        <div class="wv-col-head">
          <strong>Provinsi</strong>
          <span class="wv-count" v-if="provinces.length">{{ provinces.length }}</span>
        </div>
        <div class="wv-list">
          <div v-if="loading.prov" class="wv-state">
            <span class="wv-spinner"></span> Memuat…
          </div>
          <button
            v-for="p in provinces"
            :key="p.id"
            class="wv-item"
            :class="{ active: p.id === province.id }"
            @click="selectProvince(p)"
          >
            <span class="wv-item-name">{{ p.name }}</span>
            <span class="wv-item-id" @click.stop="copyText(p.id)" title="Salin ID">{{ p.id }}</span>
          </button>
        </div>
      </div>

      <!-- Kabupaten/Kota -->
      <div class="wv-col">
        <div class="wv-col-head">
          <strong>Kabupaten / Kota</strong>
          <span class="wv-count" v-if="regencies.length">{{ regencies.length }}</span>
        </div>
        <div class="wv-list">
          <div v-if="!province.id" class="wv-state wv-state-empty">Pilih provinsi dulu</div>
          <div v-else-if="loading.reg" class="wv-state">
            <span class="wv-spinner"></span> Memuat…
          </div>
          <button
            v-for="r in regencies"
            :key="r.id"
            class="wv-item"
            :class="{ active: r.id === regency.id }"
            @click="selectRegency(r)"
          >
            <span class="wv-item-name">{{ r.name }}</span>
            <span class="wv-item-id" @click.stop="copyText(r.id)" title="Salin ID">{{ r.id }}</span>
          </button>
        </div>
      </div>

      <!-- Kecamatan -->
      <div class="wv-col">
        <div class="wv-col-head">
          <strong>Kecamatan</strong>
          <span class="wv-count" v-if="districts.length">{{ districts.length }}</span>
        </div>
        <div class="wv-list">
          <div v-if="!regency.id" class="wv-state wv-state-empty">Pilih kab/kota dulu</div>
          <div v-else-if="loading.dist" class="wv-state">
            <span class="wv-spinner"></span> Memuat…
          </div>
          <button
            v-for="d in districts"
            :key="d.id"
            class="wv-item"
            :class="{ active: d.id === district.id }"
            @click="selectDistrict(d)"
          >
            <span class="wv-item-name">{{ d.name }}</span>
            <span class="wv-item-id" @click.stop="copyText(d.id)" title="Salin ID">{{ d.id }}</span>
          </button>
        </div>
      </div>

      <!-- Desa/Kelurahan -->
      <div class="wv-col">
        <div class="wv-col-head">
          <strong>Desa / Kelurahan</strong>
          <span class="wv-count" v-if="villages.length">{{ villages.length }}</span>
        </div>
        <div class="wv-list">
          <div v-if="!district.id" class="wv-state wv-state-empty">Pilih kecamatan dulu</div>
          <div v-else-if="loading.vill" class="wv-state">
            <span class="wv-spinner"></span> Memuat…
          </div>
          <div
            v-for="v in villages"
            :key="v.id"
            class="wv-item wv-item-leaf"
          >
            <span class="wv-item-name">{{ v.name }}</span>
            <span class="wv-item-id" @click.stop="copyText(v.id)" title="Salin ID">{{ v.id }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wv-wrap { display: flex; flex-direction: column; gap: 1rem; }

.wv-head h2 { font-family: 'DM Serif Display', serif; font-size: 20px; color: var(--td); margin: 0; }
.wv-head p { font-size: 13px; color: var(--tm); margin: 4px 0 0; line-height: 1.5; }
.wv-head a { color: var(--ga); text-decoration: none; }
.wv-head a:hover { text-decoration: underline; }

.wv-error { padding: 0.7rem 1rem; background: var(--eb); border: 1px solid var(--ebd); border-radius: 10px; color: var(--et); font-size: 13px; display: flex; align-items: center; justify-content: space-between; }
.wv-retry { padding: 4px 12px; border: 1px solid var(--ebd); background: var(--bc); color: var(--et); border-radius: 6px; cursor: pointer; font-size: 12px; }

.wv-crumb { display: flex; align-items: center; gap: 6px; padding: 0.6rem 1rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; font-size: 12.5px; color: var(--tm); flex-wrap: wrap; }
.wv-crumb-label { font-weight: 500; color: var(--td); }
.wv-crumb-item { padding: 2px 9px; border-radius: 6px; background: var(--bc); border: 1px solid var(--gb); }
.wv-crumb-item.active { background: var(--gl); border-color: var(--ga); color: var(--gd); font-weight: 500; }
.wv-crumb-sep { color: var(--tu); }

.wv-cols { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.8rem; min-height: 460px; }
@media (max-width: 1100px) { .wv-cols { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 600px)  { .wv-cols { grid-template-columns: 1fr; } }

.wv-col { display: flex; flex-direction: column; background: var(--bc); border: 1px solid var(--gb); border-radius: 10px; overflow: hidden; min-height: 0; }
.wv-col-head { display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0.85rem; background: var(--bs); border-bottom: 1px solid var(--gb); font-size: 12px; color: var(--td); }
.wv-count { font-size: 10px; padding: 2px 7px; border-radius: 999px; background: var(--gl); color: var(--gd); font-weight: 600; }

.wv-list { flex: 1; overflow-y: auto; padding: 0.3rem; display: flex; flex-direction: column; gap: 1px; max-height: 460px; }
.wv-list::-webkit-scrollbar { width: 4px; }
.wv-list::-webkit-scrollbar-thumb { background: var(--gb); border-radius: 2px; }

.wv-item { display: flex; justify-content: space-between; align-items: center; gap: 6px; padding: 7px 10px; border: none; background: transparent; text-align: left; cursor: pointer; border-radius: 7px; font-size: 12.5px; color: var(--td); transition: background 0.1s; }
.wv-item:hover { background: var(--bs); }
.wv-item.active { background: var(--gl); color: var(--gd); font-weight: 500; }
.wv-item-leaf { cursor: default; }
.wv-item-leaf:hover { background: var(--bs); }

.wv-item-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wv-item-id { font-family: 'Geist Mono', monospace; font-size: 10px; color: var(--tu); padding: 2px 5px; border-radius: 4px; cursor: pointer; flex-shrink: 0; }
.wv-item-id:hover { background: var(--bc); color: var(--ga); }

.wv-state { padding: 1.5rem 1rem; text-align: center; color: var(--tu); font-size: 12px; display: flex; align-items: center; justify-content: center; gap: 8px; }
.wv-state-empty { color: var(--th); font-style: italic; }
.wv-spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid var(--gb); border-top-color: var(--ga); border-radius: 50%; animation: wv-spin 0.7s linear infinite; }
@keyframes wv-spin { to { transform: rotate(360deg); } }
</style>
