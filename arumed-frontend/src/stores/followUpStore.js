/**
 * Follow-Up Store — Kontrol Ulang
 *
 * Mengelola 3 dashboard widget:
 *   1. Pasien kontrol HARI INI   → follow_up_date = TODAY
 *   2. Pasien kontrol MINGGU INI → follow_up_date BETWEEN TODAY AND TODAY+7
 *   3. Statistik per bulan       → GROUP BY month, guarantor_type
 *
 * Hanya untuk tracking dan reporting.
 * TIDAK ada auto-queue atau auto-visit creation.
 */

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { followUpApi } from '@/services/api'

export const useFollowUpStore = defineStore('followUp', () => {
  // ─── State ──────────────────────────────────────────────────────────────
  const hariIni         = ref([])   // visits kontrol hari ini
  const mingguIni       = ref([])   // visits kontrol 7 hari ke depan
  const statistik       = ref(null) // monthly analytics
  const loading         = ref({ hariIni: false, mingguIni: false, statistik: false })
  const error           = ref(null)
  const statistikFilter = ref({ bulan: 6 })

  // ─── Getters ─────────────────────────────────────────────────────────────

  /** Jumlah pasien kontrol hari ini */
  const countHariIni = computed(() => hariIni.value.length)

  /** Jumlah pasien kontrol minggu ini (termasuk hari ini) */
  const countMingguIni = computed(() => mingguIni.value.length)

  /**
   * Pasien kontrol hari ini — group by jam (pagi/siang/sore)
   * Berguna untuk display kartu jadwal.
   */
  const hariIniByGuarantor = computed(() => {
    const map = { BPJS: [], UMUM: [], LAIN: [] }
    for (const v of hariIni.value) {
      if (v.guarantor_type === 'BPJS') map.BPJS.push(v)
      else if (v.guarantor_type === 'UMUM') map.UMUM.push(v)
      else map.LAIN.push(v)
    }
    return map
  })

  /**
   * Minggu ini — ditambahkan field `hari_label` untuk display.
   */
  const mingguIniWithLabel = computed(() =>
    mingguIni.value.map((v) => ({
      ...v,
      hari_label: formatHariTersisa(v.hari_tersisa),
    })),
  )

  /**
   * Chart series untuk statistik per bulan.
   * Format: { labels: string[], datasets: { label, data }[] }
   */
  const chartData = computed(() => {
    if (!statistik.value?.per_bulan?.length) return null

    const monthsSet = [...new Set(statistik.value.per_bulan.map((r) => r.bulan))].sort()
    const guarantors = [...new Set(statistik.value.per_bulan.map((r) => r.guarantor_type))]

    const datasets = guarantors.map((gt) => ({
      label: gt,
      data: monthsSet.map((m) => {
        const found = statistik.value.per_bulan.find(
          (r) => r.bulan === m && r.guarantor_type === gt,
        )
        return found?.total ?? 0
      }),
    }))

    return {
      labels:   monthsSet.map(formatBulanLabel),
      datasets,
    }
  })

  // ─── Actions ─────────────────────────────────────────────────────────────

  async function fetchHariIni() {
    loading.value.hariIni = true
    error.value           = null

    try {
      const { data } = await followUpApi.hariIni()
      hariIni.value = data.data ?? []
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Gagal memuat data kontrol hari ini'
    } finally {
      loading.value.hariIni = false
    }
  }

  async function fetchMingguIni() {
    loading.value.mingguIni = true
    error.value             = null

    try {
      const { data } = await followUpApi.mingguIni()
      mingguIni.value = data.data ?? []
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Gagal memuat data kontrol minggu ini'
    } finally {
      loading.value.mingguIni = false
    }
  }

  async function fetchStatistik(bulan = null) {
    if (bulan) statistikFilter.value.bulan = bulan

    loading.value.statistik = true
    error.value             = null

    try {
      const { data } = await followUpApi.statistik(statistikFilter.value)
      statistik.value = data.data
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Gagal memuat statistik follow-up'
    } finally {
      loading.value.statistik = false
    }
  }

  /** Fetch semua sekaligus untuk dashboard load pertama */
  async function fetchAll() {
    await Promise.allSettled([fetchHariIni(), fetchMingguIni(), fetchStatistik()])
  }

  function setStatistikBulan(bulan) {
    statistikFilter.value.bulan = bulan
    fetchStatistik()
  }

  // ─── Helpers ─────────────────────────────────────────────────────────────

  function formatHariTersisa(hari) {
    const n = Number(hari)
    if (n === 0)   return 'Hari ini'
    if (n === 1)   return 'Besok'
    if (n <= 7)    return `${n} hari lagi`
    return `${n} hari`
  }

  function formatBulanLabel(isoMonth) {
    // isoMonth = "2026-04"
    const [year, month] = isoMonth.split('-')
    const date = new Date(Number(year), Number(month) - 1, 1)
    return date.toLocaleDateString('id-ID', { month: 'short', year: 'numeric' })
  }

  return {
    hariIni,
    mingguIni,
    statistik,
    loading,
    error,
    statistikFilter,
    countHariIni,
    countMingguIni,
    hariIniByGuarantor,
    mingguIniWithLabel,
    chartData,
    fetchHariIni,
    fetchMingguIni,
    fetchStatistik,
    fetchAll,
    setStatistikBulan,
  }
})
