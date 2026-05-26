/**
 * Tarif & Paket Bedah Store.
 *
 * Mengelola state untuk sub-modul standalone /tarif-paket/*:
 *  - tarif per penjamin (tindakan/obat/bhp/iol) → list paginated per type
 *  - paket bedah → list paginated + detail by id (dengan items + tariffs)
 *
 * Pola sengaja mirror masterDataStore (slot pattern) supaya komponen MasterTable
 * yang sudah ada bisa langsung dipakai untuk render list.
 */

import { defineStore } from 'pinia'
import { reactive, ref } from 'vue'
import { tarifPaketApi } from '@/services/api'

const TARIF_TYPES = ['tindakan', 'obat', 'bhp', 'iol']

function blankSlot() {
  return {
    items:   [],
    meta:    { current_page: 1, last_page: 1, total: 0, per_page: 25 },
    params:  { per_page: 25, page: 1 },
    loading: false,
    error:   null,
  }
}

function getMeta(payload) {
  if (payload && Array.isArray(payload.data)) {
    return {
      current_page: payload.current_page ?? 1,
      last_page:    payload.last_page ?? 1,
      total:        payload.total ?? payload.data.length,
      per_page:     payload.per_page ?? payload.data.length,
    }
  }
  const len = Array.isArray(payload) ? payload.length : 0
  return { current_page: 1, last_page: 1, total: len, per_page: len }
}

function getRows(payload) {
  if (payload && Array.isArray(payload.data)) return payload.data
  if (Array.isArray(payload)) return payload
  return []
}

function errMsg(e, fallback = 'Operasi gagal') {
  return e.response?.data?.message ?? e.message ?? fallback
}

export const useTarifPaketStore = defineStore('tarifPaket', () => {
  // ─── State ──────────────────────────────────────────────────────────────
  // Tarif per penjamin — slot per type
  const tarif = reactive(
    Object.fromEntries(TARIF_TYPES.map((t) => [t, blankSlot()])),
  )

  // Paket bedah — list state
  const paket = reactive(blankSlot())

  // Detail paket aktif (buka dari /paket-bedah/:id) — termasuk items & tariffs
  const paketDetail = ref(null)
  const paketDetailLoading = ref(false)
  const paketDetailError = ref(null)

  // ─── Tarif actions ──────────────────────────────────────────────────────
  async function fetchTarif(type, params = {}) {
    if (!TARIF_TYPES.includes(type)) throw new Error(`Type tarif tidak dikenal: ${type}`)
    const slot = tarif[type]
    slot.params = { ...slot.params, ...params }
    slot.loading = true
    slot.error = null
    try {
      const { data } = await tarifPaketApi.tarif.list(type, slot.params)
      const payload = data?.data ?? data
      slot.items = getRows(payload)
      slot.meta  = getMeta(payload)
    } catch (e) {
      slot.error = errMsg(e, `Gagal memuat tarif ${type}`)
    } finally {
      slot.loading = false
    }
  }

  async function createTarif(type, data) {
    const res = await tarifPaketApi.tarif.create(type, data)
    await fetchTarif(type)
    return res.data?.data ?? res.data
  }

  async function updateTarif(type, id, data) {
    const res = await tarifPaketApi.tarif.update(type, id, data)
    const slot = tarif[type]
    const idx = slot.items.findIndex((it) => it.id === id)
    if (idx !== -1 && res.data?.data) slot.items[idx] = res.data.data
    return res.data?.data ?? res.data
  }

  async function removeTarif(type, id) {
    await tarifPaketApi.tarif.remove(type, id)
    const slot = tarif[type]
    slot.items = slot.items.filter((it) => it.id !== id)
    slot.meta.total = Math.max(0, slot.meta.total - 1)
  }

  // ─── CSV tarif ──────────────────────────────────────────────────────────
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

  // CSV global per-type sudah dipindah ke per-insurer di MetodeBayarTarifTab.vue.
  // Fungsi lama dihapus karena endpoint /tarif/{type}/{export,import}-csv sudah tidak ada.

  // ─── Paket actions (CRUD) ───────────────────────────────────────────────
  async function fetchPaketList(params = {}) {
    paket.params = { ...paket.params, ...params }
    paket.loading = true
    paket.error = null
    try {
      const { data } = await tarifPaketApi.paket.list(paket.params)
      const payload = data?.data ?? data
      paket.items = getRows(payload)
      paket.meta  = getMeta(payload)
    } catch (e) {
      paket.error = errMsg(e, 'Gagal memuat paket bedah')
    } finally {
      paket.loading = false
    }
  }

  async function fetchPaketDetail(id) {
    paketDetailLoading.value = true
    paketDetailError.value = null
    try {
      const { data } = await tarifPaketApi.paket.show(id)
      paketDetail.value = data?.data ?? data
    } catch (e) {
      paketDetailError.value = errMsg(e, 'Gagal memuat detail paket')
    } finally {
      paketDetailLoading.value = false
    }
  }

  async function createPaket(data) {
    const res = await tarifPaketApi.paket.create(data)
    await fetchPaketList()
    return res.data?.data ?? res.data
  }

  async function updatePaket(id, data) {
    const res = await tarifPaketApi.paket.update(id, data)
    if (paketDetail.value?.id === id && res.data?.data) {
      paketDetail.value = { ...paketDetail.value, ...res.data.data }
    }
    const idx = paket.items.findIndex((it) => it.id === id)
    if (idx !== -1 && res.data?.data) paket.items[idx] = res.data.data
    return res.data?.data ?? res.data
  }

  async function removePaket(id) {
    await tarifPaketApi.paket.remove(id)
    paket.items = paket.items.filter((it) => it.id !== id)
    paket.meta.total = Math.max(0, paket.meta.total - 1)
    if (paketDetail.value?.id === id) paketDetail.value = null
  }

  // ─── Items paket ────────────────────────────────────────────────────────
  async function addItem(paketId, data) {
    const res = await tarifPaketApi.items.add(paketId, data)
    if (paketDetail.value?.id === paketId) await fetchPaketDetail(paketId)
    return res.data?.data ?? res.data
  }

  async function updateItem(paketId, itemId, data) {
    const res = await tarifPaketApi.items.update(paketId, itemId, data)
    if (paketDetail.value?.id === paketId) await fetchPaketDetail(paketId)
    return res.data?.data ?? res.data
  }

  async function removeItem(paketId, itemId) {
    await tarifPaketApi.items.remove(paketId, itemId)
    if (paketDetail.value?.id === paketId) await fetchPaketDetail(paketId)
  }

  // ─── Tariffs paket ──────────────────────────────────────────────────────
  async function upsertTariff(paketId, data) {
    const res = await tarifPaketApi.tariffs.upsert(paketId, data)
    if (paketDetail.value?.id === paketId) await fetchPaketDetail(paketId)
    return res.data?.data ?? res.data
  }

  async function removeTariff(paketId, tariffId) {
    await tarifPaketApi.tariffs.remove(paketId, tariffId)
    if (paketDetail.value?.id === paketId) await fetchPaketDetail(paketId)
  }

  return {
    // state
    tarif,
    paket,
    paketDetail, paketDetailLoading, paketDetailError,

    // constants
    TARIF_TYPES,

    // tarif ops
    fetchTarif, createTarif, updateTarif, removeTarif,

    // paket ops
    fetchPaketList, fetchPaketDetail,
    createPaket, updatePaket, removePaket,

    // items/tariffs ops
    addItem, updateItem, removeItem,
    upsertTariff, removeTariff,
  }
})
