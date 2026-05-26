import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { jadwalDokterApi } from '@/services/api'

export const useJadwalDokterStore = defineStore('jadwalDokter', () => {
  // list semua dokter + jadwal mingguan mereka (dari GET /jadwal-dokter)
  const daftarDokter = ref([])
  // list jadwal aktif hari ini (dari GET /jadwal-dokter/aktif-hari-ini)
  const aktifHariIni = ref([])

  const loading = ref(false)
  const error   = ref(null)

  // ─── Fetch semua ────────────────────────────────────────────────────────────
  async function fetchAll() {
    loading.value = true
    error.value   = null
    try {
      const res = await jadwalDokterApi.list()
      daftarDokter.value = res.data?.data ?? []
    } catch (e) {
      error.value = e.response?.data?.message ?? 'Gagal memuat jadwal dokter'
    } finally {
      loading.value = false
    }
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

  // ─── Computed ────────────────────────────────────────────────────────────────

  // Label untuk Antrean TV: daftar "D1 — dr. Aulia (Poliklinik Mata, 08:00–12:00)"
  const tvPanelRows = computed(() =>
    aktifHariIni.value.map((s) => ({
      id:           s.id,
      queuePrefix:  s.queue_prefix,
      namaDokter:   s.nama_dokter,
      poliklinik:   s.poliklinik,
      room:         s.room,
      label:        `${s.queue_prefix} — ${s.nama_dokter}`,
      sublabel:     [s.poliklinik, s.room ? `Ruang ${s.room}` : null, s.start_time && s.end_time ? `${s.start_time}–${s.end_time}` : null]
                      .filter(Boolean).join(' · '),
    }))
  )

  return {
    daftarDokter,
    aktifHariIni,
    tvPanelRows,
    loading,
    error,
    fetchAll,
    fetchAktifHariIni,
    createJadwal,
    updateJadwal,
    deleteJadwal,
    toggleAktif,
  }
})
