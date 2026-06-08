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
    // Server hanya menukar 1 posisi (queue_sequence) — re-fetch agar urutan UI
    // sinkron dengan server (bukan reorder lokal ke bawah).
    await fetchAntrian()
    return data.data
  }

  // Lewati Refraksi (pasien tidak perlu refraksi) — antrean tetap jalan ke Dokter.
  const skipping = ref(false)
  async function skipRefraksi(queueId) {
    skipping.value = true
    try {
      const { data } = await refraksiApi.skipRefraksi(queueId)
      await fetchAntrian()
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal melewati refraksi')
    } finally {
      skipping.value = false
    }
  }

  // ─── Patient Selection ──────────────────────────────────────────────────────
  async function pickPatient(queueItem) {
    selectedQueue.value = queueItem
    doctorTicket.value  = null   // reset tiket dari pasien sebelumnya

    if (queueItem.status === 'CALLED') {
      try {
        // mulaiAntrian hanya menyetel ulang baris LIST (_updateQueueItem) — sinkronkan
        // juga selectedQueue supaya status pasien terpilih tidak basi ('CALLED' →
        // 'IN_PROGRESS'), agar tombol aksi/badge konsisten dengan backend.
        const updated = await mulaiAntrian(queueItem.id)
        if (updated && selectedQueue.value?.id === queueItem.id) {
          selectedQueue.value = { ...selectedQueue.value, ...updated }
        }
      } catch {
        // Auto-mulai gagal (status sudah berubah di server / dipanggil user lain) →
        // resync papan supaya status lokal tidak menggantung di 'CALLED'.
        await fetchAntrian().catch(() => {})
      }
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

  async function finalizePemeriksaan(pin) {
    if (!pemeriksaan.value?.id) throw new Error('Simpan pemeriksaan terlebih dahulu')
    finalizing.value = true
    try {
      const { data } = await refraksiApi.finalizePemeriksaan(pemeriksaan.value.id, pin)
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

  // Buka kunci (periksa ulang) — is_finalized=false → form ter-unlock; antrean REFRAKSIONIS
  // dibuka kembali (WAITING) sehingga pasien bisa di-Panggil & direvisi lalu finalisasi ulang.
  async function reopenPemeriksaan() {
    if (!pemeriksaan.value?.id) throw new Error('Belum ada data refraksi')
    finalizing.value = true
    try {
      const { data } = await refraksiApi.reopenPemeriksaan(pemeriksaan.value.id)
      pemeriksaan.value  = data.data
      doctorTicket.value = null
      await fetchAntrian()
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal membuka kunci pemeriksaan')
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
          // Stasiun pasangan TRIASE memanggil/melepas pasien → refresh agar badge
          // "sedang di Triase" + status tombol Panggil sinkron realtime (cegah panggil-ganda).
          if (queue.station === 'TRIASE') {
            if (antrian.value.some((q) => q.visit?.id === queue.visit_id)) fetchAntrian()
            return
          }
          // refraksi store hanya peduli rows station REFRAKSIONIS
          if (queue.station !== 'REFRAKSIONIS') return
          if (action === 'added') {
            // Payload broadcast TIPIS (tanpa relasi visit lengkap: id/nurse_assessment/
            // doctor_schedule). Kalau di-push langsung, baris baru tak punya visit.id →
            // pasien tak bisa dipilih/diproses (selectedVisitId null → save 422) & data
            // triase/DPJP tak tampil. Re-fetch daftar penuh agar baris lengkap.
            // De-dup: WS bisa pancar 'added' >1x (retry/race) → fetch hanya bila baru.
            if (! antrian.value.some((q) => q.id === queue.id)) fetchAntrian()
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
      antrian.value[idx] = _mergeQueueItem(antrian.value[idx], updatedQueue)
    }
    if (selectedQueue.value?.id === updatedQueue.id) {
      selectedQueue.value = _mergeQueueItem(selectedQueue.value, updatedQueue)
    }
  }

  // Merge baris antrean TANPA menimpa relasi bersarang dengan versi tipis.
  // Respons panggil/mulai (QueueService::panggil/mulai) hanya memuat visit.patient,
  // jadi nurse_assessment/doctor_schedule/guarantor_type ikut HILANG kalau `visit`
  // ditimpa mentah → badge ⚠Alergi & Triase✓, label BPJS, dan baris DPJP lenyap dari
  // kartu sampai fetch berikutnya. Gabungkan `visit` & `patient` secara dangkal per-objek.
  function _mergeQueueItem(existing, incoming) {
    if (!existing) return incoming
    return {
      ...existing,
      ...incoming,
      visit:   incoming.visit   ? { ...existing.visit, ...incoming.visit }     : existing.visit,
      patient: incoming.patient ? { ...existing.patient, ...incoming.patient } : existing.patient,
    }
  }

  return {
    // state
    antrian, queueLoading, queueError,
    selectedQueue, pemeriksaan, prescription, pemeriksaanLoading,
    doctorTicket,
    saving, finalizing, skipping,

    // getters
    belumDipanggilCount, selesaiCount, totalCount,
    selectedVisitId, selectedPatientId, isFinalized,

    // actions
    fetchAntrian, panggilAntrian, mulaiAntrian, lewatiAntrian, skipRefraksi,
    pickPatient, clearSelected,
    savePemeriksaan, saveResep, finalizePemeriksaan, reopenPemeriksaan,

    // websocket
    connectWs, disconnectWs,
  }
})
