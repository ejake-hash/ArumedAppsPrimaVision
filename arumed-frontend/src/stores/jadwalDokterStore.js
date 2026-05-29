import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { jadwalDokterApi } from '@/services/api'

export const useJadwalDokterStore = defineStore('jadwalDokter', () => {
  // list semua dokter + jadwal mingguan mereka (dari GET /jadwal-dokter)
  const daftarDokter = ref([])
  // list jadwal aktif hari ini (dari GET /jadwal-dokter/aktif-hari-ini)
  const aktifHariIni = ref([])
  // daftar minggu yang bisa dipilih (dari GET /jadwal-dokter/minggu-tersedia)
  const availableWeeks = ref([])

  // Filter aktif — minggu yang sedang ditampilkan & jenis layanan (tab).
  // weekStart null = backend pakai minggu berjalan.
  const weekStart   = ref(null)
  const serviceType = ref('BPJS') // 'BPJS' | 'EKSEKUTIF'

  const loading = ref(false)
  const error   = ref(null)

  // ─── Fetch semua (mengikuti weekStart + serviceType aktif) ───────────────────
  async function fetchAll() {
    loading.value = true
    error.value   = null
    try {
      const params = {}
      if (weekStart.value)   params.week_start   = weekStart.value
      if (serviceType.value) params.service_type = serviceType.value
      const res = await jadwalDokterApi.list(params)
      daftarDokter.value = res.data?.data ?? []
    } catch (e) {
      error.value = e.response?.data?.message ?? 'Gagal memuat jadwal dokter'
    } finally {
      loading.value = false
    }
  }

  // ─── Fetch daftar minggu ─────────────────────────────────────────────────────
  async function fetchAvailableWeeks() {
    try {
      const res = await jadwalDokterApi.availableWeeks()
      availableWeeks.value = res.data?.data ?? []
      // Default weekStart = minggu berjalan jika belum di-set.
      if (!weekStart.value) {
        const current = availableWeeks.value.find((w) => w.is_current)
        weekStart.value = current?.week_start ?? availableWeeks.value[0]?.week_start ?? null
      }
    } catch {
      availableWeeks.value = []
    }
  }

  // ─── Ganti minggu / jenis layanan lalu refetch ───────────────────────────────
  async function setWeek(ws) {
    weekStart.value = ws
    await fetchAll()
  }

  async function setServiceType(type) {
    serviceType.value = type
    await fetchAll()
  }

  // ─── Fetch aktif hari ini ────────────────────────────────────────────────────
  async function fetchAktifHariIni() {
    try {
      const res = await jadwalDokterApi.aktifHariIni()
      aktifHariIni.value = res.data?.data ?? []
    } catch {
      aktifHariIni.value = []
    }
  }

  // ─── CRUD ────────────────────────────────────────────────────────────────────
  async function createJadwal(data) {
    const res = await jadwalDokterApi.create(data)
    await fetchAll()
    return res.data?.data
  }

  async function updateJadwal(id, data) {
    const res = await jadwalDokterApi.update(id, data)
    await fetchAll()
    return res.data?.data
  }

  async function deleteJadwal(id) {
    await jadwalDokterApi.remove(id)
    await fetchAll()
  }

  async function toggleAktif(id) {
    const res = await jadwalDokterApi.toggle(id)
    // Update in-place untuk responsiveness
    const updated = res.data?.data
    if (updated) {
      daftarDokter.value.forEach((emp) => {
        emp.jadwal?.forEach((j) => {
          if (j.id === id) j.is_active = updated.is_active
        })
      })
      // Refresh aktif hari ini
      await fetchAktifHariIni()
    }
    return updated
  }

  // ─── Salin ke minggu depan ────────────────────────────────────────────────────
  async function copyToNextWeek() {
    const res = await jadwalDokterApi.copyNextWeek(weekStart.value)
    await fetchAvailableWeeks()
    await fetchAll()
    return res.data // { success, data:{copied,skipped,target_week}, message }
  }

  // ─── Import CSV ───────────────────────────────────────────────────────────────
  async function importCsv(file) {
    const res = await jadwalDokterApi.csvImport(file, weekStart.value)
    await fetchAvailableWeeks()
    await fetchAll()
    return res.data // { success, data:{imported,skipped,errors,target_week}, message, errors }
  }

  // ─── Computed ────────────────────────────────────────────────────────────────

  // Label untuk Antrean TV: daftar "D1 — dr. Aulia (Poliklinik Mata, 08:00–12:00)"
  const tvPanelRows = computed(() =>
    aktifHariIni.value.map((s) => ({
      id:           s.id,
      queuePrefix:  s.queue_prefix,
      namaDokter:   s.nama_dokter,
      poliklinik:   s.poliklinik,
      room:         s.room,
      serviceType:  s.service_type,
      label:        `${s.queue_prefix} — ${s.nama_dokter}`,
      sublabel:     [s.poliklinik, s.room ? `Ruang ${s.room}` : null, s.start_time && s.end_time ? `${s.start_time}–${s.end_time}` : null]
                      .filter(Boolean).join(' · '),
    }))
  )

  return {
    daftarDokter,
    aktifHariIni,
    availableWeeks,
    weekStart,
    serviceType,
    tvPanelRows,
    loading,
    error,
    fetchAll,
    fetchAvailableWeeks,
    setWeek,
    setServiceType,
    fetchAktifHariIni,
    createJadwal,
    updateJadwal,
    deleteJadwal,
    toggleAktif,
    copyToNextWeek,
    importCsv,
  }
})
