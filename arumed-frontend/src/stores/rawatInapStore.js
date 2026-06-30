import { defineStore } from 'pinia'
import { ref } from 'vue'
import { ranapApi } from '@/services/api'

export const useRawatInapStore = defineStore('rawatInap', () => {
  const bedBoard      = ref([])   // [{ id, code, name, kelas_rawat, beds:[], occupied, total }]
  const menungguKamar = ref([])   // pasien planning RAWAT_INAP, menunggu admit
  const aktif         = ref([])   // pasien rawat inap aktif
  const detail        = ref(null) // { visit, charges, running_bill }

  const loading = ref(false)
  const error   = ref(null)

  function _fail(e, fallback) {
    error.value = e.response?.data?.message ?? fallback
    throw e
  }

  async function fetchBedBoard() {
    loading.value = true
    error.value = null
    try {
      const res = await ranapApi.bedBoard()
      bedBoard.value = res.data?.data ?? []
    } catch (e) {
      error.value = e.response?.data?.message ?? 'Gagal memuat papan room'
    } finally {
      loading.value = false
    }
  }

  async function fetchMenungguKamar() {
    try {
      const res = await ranapApi.menungguKamar()
      menungguKamar.value = res.data?.data ?? []
    } catch {
      menungguKamar.value = []
    }
  }

  async function fetchAktif() {
    try {
      const res = await ranapApi.aktif()
      aktif.value = res.data?.data ?? []
    } catch {
      aktif.value = []
    }
  }

  async function fetchDetail(visitId) {
    loading.value = true
    error.value = null
    try {
      const res = await ranapApi.detail(visitId)
      const d = res.data?.data ?? null
      // Tahan elemen null pada charges (dirender `:key="c.id"`) agar tak crash
      // "reading 'id' of null" yang mem-blank seluruh RawatInapView.
      if (d && Array.isArray(d.charges)) d.charges = d.charges.filter(Boolean)
      detail.value = d
    } catch (e) {
      error.value = e.response?.data?.message ?? 'Gagal memuat detail pasien'
    } finally {
      loading.value = false
    }
  }

  // ─── Aksi ──────────────────────────────────────────────────────────────────
  async function admit(visitId, payload) {
    try {
      const res = await ranapApi.admit(visitId, payload)
      await Promise.all([fetchBedBoard(), fetchMenungguKamar(), fetchAktif()])
      return res.data?.data
    } catch (e) { _fail(e, 'Gagal admit pasien') }
  }

  async function transfer(visitId, payload) {
    try {
      const res = await ranapApi.transfer(visitId, payload)
      await Promise.all([fetchBedBoard(), fetchAktif()])
      return res.data?.data
    } catch (e) { _fail(e, 'Gagal pindah kamar') }
  }

  async function addCharge(visitId, payload) {
    try {
      const res = await ranapApi.addCharge(visitId, payload)
      await fetchDetail(visitId)
      return res.data?.data
    } catch (e) { _fail(e, 'Gagal mencatat biaya') }
  }

  async function addTindakan(visitId, payload) {
    try {
      const res = await ranapApi.addTindakan(visitId, payload)
      await fetchDetail(visitId)
      return res.data?.data
    } catch (e) { _fail(e, 'Gagal mencatat tindakan') }
  }

  async function addObat(visitId, payload) {
    try {
      const res = await ranapApi.addObat(visitId, payload)
      await fetchDetail(visitId)
      return res.data?.data
    } catch (e) { _fail(e, 'Gagal mencatat obat') }
  }

  async function deleteCharge(visitId, chargeId) {
    try {
      await ranapApi.deleteCharge(visitId, chargeId)
      await fetchDetail(visitId)
    } catch (e) { _fail(e, 'Gagal menghapus biaya') }
  }

  async function kirimBedah(visitId, payload) {
    try {
      const res = await ranapApi.kirimBedah(visitId, payload)
      await fetchAktif()
      return res.data?.data
    } catch (e) { _fail(e, 'Gagal mengirim ke bedah') }
  }

  async function discharge(visitId, payload) {
    try {
      const res = await ranapApi.discharge(visitId, payload)
      await Promise.all([fetchBedBoard(), fetchAktif()])
      return res.data?.data
    } catch (e) { _fail(e, 'Gagal memulangkan pasien') }
  }

  return {
    bedBoard, menungguKamar, aktif, detail, loading, error,
    fetchBedBoard, fetchMenungguKamar, fetchAktif, fetchDetail,
    admit, transfer, addCharge, addTindakan, addObat, deleteCharge, kirimBedah, discharge,
  }
})
