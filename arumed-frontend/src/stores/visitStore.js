import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { visitApi } from '@/services/api'

export const useVisitStore = defineStore('visit', () => {
  // ─── State ──────────────────────────────────────────────────────────────
  const visits      = ref([])
  const currentVisit = ref(null)
  const pagination  = ref({ current_page: 1, last_page: 1, per_page: 20, total: 0 })
  const loading     = ref(false)
  const error       = ref(null)

  const filters = ref({
    tanggal:        null,
    station:        null,
    guarantor_type: null,
    classification: null,
    search:         '',
    per_page:       20,
  })

  // ─── Getters ─────────────────────────────────────────────────────────────
  const selesaiCount  = computed(() => visits.value.filter((v) => v.current_station === 'SELESAI').length)
  const bpjsCount     = computed(() => visits.value.filter((v) => v.guarantor_type === 'BPJS').length)
  const totalCount    = computed(() => pagination.value.total)

  // ─── Actions ─────────────────────────────────────────────────────────────

  async function fetchVisits(overrideFilters = {}) {
    loading.value = true
    error.value   = null

    try {
      const params = { ...filters.value, ...overrideFilters }
      // Remove empty values
      Object.keys(params).forEach((k) => {
        if (params[k] === null || params[k] === '') delete params[k]
      })

      const { data } = await visitApi.list(params)

      visits.value    = data.data.data ?? data.data
      pagination.value = {
        current_page: data.data.current_page ?? 1,
        last_page:    data.data.last_page ?? 1,
        per_page:     data.data.per_page ?? 20,
        total:        data.data.total ?? visits.value.length,
      }
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Gagal memuat kunjungan'
    } finally {
      loading.value = false
    }
  }

  async function fetchVisit(id) {
    loading.value = true
    error.value   = null

    try {
      const { data } = await visitApi.show(id)
      currentVisit.value = data.data
      return data.data
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Kunjungan tidak ditemukan'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function daftarKunjungan(formData) {
    loading.value = true
    error.value   = null

    try {
      const { data } = await visitApi.daftar(formData)
      // Insert at top of list
      visits.value.unshift(data.data)
      return data.data
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Gagal mendaftarkan kunjungan'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function cancelKunjungan(id) {
    loading.value = true

    try {
      await visitApi.cancel(id)
      visits.value = visits.value.filter((v) => v.id !== id)
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Gagal membatalkan kunjungan'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function cariPasien(keyword) {
    if (!keyword || keyword.length < 2) return []

    try {
      const { data } = await visitApi.cariPasien(keyword)
      return data.data ?? []
    } catch {
      return []
    }
  }

  function setFilter(key, value) {
    filters.value[key] = value
  }

  function resetFilters() {
    filters.value = { tanggal: null, station: null, guarantor_type: null, classification: null, search: '', per_page: 20 }
  }

  return {
    visits,
    currentVisit,
    pagination,
    loading,
    error,
    filters,
    selesaiCount,
    bpjsCount,
    totalCount,
    fetchVisits,
    fetchVisit,
    daftarKunjungan,
    cancelKunjungan,
    cariPasien,
    setFilter,
    resetFilters,
  }
})
