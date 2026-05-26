import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { penunjangApi } from '@/services/api'

export const usePenunjangStore = defineStore('penunjang', () => {

  // ─── Queue state ────────────────────────────────────────────────────────────
  const antrian       = ref([])
  const queueLoading  = ref(false)
  const queueError    = ref(null)

  // ─── Selected order/queue ───────────────────────────────────────────────────
  const selectedQueue = ref(null)

  // ─── Finalize state ─────────────────────────────────────────────────────────
  const finalizing    = ref(false)

  // ─── Polling fallback (PENUNJANG belum punya event Reverb dedicated) ────────
  let _pollInterval = null

  // ─── Getters ────────────────────────────────────────────────────────────────
  const belumDipanggilCount = computed(
    () => antrian.value.filter((q) => ['WAITING', 'CALLED', 'IN_PROGRESS'].includes(q.status)).length,
  )
  const selesaiCount = computed(
    () => antrian.value.filter((q) => q.status === 'COMPLETED').length,
  )
  const totalCount = computed(() => antrian.value.length)

  const selectedVisitId   = computed(() => selectedQueue.value?.visit?.id ?? null)
  const selectedPatientId = computed(() => selectedQueue.value?.visit?.patient?.id ?? null)

  // ─── Queue Actions ──────────────────────────────────────────────────────────
  async function fetchAntrian() {
    queueLoading.value = true
    queueError.value   = null
    try {
      const { data } = await penunjangApi.antrian()
      antrian.value   = data.data ?? []
    } catch (err) {
      queueError.value = err.response?.data?.message ?? 'Gagal memuat antrian penunjang'
    } finally {
      queueLoading.value = false
    }
  }

  async function panggilAntrian(queueId) {
    try {
      const { data } = await penunjangApi.panggilAntrian(queueId)
      _updateQueueItem(data.data)
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal memanggil pasien')
    }
  }

  function pickPatient(queueItem) {
    selectedQueue.value = queueItem
  }

  function clearSelected() {
    selectedQueue.value = null
  }

  /**
   * Selesai antrian penunjang → backend advance ke DOKTER untuk pembacaan hasil.
   */
  async function selesaiAntrian(queueId) {
    finalizing.value = true
    try {
      const { data } = await penunjangApi.selesaiAntrian(queueId)
      const idx = antrian.value.findIndex((q) => q.id === queueId)
      if (idx !== -1) {
        antrian.value[idx] = { ...antrian.value[idx], status: 'COMPLETED' }
      }
      if (selectedQueue.value?.id === queueId) {
        selectedQueue.value = { ...selectedQueue.value, status: 'COMPLETED' }
      }
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal menyelesaikan antrian')
    } finally {
      finalizing.value = false
    }
  }

  // ─── Polling ────────────────────────────────────────────────────────────────
  function startPolling(intervalMs = 30_000) {
    stopPolling()
    _pollInterval = setInterval(fetchAntrian, intervalMs)
  }

  function stopPolling() {
    if (_pollInterval) {
      clearInterval(_pollInterval)
      _pollInterval = null
    }
  }

  // ─── Private helpers ────────────────────────────────────────────────────────
  function _updateQueueItem(updatedQueue) {
    const idx = antrian.value.findIndex((q) => q.id === updatedQueue.id)
    if (idx !== -1) {
      antrian.value[idx] = { ...antrian.value[idx], ...updatedQueue }
    }
    if (selectedQueue.value?.id === updatedQueue.id) {
      selectedQueue.value = { ...selectedQueue.value, ...updatedQueue }
    }
  }

  return {
    // state
    antrian, queueLoading, queueError,
    selectedQueue, finalizing,

    // getters
    belumDipanggilCount, selesaiCount, totalCount,
    selectedVisitId, selectedPatientId,

    // actions
    fetchAntrian, panggilAntrian, selesaiAntrian,
    pickPatient, clearSelected,
    startPolling, stopPolling,
  }
})
