import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { refraksiApi } from '@/services/api'

export const useRefraksiStore = defineStore('refraksi', () => {

  // ─── Queue state ────────────────────────────────────────────────────────────
  const antrian       = ref([])
  const queueLoading  = ref(false)
  const queueError    = ref(null)

  // ─── Selected patient state ─────────────────────────────────────────────────
  const selectedQueue       = ref(null)
  const pemeriksaan         = ref(null)   // RefractionRecord existing
  const prescription        = ref(null)   // RefractionPrescription existing
  const pemeriksaanLoading  = ref(false)
  // Tiket Dokter (D-NNN) hasil finalize — diisi backend bila gate TR lolos.
  // Dipakai tombol "Cetak Tiket Dokter". Persist setelah clearSelected, direset saat pilih pasien.
  const doctorTicket        = ref(null)

  const saving      = ref(false)
  const finalizing  = ref(false)

  // ─── WebSocket / polling ────────────────────────────────────────────────────
  let _pusher  = null
  let _channel = null
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
  const selectedPatientId = computed(() => selectedQueue.value?.patient?.id ?? selectedQueue.value?.visit?.patient?.id ?? null)
  const isFinalized       = computed(() => pemeriksaan.value?.is_finalized ?? false)

  // ─── Queue Actions ──────────────────────────────────────────────────────────
  async function fetchAntrian() {
    queueLoading.value = true
    queueError.value   = null
    try {
      const { data } = await refraksiApi.antrian()
      antrian.value   = data.data ?? []
    } catch (err) {
      queueError.value = err.response?.data?.message ?? 'Gagal memuat antrean'
    } finally {
      queueLoading.value = false
    }
  }

  async function panggilAntrian(queueId) {
    const { data } = await refraksiApi.panggil(queueId)
    _updateQueueItem(data.data)
    return data.data
  }

  async function mulaiAntrian(queueId) {
    const { data } = await refraksiApi.mulai(queueId)
    _updateQueueItem(data.data)
    return data.data
  }

  async function lewatiAntrian(queueId) {
    const { data } = await refraksiApi.lewati(queueId)
    _updateQueueItem(data.data)
    // Reorder lokal: pindah baris ke akhir agar UI segera reflect server.
    const idx = antrian.value.findIndex((q) => q.id === queueId)
    if (idx !== -1) {
      const [row] = antrian.value.splice(idx, 1)
      antrian.value.push({ ...row, ...data.data })
    }
    return data.data
  }

  // ─── Patient Selection ──────────────────────────────────────────────────────
  async function pickPatient(queueItem) {
    selectedQueue.value = queueItem
    doctorTicket.value  = null   // reset tiket dari pasien sebelumnya

    if (queueItem.status === 'CALLED') {
      try { await mulaiAntrian(queueItem.id) } catch { /* ignore */ }
    }

    pemeriksaanLoading.value = true
    pemeriksaan.value  = null
    prescription.value = null
    try {
      if (queueItem.visit?.id) {
        const { data } = await refraksiApi.showPemeriksaan(queueItem.visit.id)
        pemeriksaan.value = data.data
        // Backend load('prescription') saat showPemeriksaan, jadi prescription
        // sudah ikut dalam payload kalau ada.
        prescription.value = data.data?.prescription ?? null
        // Repopulate tiket dokter bila pasien sudah finalized (cetak ulang). Backend
        // hanya menyertakan doctor_ticket bila gate TR lolos; null selama belum.
        doctorTicket.value = data.data?.doctor_ticket ?? null
      }
    } catch {
      pemeriksaan.value  = null
      prescription.value = null
    } finally {
      pemeriksaanLoading.value = false
    }
  }

  function clearSelected() {
    selectedQueue.value = null
    pemeriksaan.value   = null
    prescription.value  = null
  }

  // ─── Pemeriksaan Actions ────────────────────────────────────────────────────
  async function savePemeriksaan(formData) {
    saving.value = true
    try {
      let result
      if (pemeriksaan.value?.id) {
        const { data } = await refraksiApi.updatePemeriksaan(pemeriksaan.value.id, formData)
        result = data.data
      } else {
        const { data } = await refraksiApi.storePemeriksaan({
          visit_id: selectedVisitId.value,
          ...formData,
        })
        result = data.data
      }
      pemeriksaan.value = result
      return result
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal menyimpan pemeriksaan')
    } finally {
      saving.value = false
    }
  }

  /**
   * Simpan / update RefractionPrescription. Auto-create kalau belum ada
   * (memerlukan refraction_record_id, jadi pemeriksaan WAJIB sudah save dulu).
   */
  async function saveResep(formData) {
    if (!pemeriksaan.value?.id) {
      throw new Error('Simpan data refraksi terlebih dahulu sebelum resep.')
    }
    try {
      let result
      if (prescription.value?.id) {
        const { data } = await refraksiApi.updateResep(prescription.value.id, formData)
        result = data.data
      } else {
        const { data } = await refraksiApi.storeResep({
          refraction_record_id: pemeriksaan.value.id,
          ...formData,
        })
        result = data.data
      }
      prescription.value = result
      return result
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal menyimpan resep kacamata')
    }
  }

  async function finalizePemeriksaan() {
    if (!pemeriksaan.value?.id) throw new Error('Simpan pemeriksaan terlebih dahulu')
    finalizing.value = true
    try {
      const { data } = await refraksiApi.finalizePemeriksaan(pemeriksaan.value.id)
      pemeriksaan.value  = data.data
      doctorTicket.value = data.data?.doctor_ticket ?? null

      const idx = antrian.value.findIndex((q) => q.id === selectedQueue.value?.id)
      if (idx !== -1) {
        antrian.value[idx] = { ...antrian.value[idx], status: 'COMPLETED' }
      }
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal mengunci pemeriksaan')
    } finally {
      finalizing.value = false
    }
  }

  // ─── WebSocket (Reverb) + fallback polling ─────────────────────────────────
  function connectWs() {
    const appKey = import.meta.env.VITE_REVERB_APP_KEY
    if (!appKey) { startPolling(); return }

    try {
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
          // refraksi store hanya peduli rows station REFRAKSIONIS
          if (queue.station !== 'REFRAKSIONIS') return
          if (action === 'added') {
            antrian.value.push(queue)
          } else {
            _updateQueueItem(queue)
          }
        })

        _pusher.connection.bind('error', () => startPolling())
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
    selectedQueue, pemeriksaan, prescription, pemeriksaanLoading,
    doctorTicket,
    saving, finalizing,

    // getters
    belumDipanggilCount, selesaiCount, totalCount,
    selectedVisitId, selectedPatientId, isFinalized,

    // actions
    fetchAntrian, panggilAntrian, mulaiAntrian, lewatiAntrian,
    pickPatient, clearSelected,
    savePemeriksaan, saveResep, finalizePemeriksaan,

    // websocket
    connectWs, disconnectWs,
  }
})
