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

  // ─── Inbox hasil tak-tertaut (ingest alat gagal cocok otomatis) ─────────────
  const inbox        = ref([])
  const inboxLoading = ref(false)
  const assignable   = ref([])
  const inboxCount   = computed(() => inbox.value.length)

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

      // Re-link selectedQueue ke objek terbaru dari antrian (id sama) supaya:
      //  - selectedOrders & badge order di list memakai REFERENSI yang sama
      //    (list ↔ panel tidak lagi divergen), dan
      //  - status antrean pasien terpilih ikut ter-update lintas-poll.
      // Status order yang sudah lebih maju secara lokal (mis. baru saja IN_PROGRESS
      // via saveHasil tapi server poll belum mencerminkan) dipertahankan.
      if (selectedQueue.value) {
        const fresh = antrian.value.find((q) => q.id === selectedQueue.value.id)
        if (fresh) {
          _mergeOrderStatuses(selectedQueue.value, fresh)
          selectedQueue.value = fresh
        }
      }
    } catch (err) {
      queueError.value = err.response?.data?.message ?? 'Gagal memuat antrian penunjang'
    } finally {
      queueLoading.value = false
    }
  }

  // Pertahankan status order lokal yang lebih maju agar tidak "mundur" saat poll
  // server belum sinkron (REQUESTED < IN_PROGRESS < COMPLETED; CANCELLED final).
  const _ORDER_RANK = { REQUESTED: 0, IN_PROGRESS: 1, COMPLETED: 2, CANCELLED: 3 }
  function _mergeOrderStatuses(oldQ, freshQ) {
    const oldOrders = oldQ?.visit?.diagnostic_orders ?? []
    const newOrders = freshQ?.visit?.diagnostic_orders ?? []
    if (!oldOrders.length || !newOrders.length) return
    const oldById = new Map(oldOrders.map((o) => [o.id, o]))
    for (const o of newOrders) {
      const prev = oldById.get(o.id)
      if (!prev) continue
      const pr = _ORDER_RANK[prev.status] ?? 0
      const nr = _ORDER_RANK[o.status] ?? 0
      if (prev.status === 'CANCELLED' || (pr > nr && o.status !== 'CANCELLED')) {
        o.status = prev.status
      }
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
      let existing = resultsByOrderId.value[orderId]
      // Mesin (ingest bridge/watcher) bisa membuat row hasil SETELAH panel dibuka →
      // state lokal masih null. Re-cek ke server dulu agar storeHasil tak menabrak
      // "Hasil sudah ada" (422). updateResult mengabaikan field null → lampiran mesin aman.
      if (!existing) {
        try {
          const { data } = await penunjangApi.showHasil(orderId)
          if (data.data) existing = resultsByOrderId.value[orderId] = data.data
        } catch { /* abaikan — lanjut sebagai store baru */ }
      }
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
        const updated = { ...antrian.value[idx], status: 'COMPLETED' }
        antrian.value[idx] = updated
        // Jaga identitas referensi list ↔ panel (lihat fetchAntrian re-link).
        if (selectedQueue.value?.id === queueId) selectedQueue.value = updated
      } else if (selectedQueue.value?.id === queueId) {
        selectedQueue.value = { ...selectedQueue.value, status: 'COMPLETED' }
      }
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal menyelesaikan antrian')
    } finally {
      finalizing.value = false
    }
  }

  // ─── Inbox Actions ──────────────────────────────────────────────────────────
  async function fetchInbox(source = null) {
    inboxLoading.value = true
    try {
      const { data } = await penunjangApi.inbox(source ? { source } : {})
      inbox.value = data.data ?? []
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal memuat inbox')
    } finally {
      inboxLoading.value = false
    }
  }

  async function fetchAssignable(params = {}) {
    try {
      const { data } = await penunjangApi.inboxAssignable(params)
      assignable.value = data.data ?? []
      return assignable.value
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal memuat kandidat order')
    }
  }

  async function assignInboxItem(id, orderId) {
    try {
      await penunjangApi.assignInbox(id, orderId)
      inbox.value = inbox.value.filter((i) => i.id !== id)
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal menautkan hasil')
    }
  }

  async function discardInboxItem(id) {
    try {
      await penunjangApi.discardInbox(id)
      inbox.value = inbox.value.filter((i) => i.id !== id)
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal membuang item')
    }
  }

  // ─── Polling ────────────────────────────────────────────────────────────────
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
      // Pertahankan `visit` (beserta diagnostic_orders) yang sudah ter-load bila
      // payload server tidak menyertakannya, supaya panel order tidak hilang.
      const merged = { ...antrian.value[idx], ...updatedQueue }
      if (!updatedQueue.visit && antrian.value[idx].visit) merged.visit = antrian.value[idx].visit
      antrian.value[idx] = merged
      // Jaga identitas referensi list ↔ panel.
      if (selectedQueue.value?.id === updatedQueue.id) selectedQueue.value = merged
    } else if (selectedQueue.value?.id === updatedQueue.id) {
      const merged = { ...selectedQueue.value, ...updatedQueue }
      if (!updatedQueue.visit && selectedQueue.value.visit) merged.visit = selectedQueue.value.visit
      selectedQueue.value = merged
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
    inbox, inboxLoading, assignable,

    // getters
    belumDipanggilCount, selesaiCount, totalCount,
    selectedVisitId, selectedPatientId,
    selectedOrders, pendingOrdersCount, canFinalizeQueue,
    inboxCount,

    // actions — queue
    fetchAntrian, panggilAntrian, lewatiAntrian, selesaiAntrian,
    pickPatient, clearSelected,
    startPolling, stopPolling,

    // actions — order
    prosesOrder, cancelOrder,

    // actions — hasil
    uploadAttachment, saveHasil, finalizeHasil,

    // actions — inbox
    fetchInbox, fetchAssignable, assignInboxItem, discardInboxItem,
  }
})
