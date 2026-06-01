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
  kirimKeBedah:    (queueId)          => api.post(`/perawat/antrian/${queueId}/kirim-ke-bedah`),
  kirimKeRanap:    (queueId)          => api.post(`/perawat/antrian/${queueId}/kirim-ke-ranap`),

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
  verifyPin:        (pin)                 => api.post('/dokter/verify-pin', { pin }),
  antrian:          ()                    => api.get('/dokter/antrian'),
  panggil:          (id)                  => api.put(`/dokter/antrian/${id}/panggil`),
  selesai:          (id)                  => api.put(`/dokter/antrian/${id}/selesai`),
  kePenunjang:      (id)                  => api.put(`/dokter/antrian/${id}/ke-penunjang`),

  kunjungan:        (visitId)             => api.get(`/dokter/kunjungan/${visitId}`),
  finalize:         (visitId)             => api.post(`/dokter/kunjungan/${visitId}/finalize`),
  // Riwayat SOAP/CPPT lintas-kunjungan pasien (RME aggregator) untuk kartu "SOAP / CPPT".
  riwayatKunjungan: (patientId)           => api.get(`/rekam-medis/pasien/${patientId}/kunjungan`),
  // Riwayat hasil penunjang lintas-kunjungan (RME aggregator) untuk kartu sidebar.
  riwayatPenunjang: (patientId)           => api.get(`/rekam-medis/pasien/${patientId}/penunjang`),

  tarifTindakan:    (visitId)             => api.get('/dokter/tarif-tindakan', { params: { visit_id: visitId } }),
  daftarObat:       (search)              => api.get('/dokter/obat', { params: { search } }),
  bedahSlot:        (tanggal)             => api.get('/dokter/bedah/slot', { params: { tanggal } }),

  // Rujukan internal antar-poli (mis. Poli Mata Umum → Poli Retina)
  rujukInternalTargets: (visitId)         => api.get(`/dokter/kunjungan/${visitId}/rujuk-internal/targets`),
  rujukInternal:    (visitId, data)       => api.post(`/dokter/kunjungan/${visitId}/rujuk-internal`, data),
  // Rujukan keluar (faskes lain). Pasien BPJS → terbit ke VClaim.
  rujukanKeluar:    (data)                => api.post('/dokter/rujukan-keluar', data),
  // Surat Kontrol BPJS (planning Pulang) — status + terbitkan ke VClaim
  getSuratKontrol:  (visitId)             => api.get(`/dokter/kunjungan/${visitId}/surat-kontrol`),
  submitSuratKontrol: (visitId)           => api.post(`/dokter/kunjungan/${visitId}/surat-kontrol/submit`),

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
  penunjangBilling:    (visitId)          => api.get(`/dokter/kunjungan/${visitId}/penunjang-billing`),
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
    uploadLogo: (file) => {
      const fd = new FormData()
      fd.append('file', file)
      return api.post('/master/profil-klinik/logo', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
        timeout: 30_000,
      })
    },
    deleteLogo: () => api.delete('/master/profil-klinik/logo'),
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

  // Billing Categories (kategori grouping rincian tagihan Kasir)
  kategoriTagihan: {
    list:    (params)   => api.get('/master/kategori-tagihan', { params }),
    create:  (data)     => api.post('/master/kategori-tagihan', data),
    update:  (id, data) => api.put(`/master/kategori-tagihan/${id}`, data),
    remove:  (id)       => api.delete(`/master/kategori-tagihan/${id}`),
    reorder: (rows)     => api.put('/master/kategori-tagihan/reorder', { rows }),
  },

  // Paket Bedah (surgery_packages)
  paketBedah: {
    list:   (params)   => api.get('/master/paket-bedah', { params }),
    create: (data)     => api.post('/master/paket-bedah', data),
    update: (id, data) => api.put(`/master/paket-bedah/${id}`, data),
    remove: (id)       => api.delete(`/master/paket-bedah/${id}`),
  },

  // Pegawai (employees) — dipakai mis. combobox Tim Bedah
  pegawai: {
    list:   (params)   => api.get('/master/pegawai', { params }),
    show:   (id)       => api.get(`/master/pegawai/${id}`),
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

  // Alat Medis (master microscope/Phaco/biometri/dll — Fase 3 billing)
  alatMedis: {
    list:   (params)   => api.get('/inventori-farmasi/alat-medis', { params }),
    create: (data)     => api.post('/inventori-farmasi/alat-medis', data),
    update: (id, data) => api.put(`/inventori-farmasi/alat-medis/${id}`, data),
    remove: (id)       => api.delete(`/inventori-farmasi/alat-medis/${id}`),
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
 * Form Registry — modul template form rekam medis (Fase 1+2).
 *
 * - `formTemplate.*`  : CRUD + activate/deactivate template
 * - `formTemplate.parse(file)` : upload .docx → sync parse, return draft
 * - `formTemplate.parseResult(id)` : poll cache result (TTL 1 jam)
 * - `fieldRegistry()` / `stationSections()` : meta untuk mapper UI
 * - `forms(...)` / `renderForm(...)` : runtime endpoint untuk station Vue
 */
export const formTemplateApi = {
  // ─── Master (admin wizard) ─────────────────────────────────────────────
  list:        (params)   => api.get('/master/form-template', { params }),
  show:        (id)       => api.get(`/master/form-template/${id}`),
  create:      (data)     => api.post('/master/form-template', data),
  update:      (id, data) => api.put(`/master/form-template/${id}`, data),
  activate:    (id)       => api.post(`/master/form-template/${id}/activate`),
  deactivate:  (id)       => api.post(`/master/form-template/${id}/deactivate`),

  // Parser .docx (sync, cache 1 jam)
  upload: (file) => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post('/master/form-template/upload', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 60_000, // parser bisa agak lama
    })
  },
  parseResult: (parseId) => api.get(`/master/form-template/parse-result/${parseId}`),

  // Meta untuk mapper UI
  fieldRegistry:   () => api.get('/master/field-registry'),
  stationSections: () => api.get('/master/station-sections'),
  documentTypes:   (params)   => api.get('/master/document-types', { params }),
  // ─── Master DocumentType CRUD (admin) ──────────────────────────────────
  createDocumentType:  (data)     => api.post('/master/document-type', data),
  updateDocumentType:  (id, data) => api.put(`/master/document-type/${id}`, data),
  deleteDocumentType:  (id)       => api.delete(`/master/document-type/${id}`),

  // ─── Runtime (station Vue) ─────────────────────────────────────────────
  forms:       (params) => api.get('/rekam-medis/forms', { params }),
  renderForm:  (code, visitId) => api.get(`/rekam-medis/form/${code}/render`, { params: { visit_id: visitId } }),
  submitForm:  (code, visitId, data) => api.post(`/rekam-medis/form/${code}/submit`, { visit_id: visitId, data }),
  markRendered:(docId) => api.post(`/rekam-medis/document/${docId}/mark-rendered`),
  finalize:    (docId, signatureIds = []) => api.post(`/rekam-medis/document/${docId}/finalize`, { signature_ids: signatureIds }),
  snapshot:    (docId) => api.get(`/rekam-medis/document/${docId}/render`),
  saveDraftContent: (docId, renderedHtml) => api.put(`/rekam-medis/document/${docId}/draft-content`, { rendered_html: renderedHtml }),

  // ─── Fase 4 — Signature flow ───────────────────────────────────────────
  sign:                (docId, payload) => api.post(`/rekam-medis/document/${docId}/sign`, payload),
  listSignatures:      (docId) => api.get(`/rekam-medis/document/${docId}/signatures`),
  verifySignature:     (sigId) => api.get(`/rekam-medis/signature/${sigId}/verify`),
  auditSignature:      (sigId) => api.get(`/rekam-medis/signature/${sigId}/audit`),
  ttdQueue:            () => api.get('/rekam-medis/ttd-queue'),
  createAddendum:      (docId, payload) => api.post(`/rekam-medis/document/${docId}/addendum`, payload),
  auditLog:            (docId) => api.get(`/rekam-medis/document/${docId}/audit-log`),
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

  // --- Paket Bedah CRUD + CSV ---
  paket: {
    list:   (params)   => api.get('/tarif-paket/paket-bedah', { params }),
    show:   (id)       => api.get(`/tarif-paket/paket-bedah/${id}`),
    create: (data)     => api.post('/tarif-paket/paket-bedah', data),
    update: (id, data) => api.put(`/tarif-paket/paket-bedah/${id}`, data),
    remove: (id)       => api.delete(`/tarif-paket/paket-bedah/${id}`),
    csvTemplate: ()    => api.get('/tarif-paket/paket-bedah/template-csv', { responseType: 'blob' }),
    csvExport:   ()    => api.get('/tarif-paket/paket-bedah/export-csv',   { responseType: 'blob' }),
    csvImport:   (file) => {
      const fd = new FormData()
      fd.append('file', file)
      return api.post('/tarif-paket/paket-bedah/import-csv', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    },
    // CSV per-paket (dari halaman detail) — template/export komposisi 1 paket
    csvTemplateOne: (id) => api.get(`/tarif-paket/paket-bedah/${id}/template-csv`, { responseType: 'blob' }),
    csvExportOne:   (id) => api.get(`/tarif-paket/paket-bedah/${id}/export-csv`,   { responseType: 'blob' }),
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

/**
 * Master Fasilitas & Ruang (RANAP) — Room / Bed / Tarif Kamar.
 * Dipakai RoomBedManager.vue & RawatInapView.vue. Endpoint: /master/room|bed|room-tariff.
 */
export const roomApi = {
  list:       ()             => api.get('/master/room'),
  store:      (data)         => api.post('/master/room', data),
  update:     (id, data)     => api.put(`/master/room/${id}`, data),
  destroy:    (id)           => api.delete(`/master/room/${id}`),
  addBed:     (roomId, data) => api.post(`/master/room/${roomId}/bed`, data),
  updateBed:  (id, data)     => api.put(`/master/bed/${id}`, data),
  destroyBed: (id)           => api.delete(`/master/bed/${id}`),
}

/** Tarif Kamar (RANAP) — per kelas/penjamin. Endpoint: /master/room-tariff. */
export const roomTarifApi = {
  list:    ()      => api.get('/master/room-tariff'),
  upsert:  (data)  => api.post('/master/room-tariff', data),
  destroy: (id)    => api.delete(`/master/room-tariff/${id}`),
}

/**
 * Rawat Inap (RANAP) — papan bed, admit/transfer/discharge, CPPT,
 * tindakan/obat/charge, SEP/SPRI, riwayat. Endpoint: /rawat-inap/*.
 */
export const ranapApi = {
  // Papan & daftar
  bedBoard:      ()                  => api.get('/rawat-inap/bed-board'),
  menungguKamar: ()                  => api.get('/rawat-inap/menunggu-kamar'),
  aktif:         ()                  => api.get('/rawat-inap/aktif'),
  history:       (dateFrom, dateTo)  => api.get('/rawat-inap/history', { params: { date_from: dateFrom, date_to: dateTo } }),
  detail:        (visitId)           => api.get(`/rawat-inap/${visitId}`),

  // Aksi pasien
  admit:         (visitId, payload)  => api.post(`/rawat-inap/${visitId}/admit`, payload),
  transfer:      (visitId, payload)  => api.post(`/rawat-inap/${visitId}/transfer`, payload),
  discharge:     (visitId, payload)  => api.post(`/rawat-inap/${visitId}/discharge`, payload),
  kirimBedah:    (visitId, payload)  => api.post(`/rawat-inap/${visitId}/kirim-bedah`, payload),

  // Fase 8C — dokumen/hasil eksternal (lab/radiologi pihak ke-3 pre-op).
  documents:      (visitId)           => api.get(`/rawat-inap/${visitId}/dokumen`),
  uploadDocument: (visitId, formData) => api.post(`/rawat-inap/${visitId}/dokumen`, formData, { headers: { 'Content-Type': 'multipart/form-data' } }),
  deleteDocument: (visitId, docId)    => api.delete(`/rawat-inap/${visitId}/dokumen/${docId}`),

  // Charge / tindakan / obat
  tarifTindakan: (visitId)           => api.get(`/rawat-inap/${visitId}/tarif-tindakan`),
  daftarObat:    (visitId, search)   => api.get(`/rawat-inap/${visitId}/daftar-obat`, { params: { search } }),
  addCharge:     (visitId, payload)  => api.post(`/rawat-inap/${visitId}/charge`, payload),
  addTindakan:   (visitId, payload)  => api.post(`/rawat-inap/${visitId}/tindakan`, payload),
  addObat:       (visitId, payload)  => api.post(`/rawat-inap/${visitId}/obat`, payload),
  deleteCharge:  (visitId, chargeId) => api.delete(`/rawat-inap/${visitId}/charge/${chargeId}`),

  // CPPT
  cpptList:      (visitId)           => api.get(`/rawat-inap/${visitId}/cppt`),
  addCppt:       (visitId, payload)  => api.post(`/rawat-inap/${visitId}/cppt`, payload),
  updateCppt:    (cpptId, payload)   => api.put(`/rawat-inap/cppt/${cpptId}`, payload),
  verifyCppt:    (cpptId)            => api.post(`/rawat-inap/cppt/${cpptId}/verify`),

  // SEP / SPRI
  getSep:        (visitId)           => api.get(`/rawat-inap/${visitId}/sep`),
  updateSep:     (visitId, payload)  => api.put(`/rawat-inap/${visitId}/sep`, payload),
  listSpri:      (visitId)           => api.get(`/rawat-inap/${visitId}/spri`),
  createSpri:    (visitId, payload)  => api.post(`/rawat-inap/${visitId}/spri`, payload),
  updateSpri:    (spriId, payload)   => api.put(`/rawat-inap/spri/${spriId}`, payload),
  deleteSpri:    (spriId)            => api.delete(`/rawat-inap/spri/${spriId}`),
}

/** IGD — Instalasi Gawat Darurat (papan triase berlevel, 1 stasiun gabung). */
export const igdApi = {
  // Papan & detail
  board:         ()                  => api.get('/igd/board'),
  detail:        (visitId)           => api.get(`/igd/${visitId}`),

  // Registrasi darurat
  register:      (payload)           => api.post('/igd/register', payload),
  registerNew:   (payload)           => api.post('/igd/register-baru', payload),

  // Triase + charge/tindakan/obat
  triase:        (visitId, payload)  => api.post(`/igd/${visitId}/triase`, payload),
  tarifTindakan: (visitId)           => api.get(`/igd/${visitId}/tarif-tindakan`),
  daftarObat:    (visitId, search)   => api.get(`/igd/${visitId}/daftar-obat`, { params: { search } }),
  addTindakan:   (visitId, payload)  => api.post(`/igd/${visitId}/tindakan`, payload),
  addObat:       (visitId, payload)  => api.post(`/igd/${visitId}/obat`, payload),
  deleteCharge:  (visitId, chargeId) => api.delete(`/igd/${visitId}/charge/${chargeId}`),
  disposisi:     (visitId, payload)  => api.post(`/igd/${visitId}/disposisi`, payload),

  // CPPT IGD
  cpptList:      (visitId)           => api.get(`/igd/${visitId}/cppt`),
  addCppt:       (visitId, payload)  => api.post(`/igd/${visitId}/cppt`, payload),
  updateCppt:    (cpptId, payload)   => api.put(`/igd/cppt/${cpptId}`, payload),
  verifyCppt:    (cpptId)            => api.post(`/igd/cppt/${cpptId}/verify`),

  // SEP IGD (BPJS gawat darurat)
  sepInfo:       (visitId)           => api.get(`/igd/${visitId}/sep`),
  generateSep:   (visitId, payload)  => api.post(`/igd/${visitId}/sep`, payload),
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
  mediaSettings:       () => api.get('/antrean-tv/media-settings'),
  updateMedia:         (payload) => api.put('/antrean-tv/media-settings', payload),
  uploadMediaVideo:    (formData, onProgress, signal) => api.post('/antrean-tv/media-settings/video', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 15 * 60 * 1000, // 15 menit — file 500MB di koneksi pelan
    onUploadProgress: onProgress, // (e) => { loaded, total }
    signal, // AbortController.signal — untuk cancel upload
  }),
  deleteMediaVideo:    () => api.delete('/antrean-tv/media-settings/video'),
}

/** Jadwal Dokter */
export const jadwalDokterApi = {
  list:          (params)     => api.get('/jadwal-dokter', { params }),
  aktifHariIni:  ()           => api.get('/jadwal-dokter/aktif-hari-ini'),
  availableWeeks:()           => api.get('/jadwal-dokter/minggu-tersedia'),
  show:          (id)         => api.get(`/jadwal-dokter/${id}`),
  create:        (data)       => api.post('/jadwal-dokter', data),
  update:        (id, data)   => api.put(`/jadwal-dokter/${id}`, data),
  remove:        (id)         => api.delete(`/jadwal-dokter/${id}`),
  toggle:        (id)         => api.patch(`/jadwal-dokter/${id}/toggle`),
  copyNextWeek:  (weekStart)  => api.post('/jadwal-dokter/salin-minggu-depan', { week_start: weekStart }),
  csvTemplate:   ()           => api.get('/jadwal-dokter/template-csv', { responseType: 'blob' }),
  csvImport:     (file, weekStart) => {
    const fd = new FormData()
    fd.append('file', file)
    if (weekStart) fd.append('week_start', weekStart)
    return api.post('/jadwal-dokter/import-csv', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },
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
  jadwalBedahAktif: (id)        => api.get(`/admisi/pasien/${id}/jadwal-bedah-aktif`),
  updatePasien:  (id, data)     => api.put(`/admisi/pasien/${id}`, data),
  daftar:        (data)         => api.post('/admisi/daftar', data),
  daftarkanWalkIn: (visitId, data) => api.put(`/admisi/kunjungan/${visitId}/daftarkan-walkin`, data),
  previewConsent: (data)        => api.post('/admisi/consent/preview', data),

  // BPJS (VClaim/Antrean) — cek peserta/rujukan + terbitkan/batal SEP
  bpjs: {
    cekPeserta:      (data) => api.post('/admisi/bpjs/cek-peserta', data),
    generateSep:     (data) => api.post('/admisi/bpjs/generate-sep', data),
    updateSep:       (data) => api.put('/admisi/bpjs/update-sep', data),
    cancelSep:       (data) => api.post('/admisi/bpjs/cancel-sep', data),
    cekRujukan:      (data) => api.post('/admisi/bpjs/cek-rujukan', data),
    cekSuratKontrol: (data) => api.post('/admisi/bpjs/cek-surat-kontrol', data),
    getSuratKontrol: (visitId) => api.get(`/admisi/bpjs/surat-kontrol/${visitId}`),
    editSuratKontrol:(data) => api.put('/admisi/bpjs/edit-surat-kontrol', data),
    validasiBooking: (data) => api.post('/admisi/bpjs/validasi-booking', data),
  },
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
  updateItem:        (id, data)   => api.put(`/farmasi/resep-item/${id}`, data),
  deleteItem:        (id)         => api.delete(`/farmasi/resep-item/${id}`),
  // Penjualan obat tambahan (OTC) untuk pasien antrean Farmasi tanpa resep dokter.
  storeOtc:          (visitId, items) => api.post(`/farmasi/kunjungan/${visitId}/resep-otc`, { items }),

  // Stok
  stokObat:          (params)     => api.get('/farmasi/stok/obat', { params }),
  updateStokObat:    (id, data)   => api.put(`/farmasi/stok/obat/${id}`, data),
  stokAlert:         ()           => api.get('/farmasi/stok/alert'),

  // Penjualan obat bebas (POS apotek) — walk-in tanpa resep/kunjungan.
  penjualanList:     (params)     => api.get('/farmasi/penjualan', { params }),
  penjualanCreate:   (data)       => api.post('/farmasi/penjualan', data),
  penjualanShow:     (id)         => api.get(`/farmasi/penjualan/${id}`),
  penjualanBatal:    (id, data)   => api.post(`/farmasi/penjualan/${id}/batal`, data),
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

/** Inventori Farmasi — Alat Medis (master + tarif + usage) */
export const alatMedisApi = {
  list:           (params)         => api.get('/inventori-farmasi/alat-medis', { params }),
  show:           (id)             => api.get(`/inventori-farmasi/alat-medis/${id}`),
  create:         (data)           => api.post('/inventori-farmasi/alat-medis', data),
  update:         (id, data)       => api.put(`/inventori-farmasi/alat-medis/${id}`, data),
  remove:         (id)             => api.delete(`/inventori-farmasi/alat-medis/${id}`),

  // Tarif per insurer
  listTariffs:    (id)             => api.get(`/inventori-farmasi/alat-medis/${id}/tarif`),
  upsertTariff:   (id, data)       => api.post(`/inventori-farmasi/alat-medis/${id}/tarif`, data),
  deleteTariff:   (tariffId)       => api.delete(`/inventori-farmasi/alat-medis/tarif/${tariffId}`),

  // Usage (dipakai dari BedahView)
  usagesByVisit:  (visitId)        => api.get(`/alat-medis/visit/${visitId}/usages`),
  recordUsage:    (data)           => api.post('/alat-medis/usage', data),
  deleteUsage:    (id)             => api.delete(`/alat-medis/usage/${id}`),
}

/** Inventori Farmasi — Snapshot stok gudang per tipe (MEDICATION/BHP/IOL) */
export const inventoriStockApi = {
  list: (type, params) => api.get(`/inventori-farmasi/stock/${type}`, { params }),
  opname: (payload) => api.post('/inventori-farmasi/stock/opname', payload),
  templateCsv: (type) => api.get(`/inventori-farmasi/stock/${type}/template-csv`, { responseType: 'blob' }),
  exportCsv:   (type, location) => api.get(`/inventori-farmasi/stock/${type}/export-csv`, { params: location ? { location } : {}, responseType: 'blob' }),
  importCsv:   (type, file, location) => {
    const fd = new FormData()
    fd.append('file', file)
    if (location) fd.append('location', location)
    return api.post(`/inventori-farmasi/stock/${type}/import-csv`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },
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

  // CSV import/export per tipe
  templateCsv: (type)              => api.get(`/inventori-farmasi/harga/${type}/template-csv`, { responseType: 'blob' }),
  exportCsv:   (type)              => api.get(`/inventori-farmasi/harga/${type}/export-csv`, { responseType: 'blob' }),
  importCsv:   (type, file) => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post(`/inventori-farmasi/harga/${type}/import-csv`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 60_000,
    })
  },
}

/** Bedah — antrian + flow KASIR (action operasi detail belum diwire) */
export const bedahApi = {
  antrian:        ()    => api.get('/bedah/antrian'),
  panggilAntrian: (id)  => api.put(`/bedah/antrian/${id}/panggil`),
  selesaiAntrian: (id)  => api.put(`/bedah/antrian/${id}/selesai`),

  // Jadwal operasi (surgery_schedules) — bedah terjadwal
  jadwal:         (params) => api.get('/bedah/jadwal', { params }),
  showJadwal:     (id)     => api.get(`/bedah/jadwal/${id}`),

  // 1-klik request BHP/IOL dari komposisi paket bedah jadwal
  autoRequestPreview: (scheduleId)       => api.get(`/bedah/jadwal/${scheduleId}/auto-request/preview`),
  sendAutoRequest:    (scheduleId, data) => api.post(`/bedah/jadwal/${scheduleId}/auto-request`, data),

  // Surgery requests (BHP/IOL dari Farmasi)
  listRequests:   (params) => api.get('/bedah/request', { params }),
  showRequest:    (id)     => api.get(`/bedah/request/${id}`),
  storeRequest:   (data)   => api.post('/bedah/request', data),
  kirimRequest:   (id)     => api.put(`/bedah/request/${id}/kirim`),
  terimaRequest:  (id)     => api.put(`/bedah/request/${id}/terima`),
  adjustBhpUsage: (id, items) => api.post(`/bedah/request/${id}/adjust-bhp`, { items }),

  // Operasi lifecycle (jadwal): Sign In → Time Out
  mulaiOperasi:   (id)       => api.put(`/bedah/jadwal/${id}/mulai`),
  selesaiOperasi: (id, data) => api.put(`/bedah/jadwal/${id}/selesai`, data),

  // Laporan operasi (surgery_records): laporan + post-op + finalize
  showRecord:     (scheduleId) => api.get(`/bedah/record/${scheduleId}`),
  storeRecord:    (data)       => api.post('/bedah/record', data),
  updateRecord:   (id, data)   => api.put(`/bedah/record/${id}`, data),
  storePostOp:    (id, data)   => api.put(`/bedah/record/${id}/post-op`, data),
  finalizeRecord: (id)         => api.post(`/bedah/record/${id}/finalize`),
}

/** Penunjang — antrian, order, hasil pemeriksaan */
export const penunjangApi = {
  // Antrian
  antrian:           ()           => api.get('/penunjang/antrian'),
  panggilAntrian:    (id)         => api.put(`/penunjang/antrian/${id}/panggil`),
  lewatiAntrian:     (id)         => api.put(`/penunjang/antrian/${id}/lewati`),
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
  uploadHasilAttachment: (file)   => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post('/penunjang/hasil/upload-attachment', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },
}

/** Kasir — antrian, invoice, pembayaran, laporan */
export const kasirApi = {
  // Antrian
  antrian:           ()                   => api.get('/kasir/antrian'),
  panggilAntrian:    (id)                 => api.put(`/kasir/antrian/${id}/panggil`),
  lewatiAntrian:     (id)                 => api.put(`/kasir/antrian/${id}/lewati`),
  selesaiAntrian:    (id)                 => api.put(`/kasir/antrian/${id}/selesai`),

  // Invoice
  invoiceList:       (params)             => api.get('/kasir/invoice', { params }),
  showInvoice:       (visitId)            => api.get(`/kasir/invoice/${visitId}`),
  insuranceWarning:  (visitId)            => api.get(`/kasir/insurance-warning/${visitId}`),
  generateInvoice:   (visitId)            => api.post(`/kasir/invoice/${visitId}/generate`),
  updateInvoice:     (id, data)           => api.put(`/kasir/invoice/${id}`, data),
  finalizeInvoice:   (id)                 => api.post(`/kasir/invoice/${id}/finalize`),
  bayarInvoice:      (id, data)           => api.post(`/kasir/invoice/${id}/bayar`, data),
  confirmCoverage:   (id, data)           => api.post(`/kasir/invoice/${id}/confirm-coverage`, data),
  confirmBpjs:       (id, data)           => api.post(`/kasir/invoice/${id}/confirm-bpjs`, data ?? {}),
  cancelInvoice:     (id)                 => api.post(`/kasir/invoice/${id}/cancel`),
  cetakInvoice:      (id)                 => api.get(`/kasir/invoice/${id}/cetak`),

  // Billing items (override saat edit tagihan)
  storeItem:         (invoiceId, data)    => api.post(`/kasir/invoice/${invoiceId}/item`, data),
  updateItem:        (id, data)           => api.put(`/kasir/invoice-item/${id}`, data),
  deleteItem:        (id)                 => api.delete(`/kasir/invoice-item/${id}`),

  // Tarif tindakan per-penjamin (Edit Tagihan — pilih dari master)
  tarifTindakan:     (visitId)            => api.get('/kasir/tarif-tindakan', { params: { visit_id: visitId } }),

  // Setting cetak kwitansi/rincian (toggle logo/stempel/e-sign/footer/watermark)
  getPrintSettings:  ()                   => api.get('/kasir/print-settings'),
  updatePrintSettings: (data)             => api.put('/kasir/print-settings', data),

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
  resetPin:      (id)         => api.put(`/rbac/users/${id}/reset-pin`),
  csvTemplate:   ()           => api.get('/rbac/users/csv-template', { responseType: 'blob' }),
  exportCsv:     ()           => api.get('/rbac/users/export',       { responseType: 'blob' }),
  importCsv:     (file)       => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post('/rbac/users/import', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },
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

/** Asuransi/TPA Non-BPJS — verifikasi eligibility + workflow klaim */
export const asuransiApi = {
  // Verifikasi eligibility (input manual hasil cek portal TPA)
  pendingVerifications: (params)    => api.get('/asuransi/verifikasi/pending', { params }),
  inServiceVerifications: (params)  => api.get('/asuransi/verifikasi/in-service', { params }),
  getVerifikasi:        (visitId)   => api.get(`/asuransi/verifikasi/${visitId}`),
  getBilling:           (visitId)   => api.get(`/asuransi/billing/${visitId}`),
  createVerifikasi:     (data)      => api.post('/asuransi/verifikasi', data),
  updateVerifikasi:     (id, data)  => api.put(`/asuransi/verifikasi/${id}`, data),

  // Klaim
  listKlaim:        (params)        => api.get('/asuransi/klaim', { params }),
  showKlaim:        (id)            => api.get(`/asuransi/klaim/${id}`),
  createKlaim:      (data)          => api.post('/asuransi/klaim', data),
  updateKlaim:      (id, data)      => api.put(`/asuransi/klaim/${id}`, data),
  submitKlaim:      (id, data)      => api.post(`/asuransi/klaim/${id}/submit`, data),
  updateStatus:     (id, data)      => api.put(`/asuransi/klaim/${id}/status`, data),
  resubmitKlaim:    (id, data)      => api.post(`/asuransi/klaim/${id}/resubmit`, data),
  logsKlaim:        (id)            => api.get(`/asuransi/klaim/${id}/logs`),

  // Laporan
  aging:            ()              => api.get('/asuransi/aging'),
  outstanding:      ()              => api.get('/asuransi/outstanding'),
  summary:          ()              => api.get('/asuransi/summary'),

  // Master — Document Requirements per TPA
  listDocReq:       (insurerId)         => api.get(`/asuransi/insurer/${insurerId}/dokumen-requirement`),
  createDocReq:     (insurerId, data)   => api.post(`/asuransi/insurer/${insurerId}/dokumen-requirement`, data),
  updateDocReq:     (id, data)          => api.put(`/asuransi/dokumen-requirement/${id}`, data),
  deleteDocReq:     (id)                => api.delete(`/asuransi/dokumen-requirement/${id}`),
}

// ─── Integrasi / Bridging BPJS (VClaim + Antrean + SatuSehat) ───────────────
export const integrasiApi = {
  // Status & konfigurasi semua sistem
  status:        ()              => api.get('/integrasi/status'),
  listConfig:    ()              => api.get('/integrasi/config'),
  updateConfig:  (id, data)      => api.put(`/integrasi/config/${id}`, data),
  testKoneksi:   (system)        => api.post(`/integrasi/test/${system}`),

  // Log audit
  vclaimLog:     (params)        => api.get('/integrasi/bpjs/vclaim-log', { params }),
  antreanLog:    (params)        => api.get('/integrasi/bpjs/antrean-log', { params }),
  icareLog:      (params)        => api.get('/integrasi/bpjs/icare-log', { params }),

  // VClaim live calls
  cekPeserta:    (data)          => api.post('/integrasi/vclaim/cek-peserta', data),
  cekRujukan:    (data)          => api.post('/integrasi/vclaim/cek-rujukan', data),
  rujukanByKartu:(noKartu, p)    => api.get(`/integrasi/vclaim/rujukan-peserta/${noKartu}`, { params: p }),
  generateSep:   (data)          => api.post('/integrasi/vclaim/sep', data),
  updateSep:     (data)          => api.put('/integrasi/vclaim/sep', data),
  cancelSep:     (data)          => api.delete('/integrasi/vclaim/sep', { data }),
  insertLpk:     (data)          => api.post('/integrasi/vclaim/lpk', data),
  monitoring:    (jenis, p)      => api.get(`/integrasi/vclaim/monitoring/${jenis}`, { params: p }),
  referensi:     (jenis, p)      => api.get(`/integrasi/vclaim/referensi/${jenis}`, { params: p }),

  // Antrean live calls
  antreanAdd:        (data)      => api.post('/integrasi/antrean/add', data),
  antreanUpdateWaktu:(data)      => api.post('/integrasi/antrean/updatewaktu', data),
  antreanBatal:      (data)      => api.post('/integrasi/antrean/batal', data),
  antreanDashboard:  (jenis, p)  => api.get(`/integrasi/antrean/dashboard/${jenis}`, { params: p }),
  validateBooking:   (data)      => api.post('/integrasi/antrean/validate-booking', data),

  // Mapping Poli/DPJP BPJS (sinkron Jadwal Dokter)
  poliMapping:        ()         => api.get('/integrasi/bpjs/poli-mapping'),
  poliMappingStatus:  ()         => api.get('/integrasi/bpjs/poli-mapping/status'),
  upsertPoliMapping:  (data)     => api.post('/integrasi/bpjs/poli-mapping', data),
  deletePoliMapping:  (id)       => api.delete(`/integrasi/bpjs/poli-mapping/${id}`),
  setDpjpCode:        (empId, d) => api.put(`/integrasi/bpjs/dokter/${empId}/dpjp`, d),
  syncJadwalDokter:   (data)     => api.post('/integrasi/bpjs/sync-jadwal-dokter', data),

  // Satu Sehat (dashboard monitoring + sync/log/retry)
  satusehatDashboard: (params)   => api.get('/integrasi/satusehat/dashboard', { params }),
  satusehatSyncManual:()         => api.post('/integrasi/satusehat/sync-manual'),
  satusehatRetry:     (logId)    => api.post(`/integrasi/satusehat/retry/${logId}`),
  satusehatSyncLog:   (params)   => api.get('/integrasi/satusehat/sync-log', { params }),
  satusehatResourceLog:(params)  => api.get('/integrasi/satusehat/resource-log', { params }),
  satusehatKfaSearch: (params)   => api.get('/integrasi/satusehat/kfa-search', { params }),
  setEmployeeNik:     (empId, d) => api.put(`/integrasi/satusehat/dokter/${empId}/nik`, d),

  // Satu Sehat Location (daftar/registrasi/edit/nonaktif/set-aktif)
  satusehatLocations:       ()       => api.get('/integrasi/satusehat/location'),
  satusehatRegisterLocation:(data)   => api.post('/integrasi/satusehat/location', data),
  satusehatUpdateLocation:  (id, d)  => api.put(`/integrasi/satusehat/location/${id}`, d),
  satusehatDeleteLocation:  (id, p)  => api.delete(`/integrasi/satusehat/location/${id}`, { params: p }),
  satusehatSetActiveLocation:(id)    => api.put(`/integrasi/satusehat/location/${id}/active`),
}

export default api
