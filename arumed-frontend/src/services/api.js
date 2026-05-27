/**
 * Axios instance — Arumed Apps
 * - Base URL dari VITE_API_URL (fallback: /api/v1)
 * - Request interceptor  : inject JWT Bearer token
 * - Response interceptor : handle 401 (auto logout) + 422/500 (error toast)
 */

import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  timeout: 15_000,
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
})

// ─── Request interceptor ────────────────────────────────────────────────────
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('auth_token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  },
  (error) => Promise.reject(error),
)

// ─── Response interceptor ───────────────────────────────────────────────────
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status  = error.response?.status
    const message = error.response?.data?.message ?? 'Terjadi kesalahan.'

    if (status === 401) {
      // Token expired atau invalid → bersihkan session, redirect ke login
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')

      // Hindari import circular — dispatch event agar App.vue bisa react
      window.dispatchEvent(new CustomEvent('arumed:session-expired'))
    }

    if (status === 403) {
      window.dispatchEvent(new CustomEvent('arumed:forbidden', { detail: message }))
    }

    if (status >= 500) {
      window.dispatchEvent(
        new CustomEvent('arumed:server-error', { detail: message }),
      )
    }

    return Promise.reject(error)
  },
)

// ─── Typed endpoint helpers ──────────────────────────────────────────────────

/** Auth */
export const authApi = {
  login:          (data)      => api.post('/auth/login', data),
  logout:         ()          => api.post('/auth/logout'),
  refresh:        ()          => api.post('/auth/refresh'),
  me:             ()          => api.get('/auth/me'),
  changePassword: (data)      => api.put('/auth/password', data),
}

/** Antrian — multi-station */
export const queueApi = {
  admisi:     () => api.get('/admisi/antrian'),
  perawat:    () => api.get('/perawat/antrian'),
  refraksi:   () => api.get('/refraksi/antrian'),
  dokter:     () => api.get('/dokter/antrian'),
  penunjang:  () => api.get('/penunjang/antrian'),
  bedah:      () => api.get('/bedah/antrian'),
  farmasi:    () => api.get('/farmasi/antrian'),
  kasir:      () => api.get('/kasir/antrian'),

  panggil: (station, id) => api.put(`/${station}/antrian/${id}/panggil`),
  selesai: (station, id) => api.put(`/${station}/antrian/${id}/selesai`),
}

/** Kunjungan */
export const visitApi = {
  list:       (params)    => api.get('/admisi/kunjungan', { params }),
  show:       (id)        => api.get(`/admisi/kunjungan/${id}`),
  daftar:     (data)      => api.post('/admisi/daftar', data),
  cancel:     (id)        => api.put(`/admisi/kunjungan/${id}/cancel`),
  cariPasien: (keyword)   => api.get('/admisi/pasien', { params: { keyword } }),
}

/** Notifikasi TTD dokter */
export const notifApi = {
  list:          ()     => api.get('/dokter/notifikasi'),
  baca:          (id)   => api.put(`/dokter/notifikasi/${id}/baca`),
  tandaTangan:   (id, pin) => api.post(`/dokter/dokumen/${id}/tanda-tangan`, { pin }),
  tolak:         (id, alasan) => api.post(`/dokter/dokumen/${id}/tolak`, { alasan }),
}

/** Follow-up (Kontrol Ulang) */
export const followUpApi = {
  hariIni:    ()        => api.get('/dashboard/follow-up/hari-ini'),
  mingguIni:  ()        => api.get('/dashboard/follow-up/minggu-ini'),
  statistik:  (params)  => api.get('/dashboard/follow-up/statistik', { params }),
}

/** Perawat / Triase */
export const perawatApi = {
  antrian:         ()                 => api.get('/perawat/antrian'),
  kunjungan:       (visitId)          => api.get(`/perawat/kunjungan/${visitId}`),
  panggil:         (id)               => api.put(`/perawat/antrian/${id}/panggil`),
  mulai:           (id)               => api.put(`/perawat/antrian/${id}/mulai`),
  selesai:         (id)               => api.put(`/perawat/antrian/${id}/selesai`),
  lewati:          (id)               => api.put(`/perawat/antrian/${id}/lewati`),

  showAsesmen:     (visitId)          => api.get(`/perawat/asesmen/${visitId}`),
  storeAsesmen:    (data)             => api.post('/perawat/asesmen', data),
  updateAsesmen:   (id, data)         => api.put(`/perawat/asesmen/${id}`, data),
  finalizeAsesmen: (id)               => api.post(`/perawat/asesmen/${id}/finalize`),

  // CPPT — timeline append + soft-edit
  cpptList:        (visitId)          => api.get(`/perawat/cppt/visit/${visitId}`),
  cpptCreate:      (data)             => api.post('/perawat/cppt', data),
  cpptUpdate:      (id, data)         => api.put(`/perawat/cppt/${id}`, data),

  vitalHistory:    (patientId)        => api.get(`/perawat/pasien/${patientId}/vital-history`),
  rekamMedis:      (patientId)        => api.get(`/perawat/pasien/${patientId}/rekam-medis`),
  dokumen:         (documentId)       => api.get(`/perawat/dokumen/${documentId}`),
  statusParallel:  (visitId)          => api.get(`/perawat/kunjungan/${visitId}/status-parallel`),
}

/** Dokter — RME, antrian, tindakan, resep, penunjang */
export const dokterApi = {
  antrian:          ()                    => api.get('/dokter/antrian'),
  panggil:          (id)                  => api.put(`/dokter/antrian/${id}/panggil`),
  selesai:          (id)                  => api.put(`/dokter/antrian/${id}/selesai`),
  kePenunjang:      (id)                  => api.put(`/dokter/antrian/${id}/ke-penunjang`),

  kunjungan:        (visitId)             => api.get(`/dokter/kunjungan/${visitId}`),
  finalize:         (visitId)             => api.post(`/dokter/kunjungan/${visitId}/finalize`),

  tarifTindakan:    (visitId)             => api.get('/dokter/tarif-tindakan', { params: { visit_id: visitId } }),
  daftarObat:       (search)              => api.get('/dokter/obat', { params: { search } }),

  showTab2:         (visitId)             => api.get(`/dokter/kunjungan/${visitId}/tab2`),
  storeTab2:        (visitId, data)       => api.post(`/dokter/kunjungan/${visitId}/tab2`, data),
  updateTab2:       (visitId, data)       => api.put(`/dokter/kunjungan/${visitId}/tab2`, data),

  showTab4:         (visitId)             => api.get(`/dokter/kunjungan/${visitId}/tab4`),
  storeTab4:        (visitId, data)       => api.post(`/dokter/kunjungan/${visitId}/tab4`, data),
  updateTab4:       (visitId, data)       => api.put(`/dokter/kunjungan/${visitId}/tab4`, data),

  indexTindakan:    (visitId)             => api.get(`/dokter/kunjungan/${visitId}/tindakan`),
  storeTindakan:    (visitId, data)       => api.post(`/dokter/kunjungan/${visitId}/tindakan`, data),
  deleteTindakan:   (id)                  => api.delete(`/dokter/tindakan/${id}`),

  indexResep:       (visitId)             => api.get(`/dokter/kunjungan/${visitId}/resep`),
  storeResep:       (visitId, data)       => api.post(`/dokter/kunjungan/${visitId}/resep`, data),

  indexOrderPenunjang:  (visitId)         => api.get(`/dokter/kunjungan/${visitId}/order-penunjang`),
  indexHasilPenunjang: (visitId)          => api.get(`/dokter/kunjungan/${visitId}/hasil-penunjang`),
  storeOrderPenunjang: (data)             => api.post('/dokter/order-penunjang', data),
  cancelOrderPenunjang: (id)              => api.delete(`/dokter/order-penunjang/${id}`),
}

/** Refraksionis */
export const refraksiApi = {
  antrian:            ()                 => api.get('/refraksi/antrian'),
  kunjungan:          (visitId)          => api.get(`/refraksi/kunjungan/${visitId}`),
  panggil:            (id)               => api.put(`/refraksi/antrian/${id}/panggil`),
  mulai:              (id)               => api.put(`/refraksi/antrian/${id}/mulai`),
  lewati:             (id)               => api.put(`/refraksi/antrian/${id}/lewati`),
  selesai:            (id)               => api.put(`/refraksi/antrian/${id}/selesai`),

  showPemeriksaan:    (visitId)          => api.get(`/refraksi/pemeriksaan/${visitId}`),
  storePemeriksaan:   (data)             => api.post('/refraksi/pemeriksaan', data),
  updatePemeriksaan:  (id, data)         => api.put(`/refraksi/pemeriksaan/${id}`, data),
  finalizePemeriksaan: (id)              => api.post(`/refraksi/pemeriksaan/${id}/finalize`),

  showResep:          (refractionId)     => api.get(`/refraksi/resep-kacamata/${refractionId}`),
  storeResep:         (data)             => api.post('/refraksi/resep-kacamata', data),
  updateResep:        (id, data)         => api.put(`/refraksi/resep-kacamata/${id}`, data),

  riwayat:            (patientId)        => api.get(`/refraksi/pasien/${patientId}/riwayat`),
  statusParallel:     (visitId)          => api.get(`/refraksi/kunjungan/${visitId}/status-parallel`),
}

/** Dashboard */
export const dashboardApi = {
  statistik:       ()       => api.get('/dashboard/statistik'),
  kunjunganHariIni: (params) => api.get('/dashboard/kunjungan-hari-ini', { params }),
  antrianAktif:    ()       => api.get('/dashboard/antrian-aktif'),
  pendapatan:      (params) => api.get('/dashboard/pendapatan', { params }),
  stokAlert:       ()       => api.get('/dashboard/stok-alert'),
  bpjsExpired:     ()       => api.get('/dashboard/bpjs-expired'),
  satusehatStatus: ()       => api.get('/dashboard/satusehat-status'),
  kunjunganChart:  ()       => api.get('/dashboard/kunjungan-chart'),
  diagnosisStats:  ()       => api.get('/dashboard/diagnosis-stats'),
}

/** Master Data */
// Penjamin (insurers) — callable as function utk back-compat (list shorthand)
// + properties list/create/update/remove untuk CRUD lengkap.
const _penjamin = (params) => api.get('/master/penjamin', { params })
_penjamin.list   = (params)   => api.get('/master/penjamin', { params })
_penjamin.create = (data)     => api.post('/master/penjamin', data)
_penjamin.update = (id, data) => api.put(`/master/penjamin/${id}`, data)
_penjamin.remove = (id)       => api.delete(`/master/penjamin/${id}`)

export const masterApi = {
  penjamin: _penjamin,

  // Profil Klinik (singleton)
  profilKlinik: {
    show:   ()         => api.get('/master/profil-klinik'),
    update: (data)     => api.put('/master/profil-klinik', data),
  },

  // Tindakan / Prosedur (procedures) — master tarif acuan
  tindakan: {
    list:          (params)   => api.get('/master/tindakan', { params }),
    create:        (data)     => api.post('/master/tindakan', data),
    update:        (id, data) => api.put(`/master/tindakan/${id}`, data),
    remove:        (id)       => api.delete(`/master/tindakan/${id}`),
    kategoriList:  ()         => api.get('/master/tindakan/kategori-list'),  // list master kategori (untuk dropdown)
    // CRUD master kategori (procedure_categories)
    kategori: {
      list:   (params)   => api.get('/master/tindakan/kategori', { params }),
      create: (data)     => api.post('/master/tindakan/kategori', data),
      update: (id, data) => api.put(`/master/tindakan/kategori/${id}`, data),
      remove: (id)       => api.delete(`/master/tindakan/kategori/${id}`),
    },
  },

  // Paket Bedah (surgery_packages)
  paketBedah: {
    list:   (params)   => api.get('/master/paket-bedah', { params }),
    create: (data)     => api.post('/master/paket-bedah', data),
    update: (id, data) => api.put(`/master/paket-bedah/${id}`, data),
    remove: (id)       => api.delete(`/master/paket-bedah/${id}`),
  },

  // Tarif Tindakan (procedure_tariffs) — CSV via masterApi.csv('tarif/tindakan', ...)
  tarifTindakan: {
    list:   (params)   => api.get('/master/tarif/tindakan', { params }),
    create: (data)     => api.post('/master/tarif/tindakan', data),
    update: (id, data) => api.put(`/master/tarif/tindakan/${id}`, data),
    remove: (id)       => api.delete(`/master/tarif/tindakan/${id}`),
  },

  // Obat (medications)
  obat: {
    list:   (params)   => api.get('/master/obat', { params }),
    create: (data)     => api.post('/master/obat', data),
    update: (id, data) => api.put(`/master/obat/${id}`, data),
    remove: (id)       => api.delete(`/master/obat/${id}`),
  },

  // BHP (bhp_items)
  bhp: {
    list:   (params)   => api.get('/master/bhp', { params }),
    create: (data)     => api.post('/master/bhp', data),
    update: (id, data) => api.put(`/master/bhp/${id}`, data),
    remove: (id)       => api.delete(`/master/bhp/${id}`),
  },

  // IOL (iol_items)
  iol: {
    list:   (params)   => api.get('/master/iol', { params }),
    create: (data)     => api.post('/master/iol', data),
    update: (id, data) => api.put(`/master/iol/${id}`, data),
    remove: (id)       => api.delete(`/master/iol/${id}`),
  },

  // Jenis Penunjang (diagnostic_test_types) — master jenis pemeriksaan penunjang
  diagnosticTestType: {
    list:   (params)   => api.get('/master/diagnostic-test-type', { params }),
    create: (data)     => api.post('/master/diagnostic-test-type', data),
    update: (id, data) => api.put(`/master/diagnostic-test-type/${id}`, data),
    remove: (id)       => api.delete(`/master/diagnostic-test-type/${id}`),
  },

  // ICD-10
  icd10: {
    list:   (params)   => api.get('/master/icd10', { params }),
    create: (data)     => api.post('/master/icd10', data),
    update: (id, data) => api.put(`/master/icd10/${id}`, data),
    remove: (id)       => api.delete(`/master/icd10/${id}`),
  },

  // ICD-9
  icd9: {
    list:   (params)   => api.get('/master/icd9', { params }),
    create: (data)     => api.post('/master/icd9', data),
    update: (id, data) => api.put(`/master/icd9/${id}`, data),
    remove: (id)       => api.delete(`/master/icd9/${id}`),
  },

  // Supplier (master supplier inventori farmasi)
  supplier: {
    list:   (params)   => api.get('/inventori-farmasi/supplier', { params }),
    create: (data)     => api.post('/inventori-farmasi/supplier', data),
    update: (id, data) => api.put(`/inventori-farmasi/supplier/${id}`, data),
    remove: (id)       => api.delete(`/inventori-farmasi/supplier/${id}`),
  },

  /**
   * CSV helper generic — bekerja untuk:
   *   - resource baru: 'obat', 'bhp', 'iol', 'icd10', 'icd9'    → /master/{type}/{action}
   *   - tarif existing: 'tarif/tindakan', 'tarif/obat', 'tarif/bhp', 'tarif/iol'
   * (tarif tidak punya template-csv di backend, hanya export & import.)
   */
  csv: {
    template: (type)        => api.get(`/master/${type}/template-csv`, { responseType: 'blob' }),
    export:   (type)        => api.get(`/master/${type}/export-csv`,   { responseType: 'blob' }),
    import:   (type, file)  => {
      const fd = new FormData()
      fd.append('file', file)
      return api.post(`/master/${type}/import-csv`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    },
  },
}

/**
 * Tarif & Paket Bedah — sub-modul standalone (/tarif-paket/*).
 *
 * 18 endpoints: tarif per penjamin (tindakan/obat/bhp/iol) + paket bedah CRUD +
 * sub-resource items (komposisi paket) + tariffs (harga jual per penjamin).
 *
 * Catatan diskon auto: backend hitung `discount_amount` & `discount_percent` di
 * field `discountAmount()/discountPercent()` model SurgeryPackageTariff —
 * frontend tinggal render dari property hasil `listTariffs()`.
 */
export const tarifPaketApi = {
  // --- Tarif per penjamin (type: 'tindakan' | 'obat' | 'bhp' | 'iol') ---
  // CRUD per-row. Wajib pass insurer_id di params/body.
  tarif: {
    list:   (type, params)    => api.get(`/tarif-paket/tarif/${type}`, { params }),
    create: (type, data)      => api.post(`/tarif-paket/tarif/${type}`, data),
    update: (type, id, data)  => api.put(`/tarif-paket/tarif/${type}/${id}`, data),
    remove: (type, id)        => api.delete(`/tarif-paket/tarif/${type}/${id}`),
  },

  // --- Metode Bayar (detail insurer + CSV per-insurer per-type) ---
  metodeBayar: {
    detail:      (id)              => api.get(`/tarif-paket/metode-bayar/${id}`),
    csvTemplate: (id, type)        => api.get(`/tarif-paket/metode-bayar/${id}/tarif/${type}/template-csv`, { responseType: 'blob' }),
    csvExport:   (id, type)        => api.get(`/tarif-paket/metode-bayar/${id}/tarif/${type}/export-csv`,   { responseType: 'blob' }),
    csvImport:   (id, type, file)  => {
      const fd = new FormData()
      fd.append('file', file)
      return api.post(`/tarif-paket/metode-bayar/${id}/tarif/${type}/import-csv`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    },
  },

  // --- Helper: harga master live (untuk auto-fill modal Tambah tarif) ---
  masterPrice: (type, itemId)      => api.get(`/tarif-paket/master-price/${type}/${itemId}`),

  // --- Paket Bedah CRUD ---
  paket: {
    list:   (params)   => api.get('/tarif-paket/paket-bedah', { params }),
    show:   (id)       => api.get(`/tarif-paket/paket-bedah/${id}`),
    create: (data)     => api.post('/tarif-paket/paket-bedah', data),
    update: (id, data) => api.put(`/tarif-paket/paket-bedah/${id}`, data),
    remove: (id)       => api.delete(`/tarif-paket/paket-bedah/${id}`),
  },

  // --- Items paket (komposisi) ---
  items: {
    list:   (paketId)         => api.get(`/tarif-paket/paket-bedah/${paketId}/items`),
    add:    (paketId, data)   => api.post(`/tarif-paket/paket-bedah/${paketId}/items`, data),
    update: (paketId, itemId, data) => api.put(`/tarif-paket/paket-bedah/${paketId}/items/${itemId}`, data),
    remove: (paketId, itemId) => api.delete(`/tarif-paket/paket-bedah/${paketId}/items/${itemId}`),
  },

  // --- Tariffs paket (harga jual per penjamin, auto-diskon) ---
  tariffs: {
    list:   (paketId)              => api.get(`/tarif-paket/paket-bedah/${paketId}/tariffs`),
    upsert: (paketId, data)        => api.post(`/tarif-paket/paket-bedah/${paketId}/tariffs`, data),
    remove: (paketId, tariffId)    => api.delete(`/tarif-paket/paket-bedah/${paketId}/tariffs/${tariffId}`),
  },
}

/** Anjungan Mandiri (Kiosk — public, no auth) */
export const anjunganApi = {
  tiketUmum: () => api.post('/anjungan/tiket-umum'),
}

/** Antrean TV (lobby display — public, no auth) */
export const antreanTvApi = {
  snapshot:            () => api.get('/antrean-tv/snapshot'),
  dokterAktif:         () => api.get('/antrean-tv/dokter-aktif'),
  displaySettings:     () => api.get('/antrean-tv/display-settings'),
  updateDisplay:       (station, payload) => api.put(`/antrean-tv/display-settings/${station}`, payload),
  resetDisplay:        (station) => api.post(`/antrean-tv/display-settings/${station}/reset`),
  audioSettings:       () => api.get('/antrean-tv/audio-settings'),
  updateAudio:         (payload) => api.put('/antrean-tv/audio-settings', payload),
  brandingSettings:    () => api.get('/antrean-tv/branding-settings'),
  updateBranding:      (payload) => api.put('/antrean-tv/branding-settings', payload),
  resetBranding:       () => api.post('/antrean-tv/branding-settings/reset'),
}

/** Jadwal Dokter */
export const jadwalDokterApi = {
  list:         ()           => api.get('/jadwal-dokter'),
  aktifHariIni: ()           => api.get('/jadwal-dokter/aktif-hari-ini'),
  show:         (id)         => api.get(`/jadwal-dokter/${id}`),
  create:       (data)       => api.post('/jadwal-dokter', data),
  update:       (id, data)   => api.put(`/jadwal-dokter/${id}`, data),
  remove:       (id)         => api.delete(`/jadwal-dokter/${id}`),
  toggle:       (id)         => api.patch(`/jadwal-dokter/${id}/toggle`),
}

/** Admisi — dashboard, antrian, kunjungan, jadwal dokter */
export const admisiApi = {
  dashboard:     ()             => api.get('/admisi/dashboard'),
  antrian:       ()             => api.get('/admisi/antrian'),
  panggil:       (id)           => api.put(`/admisi/antrian/${id}/panggil`),
  selesai:       (id)           => api.put(`/admisi/antrian/${id}/selesai`),
  kunjungan:     (params)       => api.get('/admisi/kunjungan', { params }),
  kunjunganById: (id)           => api.get(`/admisi/kunjungan/${id}`),
  cancelKunjungan: (id)         => api.put(`/admisi/kunjungan/${id}/cancel`),
  cariPasien:    (keyword)      => api.get('/admisi/pasien', { params: { keyword } }),
  showPasien:    (id)           => api.get(`/admisi/pasien/${id}`),
  kunjunganPasien: (id, params) => api.get(`/admisi/pasien/${id}/kunjungan`, { params }),
  updatePasien:  (id, data)     => api.put(`/admisi/pasien/${id}`, data),
  daftar:        (data)         => api.post('/admisi/daftar', data),
  daftarkanWalkIn: (visitId, data) => api.put(`/admisi/kunjungan/${visitId}/daftarkan-walkin`, data),
}

/** Farmasi — dispensing resep, antrian, stok obat/BHP */
export const farmasiApi = {
  // Antrian
  antrian:           ()           => api.get('/farmasi/antrian'),
  panggilAntrian:    (id)         => api.put(`/farmasi/antrian/${id}/panggil`),
  selesaiAntrian:    (id)         => api.put(`/farmasi/antrian/${id}/selesai`),

  // Resep
  resep:             (params)     => api.get('/farmasi/resep', { params }),
  showResep:         (id)         => api.get(`/farmasi/resep/${id}`),
  startDispensing:   (id)         => api.put(`/farmasi/resep/${id}/dispensing`),
  selesaiDispensing: (id)         => api.put(`/farmasi/resep/${id}/selesai`),
  cancelResep:       (id)         => api.put(`/farmasi/resep/${id}/cancel`),
  storeItem:         (rid, items) => api.post(`/farmasi/resep/${rid}/item`, { items }),
  deleteItem:        (id)         => api.delete(`/farmasi/resep-item/${id}`),

  // Stok
  stokObat:          (params)     => api.get('/farmasi/stok/obat', { params }),
  updateStokObat:    (id, data)   => api.put(`/farmasi/stok/obat/${id}`, data),
  stokAlert:         ()           => api.get('/farmasi/stok/alert'),
}

/** Inventori Farmasi — Pembelian (Purchase Order) */
export const pembelianApi = {
  list:   (params)   => api.get('/inventori-farmasi/pembelian', { params }),
  show:   (id)       => api.get(`/inventori-farmasi/pembelian/${id}`),
  create: (data)     => api.post('/inventori-farmasi/pembelian', data),
  update: (id, data) => api.put(`/inventori-farmasi/pembelian/${id}`, data),
  remove: (id)       => api.delete(`/inventori-farmasi/pembelian/${id}`),
  cancel: (id)       => api.post(`/inventori-farmasi/pembelian/${id}/cancel`),
}

/** Inventori Farmasi — Penerimaan Barang (Goods Receipt) */
export const penerimaanApi = {
  list:        (params)   => api.get('/inventori-farmasi/penerimaan', { params }),
  show:        (id)       => api.get(`/inventori-farmasi/penerimaan/${id}`),
  prepareFromPo: (poId)   => api.get(`/inventori-farmasi/penerimaan/from-po/${poId}`),
  create:      (data)     => api.post('/inventori-farmasi/penerimaan', data),
  remove:      (id)       => api.delete(`/inventori-farmasi/penerimaan/${id}`),
}

/** Inventori Farmasi — Request dari Unit (klinik → gudang) */
export const unitRequestApi = {
  list:    (params)     => api.get('/inventori-farmasi/unit-request', { params }),
  show:    (id)         => api.get(`/inventori-farmasi/unit-request/${id}`),
  create:  (data)       => api.post('/inventori-farmasi/unit-request', data),
  update:  (id, data)   => api.put(`/inventori-farmasi/unit-request/${id}`, data),
  submit:  (id)         => api.post(`/inventori-farmasi/unit-request/${id}/submit`),
  approve: (id)         => api.post(`/inventori-farmasi/unit-request/${id}/approve`),
  reject:  (id, reason) => api.post(`/inventori-farmasi/unit-request/${id}/reject`, { reason }),
  deliver: (id, data)   => api.post(`/inventori-farmasi/unit-request/${id}/deliver`, data),
  close:   (id)         => api.post(`/inventori-farmasi/unit-request/${id}/close`),
  remove:  (id)         => api.delete(`/inventori-farmasi/unit-request/${id}`),
}

/** Inventori Farmasi — Retur dari Unit (stok kembali ke gudang saat submit) */
export const unitReturnApi = {
  list:    (params)     => api.get('/inventori-farmasi/unit-return', { params }),
  show:    (id)         => api.get(`/inventori-farmasi/unit-return/${id}`),
  create:  (data)       => api.post('/inventori-farmasi/unit-return', data),
  update:  (id, data)   => api.put(`/inventori-farmasi/unit-return/${id}`, data),
  submit:  (id)         => api.post(`/inventori-farmasi/unit-return/${id}/submit`),
  receive: (id)         => api.post(`/inventori-farmasi/unit-return/${id}/receive`),
  reject:  (id, reason) => api.post(`/inventori-farmasi/unit-return/${id}/reject`, { reason }),
  remove:  (id)         => api.delete(`/inventori-farmasi/unit-return/${id}`),
}

/** Inventori Farmasi — Inbox admin (request + retur SUBMITTED yg butuh action) */
export const inventoriInboxApi = {
  list: () => api.get('/inventori-farmasi/inbox'),
}

/** Inventori Farmasi — Penentuan Harga (HPP & HJA) */
export const inventoriHargaApi = {
  settings: {
    get:    ()                      => api.get('/inventori-farmasi/harga/settings'),
    update: (data)                  => api.put('/inventori-farmasi/harga/settings', data),
  },
  list:   (type, params)           => api.get(`/inventori-farmasi/harga/${type}`, { params }),
  upsert: (type, itemId, data)     => api.put(`/inventori-farmasi/harga/${type}/${itemId}`, data),
  remove: (type, itemId)           => api.delete(`/inventori-farmasi/harga/${type}/${itemId}`),
}

/** Penunjang — antrian, order, hasil pemeriksaan */
export const penunjangApi = {
  // Antrian
  antrian:           ()           => api.get('/penunjang/antrian'),
  panggilAntrian:    (id)         => api.put(`/penunjang/antrian/${id}/panggil`),
  selesaiAntrian:    (id)         => api.put(`/penunjang/antrian/${id}/selesai`),

  // Order
  orders:            (params)     => api.get('/penunjang/order', { params }),
  showOrder:         (id)         => api.get(`/penunjang/order/${id}`),
  storeOrder:        (data)       => api.post('/penunjang/order', data),
  prosesOrder:       (id)         => api.put(`/penunjang/order/${id}/proses`),
  cancelOrder:       (id)         => api.put(`/penunjang/order/${id}/cancel`),

  // Hasil
  showHasil:         (orderId)    => api.get(`/penunjang/hasil/${orderId}`),
  storeHasil:        (data)       => api.post('/penunjang/hasil', data),
  updateHasil:       (id, data)   => api.put(`/penunjang/hasil/${id}`, data),
  selesaiHasil:      (id)         => api.post(`/penunjang/hasil/${id}/selesai`),
}

/** Kasir — antrian, invoice, pembayaran, laporan */
export const kasirApi = {
  // Antrian
  antrian:           ()                   => api.get('/kasir/antrian'),
  panggilAntrian:    (id)                 => api.put(`/kasir/antrian/${id}/panggil`),
  selesaiAntrian:    (id)                 => api.put(`/kasir/antrian/${id}/selesai`),

  // Invoice
  invoiceList:       (params)             => api.get('/kasir/invoice', { params }),
  showInvoice:       (visitId)            => api.get(`/kasir/invoice/${visitId}`),
  generateInvoice:   (visitId)            => api.post(`/kasir/invoice/${visitId}/generate`),
  updateInvoice:     (id, data)           => api.put(`/kasir/invoice/${id}`, data),
  finalizeInvoice:   (id)                 => api.post(`/kasir/invoice/${id}/finalize`),
  bayarInvoice:      (id, data)           => api.post(`/kasir/invoice/${id}/bayar`, data),
  cancelInvoice:     (id)                 => api.post(`/kasir/invoice/${id}/cancel`),
  cetakInvoice:      (id)                 => api.get(`/kasir/invoice/${id}/cetak`),

  // Billing items (override saat edit tagihan)
  storeItem:         (invoiceId, data)    => api.post(`/kasir/invoice/${invoiceId}/item`, data),
  updateItem:        (id, data)           => api.put(`/kasir/invoice-item/${id}`, data),
  deleteItem:        (id)                 => api.delete(`/kasir/invoice-item/${id}`),

  // Laporan
  laporanHarian:     (params)             => api.get('/kasir/laporan', { params }),
}

/** RBAC — Users / Roles / Permissions (Superadmin only) */
export const userApi = {
  list:          (params)     => api.get('/rbac/users', { params }),
  show:          (id)         => api.get(`/rbac/users/${id}`),
  create:        (data)       => api.post('/rbac/users', data),
  update:        (id, data)   => api.put(`/rbac/users/${id}`, data),
  remove:        (id)         => api.delete(`/rbac/users/${id}`),
  toggleAktif:   (id)         => api.patch(`/rbac/users/${id}/toggle-aktif`),
  resetPassword: (id, data)   => api.put(`/rbac/users/${id}/reset-password`, data ?? {}),
}

export const roleApi = {
  list:            ()           => api.get('/rbac/roles'),
  show:            (id)         => api.get(`/rbac/roles/${id}`),
  create:          (data)       => api.post('/rbac/roles', data),
  update:          (id, data)   => api.put(`/rbac/roles/${id}`, data),
  remove:          (id)         => api.delete(`/rbac/roles/${id}`),
  syncPermissions: (id, ids)    => api.put(`/rbac/roles/${id}/permissions`, { permission_ids: ids }),
}

export const permissionApi = {
  list: () => api.get('/rbac/permissions'),
  flat: () => api.get('/rbac/permissions/flat'),
  updateModuleLabel: (module, label) => api.put(`/rbac/permissions/module-label/${module}`, { label }),
  resetModuleLabel:  (module)        => api.post(`/rbac/permissions/module-label/${module}/reset`),
}

export default api
