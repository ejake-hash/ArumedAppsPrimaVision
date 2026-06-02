import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { dokterApi } from '@/services/api'

export const useDokterStore = defineStore('dokter', () => {

  // ─── Queue state ────────────────────────────────────────────────────────────
  const antrian       = ref([])
  const queueLoading  = ref(false)
  const queueError    = ref(null)

  // ─── Selected patient state ─────────────────────────────────────────────────
  const selectedQueue = ref(null)

  // ─── Finalize state ─────────────────────────────────────────────────────────
  const finalizing    = ref(false)

  // ─── Polling fallback ───────────────────────────────────────────────────────
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
      const { data } = await dokterApi.antrian()
      antrian.value   = data.data ?? []
    } catch (err) {
      queueError.value = err.response?.data?.message ?? 'Gagal memuat antrian dokter'
    } finally {
      queueLoading.value = false
    }
  }

  async function panggilAntrian(queueId) {
    try {
      const { data } = await dokterApi.panggil(queueId)
      _updateQueueItem(data.data)
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal memanggil pasien')
    }
  }

  async function lewatiAntrian(queueId) {
    try {
      const { data } = await dokterApi.lewati(queueId)
      _updateQueueItem(data.data)
      // Server menukar queue_sequence 2 baris — refetch agar urutan UI sinkron
      // (bukan reorder lokal yang dibuang polling). Pola sama: refraksiStore.
      await fetchAntrian()
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal melewati pasien')
    }
  }

  // Kirim pasien ke pemeriksaan penunjang (baris DOKTER di-pause + turun ke bawah)
  async function kirimKePenunjang(queueId) {
    try {
      const { data } = await dokterApi.kePenunjang(queueId)
      _updateQueueItem(data.data)
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal mengirim ke penunjang')
    }
  }

  // ─── Patient Selection ──────────────────────────────────────────────────────
  function pickPatient(queueItem) {
    selectedQueue.value = queueItem
  }

  function clearSelected() {
    selectedQueue.value = null
  }

  // ─── Finalize visit (selesaikan pemeriksaan dokter) ─────────────────────────
  async function selesaiAntrian(queueId) {
    finalizing.value = true
    try {
      const { data } = await dokterApi.selesai(queueId)
      // Backend mark queue COMPLETED + advance ke station berikutnya.
      // Update local store secara optimistic.
      const idx = antrian.value.findIndex((q) => q.id === queueId)
      if (idx !== -1) {
        antrian.value[idx] = { ...antrian.value[idx], status: 'COMPLETED' }
      }
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal menyelesaikan antrian')
    } finally {
      finalizing.value = false
    }
  }

  // ─── Polling (Reverb belum broadcast untuk DOKTER per arsitektur saat ini) ──
  function startPolling(intervalMs = 8_000) {
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
    fetchAntrian, panggilAntrian, lewatiAntrian, selesaiAntrian, kirimKePenunjang,
    pickPatient, clearSelected,
    startPolling, stopPolling,
  }
})
