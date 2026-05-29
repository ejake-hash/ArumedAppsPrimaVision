/**
 * Asuransi/TPA Non-BPJS — verifikasi eligibility + workflow klaim.
 * Tidak menyentuh KlaimStore/BPJS flow.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { asuransiApi } from '@/services/api'

export const useAsuransiStore = defineStore('asuransi', () => {
  // ─── State ───────────────────────────────────────────────────────────────────
  const pendingList     = ref([])
  const pendingLoading  = ref(false)

  const inServiceList   = ref([])
  const inServiceLoading = ref(false)

  const billingDetail   = ref(null)
  const billingLoading  = ref(false)

  const verifikasi      = ref(null)
  const verifLoading    = ref(false)
  const verifSaving     = ref(false)

  const klaims          = ref({ data: [], current_page: 1, last_page: 1, total: 0 })
  const klaimsLoading   = ref(false)
  const selectedKlaim   = ref(null)
  const klaimLoading    = ref(false)
  const klaimSaving     = ref(false)

  const klaimLogs       = ref([])
  const klaimLogsLoading = ref(false)

  const aging           = ref([])
  const agingLoading    = ref(false)

  const summary         = ref({
    pending_verification: 0,
    draft_claims: 0,
    overdue_claims: 0,
    submitted_this_month: 0,
    approved_this_month: 0,
    rejected_this_month: 0,
  })
  const summaryLoading  = ref(false)

  const docRequirements = ref([])
  const docReqLoading   = ref(false)

  const error           = ref(null)

  // ─── Getters ─────────────────────────────────────────────────────────────────
  const overdueCount = computed(
    () => aging.value.filter((a) => a.is_overdue).length,
  )

  // ─── Helpers ─────────────────────────────────────────────────────────────────
  const _err = (e, fallback) =>
    (error.value = e?.response?.data?.message ?? fallback)

  // ─── Verifikasi Actions ──────────────────────────────────────────────────────
  async function fetchPendingVerifications(date = null) {
    pendingLoading.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.pendingVerifications(date ? { date } : {})
      pendingList.value = data.data ?? []
    } catch (e) {
      _err(e, 'Gagal memuat verifikasi pending')
    } finally {
      pendingLoading.value = false
    }
  }

  async function fetchInServiceVerifications(date = null) {
    inServiceLoading.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.inServiceVerifications(date ? { date } : {})
      inServiceList.value = data.data ?? []
    } catch (e) {
      _err(e, 'Gagal memuat pasien sedang dilayani')
    } finally {
      inServiceLoading.value = false
    }
  }

  async function fetchBilling(visitId) {
    billingLoading.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.getBilling(visitId)
      billingDetail.value = data.data
      return billingDetail.value
    } catch (e) {
      _err(e, 'Gagal memuat rincian tagihan')
      throw e
    } finally {
      billingLoading.value = false
    }
  }

  async function fetchVerifikasi(visitId) {
    verifLoading.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.getVerifikasi(visitId)
      verifikasi.value = data.data
      return verifikasi.value
    } catch (e) {
      _err(e, 'Gagal memuat verifikasi')
      throw e
    } finally {
      verifLoading.value = false
    }
  }

  async function createVerifikasi(payload) {
    verifSaving.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.createVerifikasi(payload)
      verifikasi.value = data.data
      return data.data
    } catch (e) {
      _err(e, 'Gagal menyimpan verifikasi')
      throw e
    } finally {
      verifSaving.value = false
    }
  }

  async function updateVerifikasi(id, payload) {
    verifSaving.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.updateVerifikasi(id, payload)
      verifikasi.value = data.data
      return data.data
    } catch (e) {
      _err(e, 'Gagal memperbarui verifikasi')
      throw e
    } finally {
      verifSaving.value = false
    }
  }

  // ─── Klaim Actions ──────────────────────────────────────────────────────────
  async function fetchKlaims(filters = {}) {
    klaimsLoading.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.listKlaim(filters)
      klaims.value = data.data ?? { data: [], current_page: 1, last_page: 1, total: 0 }
    } catch (e) {
      _err(e, 'Gagal memuat daftar klaim')
    } finally {
      klaimsLoading.value = false
    }
  }

  async function fetchKlaim(id) {
    klaimLoading.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.showKlaim(id)
      selectedKlaim.value = data.data
      return data.data
    } catch (e) {
      _err(e, 'Gagal memuat detail klaim')
      throw e
    } finally {
      klaimLoading.value = false
    }
  }

  async function createKlaim(payload) {
    klaimSaving.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.createKlaim(payload)
      return data.data
    } catch (e) {
      _err(e, 'Gagal membuat klaim')
      throw e
    } finally {
      klaimSaving.value = false
    }
  }

  async function updateKlaim(id, payload) {
    klaimSaving.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.updateKlaim(id, payload)
      if (selectedKlaim.value?.id === id) selectedKlaim.value = data.data
      return data.data
    } catch (e) {
      _err(e, 'Gagal memperbarui klaim')
      throw e
    } finally {
      klaimSaving.value = false
    }
  }

  async function submitKlaim(id, payload) {
    klaimSaving.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.submitKlaim(id, payload)
      if (selectedKlaim.value?.id === id) selectedKlaim.value = data.data
      return data.data
    } catch (e) {
      _err(e, 'Gagal submit klaim')
      throw e
    } finally {
      klaimSaving.value = false
    }
  }

  async function updateStatusKlaim(id, payload) {
    klaimSaving.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.updateStatus(id, payload)
      if (selectedKlaim.value?.id === id) selectedKlaim.value = data.data
      return data.data
    } catch (e) {
      _err(e, 'Gagal update status klaim')
      throw e
    } finally {
      klaimSaving.value = false
    }
  }

  async function resubmitKlaim(id, payload) {
    klaimSaving.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.resubmitKlaim(id, payload)
      if (selectedKlaim.value?.id === id) selectedKlaim.value = data.data
      return data.data
    } catch (e) {
      _err(e, 'Gagal resubmit klaim')
      throw e
    } finally {
      klaimSaving.value = false
    }
  }

  async function fetchLogs(id) {
    klaimLogsLoading.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.logsKlaim(id)
      klaimLogs.value = data.data ?? []
    } catch (e) {
      _err(e, 'Gagal memuat log klaim')
    } finally {
      klaimLogsLoading.value = false
    }
  }

  // ─── Laporan Actions ────────────────────────────────────────────────────────
  async function fetchAging() {
    agingLoading.value = true
    error.value = null
    try {
      const { data } = await asuransiApi.aging()
      aging.value = data.data ?? []
    } catch (e) {
      _err(e, 'Gagal memuat aging report')
    } finally {
      agingLoading.value = false
    }
  }

  async function fetchSummary() {
    summaryLoading.value = true
    try {
      const { data } = await asuransiApi.summary()
      summary.value = data.data ?? summary.value
    } catch (e) {
      _err(e, 'Gagal memuat summary')
    } finally {
      summaryLoading.value = false
    }
  }

  // ─── Document Requirements ──────────────────────────────────────────────────
  async function fetchDocRequirements(insurerId) {
    docReqLoading.value = true
    try {
      const { data } = await asuransiApi.listDocReq(insurerId)
      docRequirements.value = data.data ?? []
    } catch (e) {
      _err(e, 'Gagal memuat dokumen requirement')
    } finally {
      docReqLoading.value = false
    }
  }

  async function createDocRequirement(insurerId, payload) {
    const { data } = await asuransiApi.createDocReq(insurerId, payload)
    docRequirements.value.push(data.data)
    return data.data
  }

  async function updateDocRequirement(id, payload) {
    const { data } = await asuransiApi.updateDocReq(id, payload)
    const idx = docRequirements.value.findIndex((r) => r.id === id)
    if (idx >= 0) docRequirements.value[idx] = data.data
    return data.data
  }

  async function deleteDocRequirement(id) {
    await asuransiApi.deleteDocReq(id)
    docRequirements.value = docRequirements.value.filter((r) => r.id !== id)
  }

  // ─── Reset ──────────────────────────────────────────────────────────────────
  function clearSelected() {
    selectedKlaim.value = null
    klaimLogs.value = []
    verifikasi.value = null
  }

  return {
    // state
    pendingList, pendingLoading,
    inServiceList, inServiceLoading,
    billingDetail, billingLoading,
    verifikasi, verifLoading, verifSaving,
    klaims, klaimsLoading,
    selectedKlaim, klaimLoading, klaimSaving,
    klaimLogs, klaimLogsLoading,
    aging, agingLoading,
    summary, summaryLoading,
    docRequirements, docReqLoading,
    error,
    // getters
    overdueCount,
    // actions
    fetchPendingVerifications, fetchInServiceVerifications, fetchBilling,
    fetchVerifikasi, createVerifikasi, updateVerifikasi,
    fetchKlaims, fetchKlaim, createKlaim, updateKlaim, submitKlaim, updateStatusKlaim, resubmitKlaim, fetchLogs,
    fetchAging, fetchSummary,
    fetchDocRequirements, createDocRequirement, updateDocRequirement, deleteDocRequirement,
    clearSelected,
  }
})
