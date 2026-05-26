import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { queueApi } from '@/services/api'

const VALID_STATIONS = ['admisi', 'perawat', 'refraksi', 'dokter', 'penunjang', 'bedah', 'farmasi', 'kasir']

export const useQueueStore = defineStore('queue', () => {
  // ─── State ──────────────────────────────────────────────────────────────
  const queues          = ref([])         // antrian untuk station aktif
  const currentStation  = ref(null)       // station aktif user yang login
  const calledQueue     = ref(null)       // pasien yang sedang dipanggil
  const loading         = ref(false)
  const error           = ref(null)

  // ─── Getters ─────────────────────────────────────────────────────────────
  const waitingCount    = computed(() => queues.value.filter((q) => q.status === 'WAITING').length)
  const inProgressCount = computed(() => queues.value.filter((q) => q.status === 'IN_PROGRESS').length)
  const completedCount  = computed(() => queues.value.filter((q) => q.status === 'COMPLETED').length)

  const waitingList   = computed(() => queues.value.filter((q) => q.status === 'WAITING'))
  const activeQueue   = computed(() => queues.value.find((q) => q.status === 'IN_PROGRESS') ?? null)
  const nextInLine    = computed(() => waitingList.value[0] ?? null)

  // ─── Actions ─────────────────────────────────────────────────────────────

  function setStation(station) {
    if (!VALID_STATIONS.includes(station)) {
      console.warn(`[queueStore] invalid station: ${station}`)
      return
    }
    currentStation.value = station
  }

  async function fetchQueue(station = null) {
    const target = station ?? currentStation.value
    if (!target) return

    loading.value = true
    error.value   = null

    try {
      const { data } = await queueApi[target]()
      queues.value = data.data ?? []
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Gagal memuat antrian'
    } finally {
      loading.value = false
    }
  }

  async function panggilAntrian(queueId, station = null) {
    const target = station ?? currentStation.value
    if (!target) throw new Error('Station belum dipilih')

    loading.value = true

    try {
      const { data } = await queueApi.panggil(target, queueId)

      // Update local state
      const idx = queues.value.findIndex((q) => q.id === queueId)
      if (idx !== -1) {
        queues.value[idx] = data.data
      }

      calledQueue.value = data.data
      return data.data
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Gagal memanggil pasien'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function selesaiAntrian(queueId, station = null) {
    const target = station ?? currentStation.value
    if (!target) throw new Error('Station belum dipilih')

    loading.value = true

    try {
      const { data } = await queueApi.selesai(target, queueId)

      const idx = queues.value.findIndex((q) => q.id === queueId)
      if (idx !== -1) {
        queues.value[idx] = data.data
      }

      if (calledQueue.value?.id === queueId) {
        calledQueue.value = null
      }

      return data.data
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Gagal menyelesaikan antrian'
      throw err
    } finally {
      loading.value = false
    }
  }

  // Poll setiap 30 detik (bisa diganti WebSocket Reverb nanti)
  let _pollInterval = null

  function startPolling(station = null, intervalMs = 30_000) {
    stopPolling()
    fetchQueue(station)
    _pollInterval = setInterval(() => fetchQueue(station), intervalMs)
  }

  function stopPolling() {
    if (_pollInterval) {
      clearInterval(_pollInterval)
      _pollInterval = null
    }
  }

  function clearQueue() {
    queues.value   = []
    calledQueue.value = null
  }

  return {
    queues,
    currentStation,
    calledQueue,
    loading,
    error,
    waitingCount,
    inProgressCount,
    completedCount,
    waitingList,
    activeQueue,
    nextInLine,
    setStation,
    fetchQueue,
    panggilAntrian,
    selesaiAntrian,
    startPolling,
    stopPolling,
    clearQueue,
  }
})
