import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { perawatApi } from '@/services/api'

export const usePerawatStore = defineStore('perawat', () => {

  // ─── Queue state ────────────────────────────────────────────────────────────
  const antrian       = ref([])
  const stats         = ref({ belum_dipanggil: 0, selesai: 0, total: 0 })
  const queueLoading  = ref(false)
  const queueError    = ref(null)

  // ─── Selected patient state ──────────────────────────────────────────────────
  const selectedQueue = ref(null)   // full queue item from list
  const asesmen       = ref(null)   // existing NurseAssessment or null
  const asesmenLoading = ref(false)
  // Tiket Dokter (D-NNN) hasil finalize — diisi backend bila gate TR lolos.
  // Dipakai tombol "Cetak Tiket Dokter". Persist setelah clearSelected, direset saat pilih pasien.
  const doctorTicket   = ref(null)

  // ─── Assessment form save/finalize state ────────────────────────────────────
  const saving      = ref(false)
  const finalizing  = ref(false)

  // ─── Vital History ───────────────────────────────────────────────────────────
  const vitalHistory        = ref([])
  const vitalHistoryLoading = ref(false)

  // ─── CPPT (Catatan Perkembangan Pasien Terintegrasi) ─────────────────────────
  const cpptEntries = ref([])
  const cpptLoading = ref(false)
  const cpptSaving  = ref(false)

  // ─── Rekam Medis Modal ───────────────────────────────────────────────────────
  const rekamMedis        = ref({ vital_history: [], documents: [] })
  const rekamMedisLoading = ref(false)
  const rekamMedisError   = ref(null)
  const selectedDokumen   = ref(null)
  const dokumenLoading    = ref(false)

  // ─── WebSocket ───────────────────────────────────────────────────────────────
  let _pusher  = null
  let _channel = null
  let _pollInterval = null

  // ─── Getters ─────────────────────────────────────────────────────────────────
  const belumDipanggilCount = computed(
    () => antrian.value.filter((q) => ['WAITING', 'CALLED', 'IN_PROGRESS'].includes(q.status)).length,
  )
  const selesaiCount = computed(
    () => antrian.value.filter((q) => q.status === 'COMPLETED').length,
  )
  const totalCount = computed(() => antrian.value.length)

  const selectedVisitId  = computed(() => selectedQueue.value?.visit?.id ?? null)
  const selectedPatientId = computed(() => selectedQueue.value?.patient?.id ?? null)
  const isFinalized      = computed(() => asesmen.value?.is_finalized ?? false)

  // ─── Queue Actions ────────────────────────────────────────────────────────────

  async function fetchAntrian() {
    queueLoading.value = true
    queueError.value   = null
    try {
      const { data } = await perawatApi.antrian()
      const payload   = data.data
      antrian.value   = payload.queues ?? []
      stats.value     = payload.stats ?? { belum_dipanggil: 0, selesai: 0, total: 0 }
    } catch (err) {
      queueError.value = err.response?.data?.message ?? 'Gagal memuat antrean'
    } finally {
      queueLoading.value = false
    }
  }

  async function panggilAntrian(queueId) {
    try {
      const { data } = await perawatApi.panggil(queueId)
      _updateQueueItem(data.data)
      _syncStats()
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal memanggil pasien')
    }
  }

  async function lewatiAntrian(queueId) {
    try {
      const { data } = await perawatApi.lewati(queueId)
      _updateQueueItem(data.data)
      _syncStats()
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal melewati pasien')
    }
  }

  // ─── Patient Selection ────────────────────────────────────────────────────────

  async function pickPatient(queueItem) {
    selectedQueue.value = queueItem
    doctorTicket.value  = null   // reset tiket dari pasien sebelumnya

    // CALLED → IN_PROGRESS via /mulai endpoint (bukan /selesai — itu finalize+advance)
    if (queueItem.status === 'CALLED') {
      await _mulaiQuiet(queueItem.id)
    }

    asesmenLoading.value = true
    asesmen.value = null
    try {
      if (queueItem.visit?.id) {
        const { data } = await perawatApi.showAsesmen(queueItem.visit.id)
        asesmen.value      = data.data
        // Repopulate tiket dokter bila pasien sudah finalized (cetak ulang). Backend
        // hanya menyertakan doctor_ticket bila gate TR lolos; null selama belum.
        doctorTicket.value = data.data?.doctor_ticket ?? null
      }
    } catch {
      asesmen.value = null
    } finally {
      asesmenLoading.value = false
    }

    // Load vital history in background
    if (queueItem.patient?.id) {
      loadVitalHistory(queueItem.patient.id)
    }

    // Load CPPT timeline untuk visit ini
    if (queueItem.visit?.id) {
      loadCpptTimeline(queueItem.visit.id)
    }
  }

  // PUT /perawat/antrian/{id}/mulai — silent (kalau gagal karena status sudah
  // IN_PROGRESS, anggap sukses; backend memang reject kalau bukan CALLED).
  async function _mulaiQuiet(queueId) {
    try {
      const { data } = await perawatApi.mulai(queueId)
      _updateQueueItem(data.data)
      if (selectedQueue.value?.id === queueId) {
        selectedQueue.value = { ...selectedQueue.value, ...data.data }
      }
    } catch { /* already in progress — silently ignore */ }
  }

  function clearSelected() {
    selectedQueue.value = null
    asesmen.value = null
    vitalHistory.value = []
    cpptEntries.value = []
  }

  // ─── Assessment Actions ───────────────────────────────────────────────────────

  async function saveAsesmen(formData) {
    saving.value = true
    try {
      let result
      if (asesmen.value?.id) {
        const { data } = await perawatApi.updateAsesmen(asesmen.value.id, formData)
        result = data.data
      } else {
        const { data } = await perawatApi.storeAsesmen({
          visit_id: selectedVisitId.value,
          ...formData,
        })
        result = data.data
      }
      asesmen.value = result
      return result
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal menyimpan asesmen')
    } finally {
      saving.value = false
    }
  }

  async function finalizeAsesmen() {
    if (!asesmen.value?.id) throw new Error('Simpan asesmen terlebih dahulu')
    finalizing.value = true
    try {
      const { data } = await perawatApi.finalizeAsesmen(asesmen.value.id)
      asesmen.value      = data.data
      doctorTicket.value = data.data?.doctor_ticket ?? null

      // Mark queue COMPLETED locally
      const idx = antrian.value.findIndex((q) => q.id === selectedQueue.value?.id)
      if (idx !== -1) {
        antrian.value[idx] = { ...antrian.value[idx], status: 'COMPLETED' }
      }
      _syncStats()
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal mengunci asesmen')
    } finally {
      finalizing.value = false
    }
  }

  // ─── PREOP_BEDAH — Kirim ke Bedah (manual) ──────────────────────────────────
  const parallelStatus = ref(null) // {triase_done, refraksi_done, ...}

  async function loadParallelStatus(visitId) {
    if (!visitId) { parallelStatus.value = null; return }
    try {
      const { data } = await perawatApi.statusParallel(visitId)
      parallelStatus.value = data.data ?? null
    } catch {
      parallelStatus.value = null
    }
  }

  const sendingBedah = ref(false)
  async function kirimKeBedah() {
    const queueId = selectedQueue.value?.id
    if (!queueId) throw new Error('Pilih pasien terlebih dahulu')
    sendingBedah.value = true
    try {
      const { data } = await perawatApi.kirimKeBedah(queueId)
      // Tandai queue triase COMPLETED di list lokal
      const idx = antrian.value.findIndex((q) => q.id === queueId)
      if (idx !== -1) {
        antrian.value[idx] = { ...antrian.value[idx], status: 'COMPLETED' }
      }
      _syncStats()
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal mengirim pasien ke bedah')
    } finally {
      sendingBedah.value = false
    }
  }

  // ─── PREOP_BEDAH + inap (Fase 8B) — Kirim ke Rawat Inap (papan Menunggu Kamar) ──
  const sendingRanap = ref(false)
  async function kirimKeRanap() {
    const queueId = selectedQueue.value?.id
    if (!queueId) throw new Error('Pilih pasien terlebih dahulu')
    sendingRanap.value = true
    try {
      const { data } = await perawatApi.kirimKeRanap(queueId)
      // Tandai queue triase COMPLETED di list lokal (sama seperti kirimKeBedah).
      const idx = antrian.value.findIndex((q) => q.id === queueId)
      if (idx !== -1) {
        antrian.value[idx] = { ...antrian.value[idx], status: 'COMPLETED' }
      }
      _syncStats()
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal mengirim pasien ke rawat inap')
    } finally {
      sendingRanap.value = false
    }
  }

  // ─── Vital History ────────────────────────────────────────────────────────────

  async function loadVitalHistory(patientId) {
    vitalHistoryLoading.value = true
    try {
      const { data } = await perawatApi.vitalHistory(patientId)
      vitalHistory.value = data.data ?? []
    } catch {
      vitalHistory.value = []
    } finally {
      vitalHistoryLoading.value = false
    }
  }

  // ─── CPPT Actions ─────────────────────────────────────────────────────────────

  async function loadCpptTimeline(visitId) {
    cpptLoading.value = true
    try {
      const { data } = await perawatApi.cpptList(visitId)
      cpptEntries.value = data.data ?? []
    } catch {
      cpptEntries.value = []
    } finally {
      cpptLoading.value = false
    }
  }

  async function addCpptEntry(payload) {
    cpptSaving.value = true
    try {
      const { data } = await perawatApi.cpptCreate({
        visit_id: selectedVisitId.value,
        ...payload,
      })
      // Prepend ke timeline (descending = terbaru di atas)
      cpptEntries.value = [data.data, ...cpptEntries.value]
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal menambahkan CPPT')
    } finally {
      cpptSaving.value = false
    }
  }

  async function updateCpptEntry(id, payload) {
    cpptSaving.value = true
    try {
      const { data } = await perawatApi.cpptUpdate(id, payload)
      const idx = cpptEntries.value.findIndex((e) => e.id === id)
      if (idx !== -1) cpptEntries.value[idx] = data.data
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal memperbarui CPPT')
    } finally {
      cpptSaving.value = false
    }
  }

  // ─── Rekam Medis ──────────────────────────────────────────────────────────────

  async function loadRekamMedis(patientId) {
    rekamMedisLoading.value = true
    rekamMedisError.value   = null
    try {
      const { data } = await perawatApi.rekamMedis(patientId)
      rekamMedis.value = data.data ?? { vital_history: [], documents: [] }
    } catch (err) {
      rekamMedisError.value = err.response?.data?.message ?? 'Gagal memuat rekam medis'
    } finally {
      rekamMedisLoading.value = false
    }
  }

  async function loadDokumen(documentId) {
    dokumenLoading.value = true
    selectedDokumen.value = null
    try {
      const { data } = await perawatApi.dokumen(documentId)
      selectedDokumen.value = data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal memuat dokumen')
    } finally {
      dokumenLoading.value = false
    }
  }

  // ─── WebSocket (Laravel Reverb / Pusher protocol) ────────────────────────────

  function connectWs() {
    const appKey = import.meta.env.VITE_REVERB_APP_KEY
    if (!appKey) {
      // No key configured — fall back to polling
      startPolling()
      return
    }

    try {
      // Dynamic import keeps pusher-js out of initial bundle
      import('pusher-js').then(({ default: Pusher }) => {
        _pusher = new Pusher(appKey, {
          wsHost:            import.meta.env.VITE_REVERB_HOST ?? 'localhost',
          wsPort:            Number(import.meta.env.VITE_REVERB_PORT ?? 6001),
          wssPort:           Number(import.meta.env.VITE_REVERB_PORT ?? 6001),
          forceTLS:          (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
          enabledTransports: ['ws', 'wss'],
          disableStats:      true,
        })

        _channel = _pusher.subscribe('triase-queue')
        _channel.bind('queue-updated', ({ action, queue }) => {
          if (action === 'added') {
            antrian.value.push(queue)
          } else {
            _updateQueueItem(queue)
          }
          _syncStats()
        })

        _pusher.connection.bind('error', () => {
          // Reverb not reachable — fall back to polling
          startPolling()
        })
      })
    } catch {
      startPolling()
    }
  }

  function disconnectWs() {
    _channel?.unbind_all()
    _pusher?.disconnect()
    _pusher  = null
    _channel = null
    stopPolling()
  }

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

  // ─── Private helpers ──────────────────────────────────────────────────────────

  function _updateQueueItem(updatedQueue) {
    const idx = antrian.value.findIndex((q) => q.id === updatedQueue.id)
    if (idx !== -1) {
      antrian.value[idx] = { ...antrian.value[idx], ...updatedQueue }
    }
    if (selectedQueue.value?.id === updatedQueue.id) {
      selectedQueue.value = { ...selectedQueue.value, ...updatedQueue }
    }
  }

  function _syncStats() {
    stats.value = {
      belum_dipanggil: belumDipanggilCount.value,
      selesai:         selesaiCount.value,
      total:           totalCount.value,
    }
  }

  return {
    // state
    antrian, stats, queueLoading, queueError,
    selectedQueue, asesmen, asesmenLoading,
    doctorTicket,
    saving, finalizing, sendingBedah, sendingRanap,
    vitalHistory, vitalHistoryLoading,
    rekamMedis, rekamMedisLoading, rekamMedisError,
    selectedDokumen, dokumenLoading,
    cpptEntries, cpptLoading, cpptSaving,

    // getters
    belumDipanggilCount, selesaiCount, totalCount,
    selectedVisitId, selectedPatientId, isFinalized,

    // queue actions
    fetchAntrian, panggilAntrian, lewatiAntrian,

    // patient selection
    pickPatient, clearSelected,

    // assessment
    saveAsesmen, finalizeAsesmen,

    // preop bedah
    kirimKeBedah, kirimKeRanap, parallelStatus, loadParallelStatus,

    // cppt
    loadCpptTimeline, addCpptEntry, updateCpptEntry,

    // vital history & rekam medis
    loadVitalHistory, loadRekamMedis, loadDokumen,

    // websocket
    connectWs, disconnectWs,
  }
})
