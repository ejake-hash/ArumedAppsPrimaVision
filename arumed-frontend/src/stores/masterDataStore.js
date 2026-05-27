/**
 * Master Data Store — generic untuk semua resource master.
 *
 * Pola:
 *   - State per resource disimpan di map `byResource[key]` supaya satu store
 *     bisa handle 8 tab tanpa duplikasi kode.
 *   - Resource registry (REGISTRY) memetakan key (router param friendly) ke
 *     endpoint group di masterApi + metadata (label, csvType untuk template/import/export).
 *   - Resource 'profilKlinik' (singleton) pakai action terpisah karena bentuk
 *     respons-nya beda (single object, bukan paginated list).
 *
 * Pakai:
 *   const store = useMasterDataStore()
 *   await store.fetchList('obat', { search: 'panad', per_page: 25 })
 *   store.byResource.obat.items        // → array of medication rows
 *   store.byResource.obat.meta         // → { current_page, last_page, total, per_page }
 */

import { defineStore } from 'pinia'
import { reactive, ref } from 'vue'
import { masterApi } from '@/services/api'

/**
 * Registry: kode resource → konfigurasi endpoint & metadata.
 * - `api`     : object dengan method list/create/update/remove (dari masterApi)
 * - `csvType` : string param untuk masterApi.csv.{template,export,import} —
 *               null artinya resource tidak support CSV
 * - `label`   : untuk UI breadcrumb / toast
 */
export const REGISTRY = {
  obat:          { api: masterApi.obat,          csvType: 'obat',           label: 'Obat' },
  bhp:           { api: masterApi.bhp,           csvType: 'bhp',            label: 'BHP' },
  iol:           { api: masterApi.iol,           csvType: 'iol',            label: 'IOL' },
  icd10:         { api: masterApi.icd10,         csvType: 'icd10',          label: 'ICD-10' },
  icd9:          { api: masterApi.icd9,          csvType: 'icd9',           label: 'ICD-9' },
  tindakan:      { api: masterApi.tindakan,      csvType: 'tindakan',       label: 'Tarif Tindakan' },
  tarifTindakan: { api: masterApi.tarifTindakan, csvType: 'tarif/tindakan', label: 'Tarif Tindakan' },
  paketBedah:    { api: masterApi.paketBedah,    csvType: null,             label: 'Paket Bedah' },
  supplier:      { api: masterApi.supplier,      csvType: null,             label: 'Supplier' },
  diagnosticTestType: { api: masterApi.diagnosticTestType, csvType: null,    label: 'Jenis Penunjang' },
}

function blankSlot() {
  return {
    items:   [],
    meta:    { current_page: 1, last_page: 1, total: 0, per_page: 25 },
    params:  { search: '', per_page: 25, page: 1 },
    loading: false,
    error:   null,
  }
}

function getMeta(payload) {
  // Laravel paginate response: { data: [...], current_page, last_page, total, per_page, ... }
  if (payload && Array.isArray(payload.data)) {
    return {
      current_page: payload.current_page ?? 1,
      last_page:    payload.last_page ?? 1,
      total:        payload.total ?? payload.data.length,
      per_page:     payload.per_page ?? payload.data.length,
    }
  }
  // Plain array
  const len = Array.isArray(payload) ? payload.length : 0
  return { current_page: 1, last_page: 1, total: len, per_page: len }
}

function getRows(payload) {
  if (payload && Array.isArray(payload.data)) return payload.data
  if (Array.isArray(payload)) return payload
  return []
}

function assertResource(key) {
  if (!REGISTRY[key]) {
    throw new Error(`Resource master "${key}" tidak terdaftar di REGISTRY masterDataStore`)
  }
  return REGISTRY[key]
}

function errMsg(e, fallback = 'Operasi gagal') {
  return e.response?.data?.message ?? e.message ?? fallback
}

export const useMasterDataStore = defineStore('masterData', () => {
  // ─── State ──────────────────────────────────────────────────────────────
  // Pre-populate slot untuk semua resource yang terdaftar — supaya komponen
  // bisa langsung baca `store.byResource.obat.loading` tanpa cek undefined.
  const byResource = reactive(
    Object.fromEntries(Object.keys(REGISTRY).map((k) => [k, blankSlot()])),
  )

  // Profil Klinik (singleton) — state terpisah karena bukan list.
  const profilKlinik = ref(null)
  const profilLoading = ref(false)
  const profilError   = ref(null)

  // ─── List actions ───────────────────────────────────────────────────────
  async function fetchList(key, params = {}) {
    const { api } = assertResource(key)
    const slot = byResource[key]
    slot.params = { ...slot.params, ...params }
    slot.loading = true
    slot.error = null
    try {
      const { data } = await api.list(slot.params)
      const payload = data?.data ?? data
      slot.items = getRows(payload)
      slot.meta  = getMeta(payload)
    } catch (e) {
      slot.error = errMsg(e, `Gagal memuat ${REGISTRY[key].label}`)
    } finally {
      slot.loading = false
    }
  }

  async function create(key, payload) {
    const { api } = assertResource(key)
    const { data } = await api.create(payload)
    await fetchList(key)
    return data?.data ?? data
  }

  async function update(key, id, payload) {
    const { api } = assertResource(key)
    const { data } = await api.update(id, payload)
    const slot = byResource[key]
    const idx = slot.items.findIndex((it) => it.id === id)
    if (idx !== -1 && data?.data) slot.items[idx] = data.data
    return data?.data ?? data
  }

  async function remove(key, id) {
    const { api } = assertResource(key)
    await api.remove(id)
    const slot = byResource[key]
    slot.items = slot.items.filter((it) => it.id !== id)
    slot.meta.total = Math.max(0, slot.meta.total - 1)
  }

  // ─── CSV actions ────────────────────────────────────────────────────────
  function triggerDownload(blob, filename) {
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  }

  async function downloadTemplate(key) {
    const { csvType, label } = assertResource(key)
    if (!csvType) throw new Error(`${label} tidak mendukung CSV template`)
    const res = await masterApi.csv.template(csvType)
    triggerDownload(res.data, `template-${csvType.replace('/', '-')}.csv`)
  }

  async function exportCsv(key) {
    const { csvType, label } = assertResource(key)
    if (!csvType) throw new Error(`${label} tidak mendukung CSV export`)
    const res = await masterApi.csv.export(csvType)
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '')
    triggerDownload(res.data, `${csvType.replace('/', '-')}-${today}.csv`)
  }

  async function importCsv(key, file) {
    const { csvType, label } = assertResource(key)
    if (!csvType) throw new Error(`${label} tidak mendukung CSV import`)
    const { data } = await masterApi.csv.import(csvType, file)
    await fetchList(key)
    return data?.data ?? data
  }

  // ─── Profil Klinik (singleton) ──────────────────────────────────────────
  async function fetchProfilKlinik() {
    profilLoading.value = true
    profilError.value = null
    try {
      const { data } = await masterApi.profilKlinik.show()
      profilKlinik.value = data?.data ?? data
    } catch (e) {
      profilError.value = errMsg(e, 'Gagal memuat profil klinik')
    } finally {
      profilLoading.value = false
    }
  }

  async function saveProfilKlinik(payload) {
    const { data } = await masterApi.profilKlinik.update(payload)
    profilKlinik.value = data?.data ?? data
    return profilKlinik.value
  }

  return {
    // state
    byResource,
    profilKlinik, profilLoading, profilError,

    // metadata
    REGISTRY,

    // list ops
    fetchList, create, update, remove,

    // csv ops
    downloadTemplate, exportCsv, importCsv,

    // profil klinik
    fetchProfilKlinik, saveProfilKlinik,
  }
})
