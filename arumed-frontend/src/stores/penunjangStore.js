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

  // ─── Per-order working state ────────────────────────────────────────────────
  // resultsByOrderId: { [orderId]: DiagnosticResult | null }
  // hasilSaving: set of order_id yang sedang disubmit
  const resultsByOrderId = ref({})
  const hasilSaving      = ref(new Set())

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

  async function lewatiAntrian(queueId) {
    try {
      const { data } = await penunjangApi.lewatiAntrian(queueId)
      _updateQueueItem(data.data)
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal melewati pasien')
    }
  }

  async function pickPatient(queueItem) {
    selectedQueue.value = queueItem
    // Auto-load hasil semua order existing untuk visit ini.
    const orders = queueItem.visit?.diagnostic_orders ?? []
    resultsByOrderId.value = {}
    await Promise.all(
      orders.map(async (o) => {
        try {
          const { data } = await penunjangApi.showHasil(o.id)
          resultsByOrderId.value[o.id] = data.data ?? null
        } catch {
          resultsByOrderId.value[o.id] = null
        }
      }),
    )
  }

  function clearSelected() {
    selectedQueue.value = null
    resultsByOrderId.value = {}
  }

  // ─── Order Actions ──────────────────────────────────────────────────────────
  async function prosesOrder(orderId) {
    try {
      const { data } = await penunjangApi.prosesOrder(orderId)
      _patchOrderInSelected(orderId, { status: data.data.status })
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal memproses order')
    }
  }

  async function cancelOrder(orderId) {
    try {
      await penunjangApi.cancelOrder(orderId)
      _patchOrderInSelected(orderId, { status: 'CANCELLED' })
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal membatalkan order')
    }
  }

  // ─── Hasil Actions ──────────────────────────────────────────────────────────
  async function uploadAttachment(file) {
    try {
      const { data } = await penunjangApi.uploadHasilAttachment(file)
      return data.data // { path, url }
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal mengunggah file')
    }
  }

  async function saveHasil(orderId, payload) {
    hasilSaving.value.add(orderId)
    try {
      const existing = resultsByOrderId.value[orderId]
      const { data } = existing
        ? await penunjangApi.updateHasil(existing.id, payload)
        : await penunjangApi.storeHasil({ diagnostic_order_id: orderId, ...payload })
      resultsByOrderId.value[orderId] = data.data
      _patchOrderInSelected(orderId, { status: 'IN_PROGRESS' })
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal menyimpan hasil')
    } finally {
      hasilSaving.value.delete(orderId)
    }
  }

  async function finalizeHasil(orderId) {
    const existing = resultsByOrderId.value[orderId]
    if (!existing) throw new Error('Belum ada hasil tersimpan untuk di-finalize')
    hasilSaving.value.add(orderId)
    try {
      const { data } = await penunjangApi.selesaiHasil(existing.id)
      resultsByOrderId.value[orderId] = data.data
      _patchOrderInSelected(orderId, { status: 'COMPLETED' })
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal menyelesaikan hasil')
    } finally {
      hasilSaving.value.delete(orderId)
    }
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

  function _patchOrderInSelected(orderId, patch) {
    if (!selectedQueue.value?.visit?.diagnostic_orders) return
    const orders = selectedQueue.value.visit.diagnostic_orders
    const idx = orders.findIndex((o) => o.id === orderId)
    if (idx !== -1) {
      orders[idx] = { ...orders[idx], ...patch }
    }
  }

  // ─── Getters: order summary ─────────────────────────────────────────────────
  const selectedOrders = computed(() => selectedQueue.value?.visit?.diagnostic_orders ?? [])
  const pendingOrdersCount = computed(
    () => selectedOrders.value.filter((o) => ['REQUESTED', 'IN_PROGRESS'].includes(o.status)).length,
  )
  const canFinalizeQueue = computed(
    () => selectedOrders.value.length > 0 && pendingOrdersCount.value === 0,
  )

  return {
    // state
    antrian, queueLoading, queueError,
    selectedQueue, finalizing,
    resultsByOrderId, hasilSaving,

    // getters
    belumDipanggilCount, selesaiCount, totalCount,
    selectedVisitId, selectedPatientId,
    selectedOrders, pendingOrdersCount, canFinalizeQueue,

    // actions — queue
    fetchAntrian, panggilAntrian, lewatiAntrian, selesaiAntrian,
    pickPatient, clearSelected,
    startPolling, stopPolling,

    // actions — order
    prosesOrder, cancelOrder,

    // actions — hasil
    uploadAttachment, saveHasil, finalizeHasil,
  }
})
