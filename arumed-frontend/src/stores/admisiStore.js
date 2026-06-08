import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { admisiApi, masterApi, perawatApi } from '@/services/api'

export const useAdmisiStore = defineStore('admisi', () => {

  // ─── Dashboard / Stats ───────────────────────────────────────────────────────
  const stats = ref({ total: 0, bpjs: 0, asuransi: 0, bedah: 0, ranap: 0, sep: 0, cancel: 0, waiting: 0, triage: 0, doctor: 0, done: 0 })
  const bpjsStatus = ref([])
  const dashboardLoading = ref(false)
  const dashboardError = ref(null)

  // ─── Insurers (for registration form) ────────────────────────────────────────
  const insurers = ref([])
  const insurersLoading = ref(false)

  // ─── Antrian (callable queue) ─────────────────────────────────────────────────
  const antrian = ref([])
  const antrianLoading = ref(false)
  const antrianError = ref(null)

  // ─── Visits (accordion table) — server-side filter + pagination ────────────────
  const visits = ref([])
  const visitsMeta = ref({ total: 0, current_page: 1, last_page: 1 })
  const visitsLoading = ref(false)
  const visitsError = ref(null)
  const visitsFilter = ref({ station: 'SEMUA', search: '', guarantor: '', unfinished: false, careType: 'RAJAL' })
  const visitsPerPage = ref(15)

  // ─── Visit Detail Modal ───────────────────────────────────────────────────────
  const selectedVisit = ref(null)
  const visitDetailLoading = ref(false)

  // ─── Rekam Medis Modal ────────────────────────────────────────────────────────
  const rekamMedisData = ref(null)
  const rekamMedisLoading = ref(false)
  const rekamMedisError = ref(null)

  // ─── Consent ─────────────────────────────────────────────────────────────────
  const consentLoading = ref(false)

  // ─── WebSocket ────────────────────────────────────────────────────────────────
  let _pusher = null
  let _channel = null
  let _pollInterval = null

  // ─── Computed ─────────────────────────────────────────────────────────────────
  const antrianCount = computed(() => antrian.value.length)

  const waitingCount = computed(() =>
    antrian.value.filter((q) => q.status === 'WAITING').length,
  )

  const selesaiCount = computed(() =>
    visits.value.filter((v) => v.current_station === 'SELESAI').length,
  )

  // ─── Actions: Dashboard ───────────────────────────────────────────────────────

  async function fetchDashboard() {
    dashboardLoading.value = true
    dashboardError.value = null
    try {
      const { data } = await admisiApi.dashboard()
      const payload = data.data
      const sc = payload.stat_cards ?? {}
      const ps = sc.per_station ?? {}
      stats.value = {
        total:    sc.total_kunjungan ?? 0,
        bpjs:     sc.bpjs_count      ?? 0,
        umum:     sc.umum_count      ?? 0,
        asuransi: sc.asuransi_count  ?? 0,
        bedah:    sc.bedah_count     ?? 0,
        ranap:    sc.ranap_count     ?? 0,
        sep:      sc.sep_count       ?? 0,
        cancel:   sc.cancel_count    ?? 0,
        waiting:  sc.antrian_aktif   ?? 0,
        triage:   (ps.TRIASE ?? 0) + (ps.REFRAKSIONIS ?? 0),
        doctor:   ps.DOKTER          ?? 0,
        done:     sc.selesai         ?? 0,
      }
      bpjsStatus.value = payload.bpjs_status ?? []
    } catch (err) {
      dashboardError.value = err.response?.data?.message ?? 'Gagal memuat dashboard'
    } finally {
      dashboardLoading.value = false
    }
  }

  // ─── Actions: Antrian ─────────────────────────────────────────────────────────

  async function fetchAntrian() {
    antrianLoading.value = true
    antrianError.value = null
    try {
      const { data } = await admisiApi.antrian()
      antrian.value = data.data ?? []
    } catch (err) {
      antrianError.value = err.response?.data?.message ?? 'Gagal memuat antrian'
    } finally {
      antrianLoading.value = false
    }
  }

  async function panggilAntrian(id) {
    try {
      const { data } = await admisiApi.panggil(id)
      _updateAntrianItem(data.data)
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal memanggil pasien')
    }
  }

  async function selesaiAntrian(id) {
    try {
      const { data } = await admisiApi.selesai(id)
      _updateAntrianItem(data.data?.queues?.[0] ?? data.data)
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal menyelesaikan admisi')
    }
  }

  // ─── Actions: Visits ──────────────────────────────────────────────────────────

  // overrides._silent = true → refresh latar belakang (WS/polling) tanpa
  // memunculkan indikator loading / flicker pager.
  async function fetchVisits(overrides = {}) {
    const { _silent = false, ...rest } = overrides
    if (!_silent) visitsLoading.value = true
    visitsError.value = null
    try {
      const params = {
        per_page: visitsPerPage.value,
        page: 1,
        ...rest,
      }
      // Mode "Belum Selesai (semua tgl)" → kirim unfinished, JANGAN kirim tanggal
      // (backend tampilkan semua visit aktif lintas-hari). Mode normal = per hari ini.
      if (visitsFilter.value.unfinished) {
        params.unfinished = 1
      } else if (!('tanggal' in params)) {
        params.tanggal = new Date().toISOString().split('T')[0]
      }
      if (visitsFilter.value.station && visitsFilter.value.station !== 'SEMUA') {
        params.station = visitsFilter.value.station
      }
      if (visitsFilter.value.search) params.search = visitsFilter.value.search
      if (visitsFilter.value.guarantor) params.guarantor_type = visitsFilter.value.guarantor
      // Pisah Rawat Jalan (RAJAL/IGD) vs Rawat Inap (RANAP). 'SEMUA' → tak dikirim.
      if (visitsFilter.value.careType && visitsFilter.value.careType !== 'SEMUA') {
        params.care_type = visitsFilter.value.careType
      }

      const { data } = await admisiApi.kunjungan(params)
      const payload = data.data
      visits.value = payload.data ?? []
      visitsMeta.value = {
        total:        payload.total ?? 0,
        current_page: payload.current_page ?? 1,
        last_page:    payload.last_page ?? 1,
      }
    } catch (err) {
      visitsError.value = err.response?.data?.message ?? 'Gagal memuat kunjungan'
    } finally {
      if (!_silent) visitsLoading.value = false
    }
  }

  // Refresh diam-diam halaman kunjungan yang sedang aktif (untuk WS/polling),
  // di-debounce agar burst event tidak memicu banyak request.
  let _visitsRefreshTimer = null
  function scheduleVisitsRefresh() {
    clearTimeout(_visitsRefreshTimer)
    _visitsRefreshTimer = setTimeout(() => {
      fetchVisits({ _silent: true, page: visitsMeta.value.current_page || 1 })
    }, 500)
  }

  async function fetchVisitDetail(id) {
    visitDetailLoading.value = true
    selectedVisit.value = null
    try {
      const { data } = await admisiApi.kunjunganById(id)
      selectedVisit.value = data.data
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal memuat detail kunjungan')
    } finally {
      visitDetailLoading.value = false
    }
  }

  async function cancelKunjungan(visitId) {
    const removeFromLocal = () => {
      antrian.value = antrian.value.filter((q) => q.visit_id !== visitId)
      visits.value  = visits.value.filter((v) => v.id !== visitId)
    }
    try {
      await admisiApi.cancelKunjungan(visitId)
      removeFromLocal()
    } catch (err) {
      // Visit sudah trashed (404) — anggap sukses & bersihkan list lokal
      if (err.response?.status === 404) {
        removeFromLocal()
        return
      }
      throw new Error(err.response?.data?.message ?? 'Gagal membatalkan kunjungan')
    }
  }

  // Ubah penjamin / tipe kunjungan (sekaligus pola bayar). Backend menolak bila
  // billing sudah dikomit. Return visit terbaru; caller boleh refetch list.
  async function updatePenjamin(visitId, payload) {
    try {
      const { data } = await admisiApi.updatePenjamin(visitId, payload)
      return data.data
    } catch (err) {
      const e = new Error(err.response?.data?.message ?? 'Gagal mengubah penjamin')
      e.errors = err.response?.data?.errors ?? null
      throw e
    }
  }

  // ─── Actions: Patient Search & Registration ────────────────────────────────────

  async function cariPasien(keyword) {
    try {
      const { data } = await admisiApi.cariPasien(keyword)
      return data.data ?? []
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal mencari pasien')
    }
  }

  // Detail satu pasien (untuk tab Detail di modal Profil Pasien)
  async function fetchPasienDetail(id) {
    try {
      const { data } = await admisiApi.showPasien(id)
      return data.data
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal memuat data pasien')
    }
  }

  // Riwayat kunjungan pasien — paginated + filter tanggal (untuk tab Riwayat)
  async function fetchKunjunganPasien(id, params = {}) {
    try {
      const { data } = await admisiApi.kunjunganPasien(id, params)
      const p = data.data ?? {}
      return {
        data: p.data ?? [],
        meta: {
          total:        p.total ?? 0,
          current_page: p.current_page ?? 1,
          last_page:    p.last_page ?? 1,
        },
      }
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal memuat riwayat kunjungan')
    }
  }

  // Jadwal bedah aktif pasien (hari ini & masa depan) — utk banner preop di Admisi
  async function fetchJadwalBedahAktif(patientId) {
    try {
      const { data } = await admisiApi.jadwalBedahAktif(patientId)
      return data.data ?? []
    } catch (err) {
      throw new Error(err.response?.data?.message ?? 'Gagal memuat jadwal bedah pasien')
    }
  }

  async function updatePasien(patientId, payload) {
    try {
      const { data } = await admisiApi.updatePasien(patientId, payload)
      // Update list lokal kalau pasien ada di antrian/visits
      for (const q of antrian.value) {
        if (q.visit?.patient?.id === patientId) {
          q.visit.patient = { ...q.visit.patient, ...data.data }
        }
      }
      for (const v of visits.value) {
        if (v.patient?.id === patientId) {
          v.patient = { ...v.patient, ...data.data }
        }
      }
      return data.data
    } catch (err) {
      const msg = err.response?.data?.message ?? 'Gagal memperbarui data pasien'
      const errors = err.response?.data?.errors ?? null
      const e = new Error(msg)
      e.errors = errors
      throw e
    }
  }

  // Cek/resolve IHS satu pasien ke Satu Sehat (verifikasi NIK sebelum backfill).
  // Mengembalikan { ihs, resolved, patient }; merambatkan IHS baru ke list lokal.
  async function resolveIhs(patientId) {
    try {
      const { data } = await admisiApi.resolveIhs(patientId)
      const res = data.data ?? {}
      const ihs = res.ihs ?? null
      for (const q of antrian.value) {
        if (q.visit?.patient?.id === patientId) q.visit.patient.satusehat_ihs = ihs
      }
      for (const v of visits.value) {
        if (v.patient?.id === patientId) v.patient.satusehat_ihs = ihs
      }
      return { ...res, message: data.message ?? null }
    } catch (err) {
      const msg = err.response?.data?.message ?? 'Gagal menghubungi Satu Sehat'
      throw new Error(msg)
    }
  }

  async function daftarKunjungan(formData) {
    try {
      const { data } = await admisiApi.daftar(formData)
      return data.data
    } catch (err) {
      const msg = err.response?.data?.message ?? 'Gagal mendaftarkan kunjungan'
      const errors = err.response?.data?.errors ?? null
      const e = new Error(msg)
      e.errors = errors
      throw e
    }
  }

  async function daftarkanWalkIn(visitId, formData) {
    try {
      const { data } = await admisiApi.daftarkanWalkIn(visitId, formData)
      return data.data
    } catch (err) {
      const msg = err.response?.data?.message ?? 'Gagal mendaftarkan walk-in'
      const errors = err.response?.data?.errors ?? null
      const e = new Error(msg)
      e.errors = errors
      throw e
    }
  }

  // ─── Actions: Insurers ────────────────────────────────────────────────────

  /**
   * Load semua penjamin (insurers) dari /master/penjamin.
   * Backend pakai paginate(20) by default → kirim per_page besar agar
   * dropdown admisi tidak terpotong. Filter `type` optional untuk
   * load subset (ASURANSI/PERUSAHAAN/SOSIAL).
   */
  async function fetchInsurers(filters = {}) {
    insurersLoading.value = true
    try {
      const { data } = await masterApi.penjamin({ per_page: 200, ...filters })
      // Response shape: { success, data: { data: [...], total, ... } } (paginator)
      // Fallback ke array langsung kalau backend ganti format.
      const payload = data.data
      insurers.value = Array.isArray(payload)
        ? payload
        : (payload?.data ?? [])
      return insurers.value
    } catch {
      insurers.value = []
      return []
    } finally {
      insurersLoading.value = false
    }
  }

  // ─── Actions: Rekam Medis ─────────────────────────────────────────────────────

  async function fetchRekamMedis(patientId) {
    rekamMedisLoading.value = true
    rekamMedisError.value = null
    rekamMedisData.value = null
    try {
      const { data } = await perawatApi.rekamMedis(patientId)
      rekamMedisData.value = data.data ?? { vital_history: [], documents: [] }
      return rekamMedisData.value
    } catch (err) {
      rekamMedisError.value = err.response?.data?.message ?? 'Gagal memuat rekam medis'
    } finally {
      rekamMedisLoading.value = false
    }
  }

  // ─── WebSocket ────────────────────────────────────────────────────────────────

  function connectWs() {
    const appKey = import.meta.env.VITE_REVERB_APP_KEY
    if (!appKey) {
      startPolling()
      return
    }

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

        _channel = _pusher.subscribe('admisi-queue')
        _channel.bind('queue-updated', ({ action, queue }) => {
          if (action === 'added') {
            antrian.value.push(queue)
          } else {
            _updateAntrianItem(queue)
          }
          // Tabel "Seluruh Kunjungan" ikut ter-refresh (mis. walk-in kiosk baru).
          scheduleVisitsRefresh()
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
    _pusher = null
    _channel = null
    stopPolling()
    clearTimeout(_visitsRefreshTimer)
  }

  // Fallback tanpa WebSocket: polling cepat (10s) untuk antrian "Siap Dipanggil"
  // + refresh diam-diam tabel kunjungan, supaya walk-in kiosk muncul mendekati realtime.
  function startPolling(intervalMs = 10_000) {
    stopPolling()
    _pollInterval = setInterval(() => {
      fetchAntrian()
      scheduleVisitsRefresh()
    }, intervalMs)
  }

  function stopPolling() {
    if (_pollInterval) {
      clearInterval(_pollInterval)
      _pollInterval = null
    }
  }

  // ─── Private helpers ──────────────────────────────────────────────────────────

  function _updateAntrianItem(updated) {
    if (!updated?.id) return
    const idx = antrian.value.findIndex((q) => q.id === updated.id)
    if (idx !== -1) {
      antrian.value[idx] = { ...antrian.value[idx], ...updated }
    }
  }

  // ─── Return ───────────────────────────────────────────────────────────────────

  return {
    // state
    stats, bpjsStatus, dashboardLoading, dashboardError,
    antrian, antrianLoading, antrianError,
    visits, visitsMeta, visitsLoading, visitsError, visitsFilter, visitsPerPage,
    selectedVisit, visitDetailLoading,
    rekamMedisData, rekamMedisLoading, rekamMedisError,
    consentLoading,
    insurers, insurersLoading,

    // computed
    antrianCount, waitingCount, selesaiCount,

    // dashboard
    fetchDashboard,

    // antrian
    fetchAntrian, panggilAntrian, selesaiAntrian,

    // visits
    fetchVisits, fetchVisitDetail, cancelKunjungan, updatePenjamin,

    // patient
    cariPasien, fetchPasienDetail, fetchKunjunganPasien, daftarKunjungan, daftarkanWalkIn, updatePasien,
    resolveIhs, fetchJadwalBedahAktif,

    // rekam medis
    fetchRekamMedis,

    // insurers
    fetchInsurers,

    // websocket
    connectWs, disconnectWs,
  }
})
