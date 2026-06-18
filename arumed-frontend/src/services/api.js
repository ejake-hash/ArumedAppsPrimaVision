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
  async (error) => {
    // Request dgn responseType:'blob' (export/template CSV) → body error pun Blob, bukan JSON.
    // Urai dulu jadi JSON agar error.response.data.message terbaca handler & pesan tampil.
    const data = error.response?.data
    if (data instanceof Blob && /json/i.test(data.type || '')) {
      try {
        error.response.data = JSON.parse(await data.text())
      } catch { /* biarkan apa adanya bila bukan JSON valid */ }
    }

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
  changePin:      (data)      => api.put('/auth/pin', data),
  resetToDefault: ()          => api.post('/auth/reset-to-default'),
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
  dahulukan:       (id)               => api.put(`/perawat/antrian/${id}/dahulukan`),
  skipTriase:      (id)               => api.put(`/perawat/antrian/${id}/skip`),
  kirimKeBedah:    (queueId)          => api.post(`/perawat/antrian/${queueId}/kirim-ke-bedah`),
  kirimKeDokter:   (queueId)          => api.post(`/perawat/antrian/${queueId}/kirim-ke-dokter`),
  kirimKeRanap:    (queueId)          => api.post(`/perawat/antrian/${queueId}/kirim-ke-ranap`),

  // Instruksi obat pre-op dokter jaga (stat-dose, visit PREOP_BEDAH)
  preopResep:      (visitId)          => api.get(`/perawat/kunjungan/${visitId}/preop-resep`),
  storePreopResep: (visitId, data)    => api.post(`/perawat/kunjungan/${visitId}/preop-resep`, data),
  daftarObat:      (search)           => api.get('/perawat/obat', { params: { search } }),

  showAsesmen:     (visitId)          => api.get(`/perawat/asesmen/${visitId}`),
  storeAsesmen:    (data)             => api.post('/perawat/asesmen', data),
  updateAsesmen:   (id, data)         => api.put(`/perawat/asesmen/${id}`, data),
  finalizeAsesmen: (id)               => api.post(`/perawat/asesmen/${id}/finalize`),
  reopenAsesmen:   (id)               => api.post(`/perawat/asesmen/${id}/reopen`),

  // CPPT — timeline append + soft-edit + tanda tangan PIN (paraf PPA)
  cpptList:        (visitId)          => api.get(`/perawat/cppt/visit/${visitId}`),
  cpptCreate:      (data)             => api.post('/perawat/cppt', data),
  cpptUpdate:      (id, data)         => api.put(`/perawat/cppt/${id}`, data),
  cpptSign:        (id, pin)          => api.post(`/perawat/cppt/${id}/sign`, { pin }),

  vitalHistory:    (patientId)        => api.get(`/perawat/pasien/${patientId}/vital-history`),
  rekamMedis:      (patientId)        => api.get(`/perawat/pasien/${patientId}/rekam-medis`),
  // CPPT lintas-episode terpadu (kartu SOAP/CPPT) — sumber sama dgn DokterView.
  riwayatCppt:     (patientId)        => api.get(`/perawat/pasien/${patientId}/cppt`),
  dokumen:         (documentId)       => api.get(`/perawat/dokumen/${documentId}`),
  statusParallel:  (visitId)          => api.get(`/perawat/kunjungan/${visitId}/status-parallel`),
}

/** Dokter — RME, antrian, tindakan, resep, penunjang */
export const dokterApi = {
  verifyPin:        (pin)                 => api.post('/dokter/verify-pin', { pin }),
  antrian:          ()                    => api.get('/dokter/antrian'),
  panggil:          (id)                  => api.put(`/dokter/antrian/${id}/panggil`),
  lewati:           (id)                  => api.put(`/dokter/antrian/${id}/lewati`),
  selesai:          (id)                  => api.put(`/dokter/antrian/${id}/selesai`),
  kePenunjang:      (id)                  => api.put(`/dokter/antrian/${id}/ke-penunjang`),
  // Batalkan kunjungan pasien yang sudah di stasiun Dokter (Admisi terkunci di fase ini).
  // Hanya dokter pemilik / superadmin — guard di DokterService::cancelKunjungan.
  cancelKunjungan:  (visitId)             => api.put(`/dokter/kunjungan/${visitId}/cancel`),

  kunjungan:        (visitId)             => api.get(`/dokter/kunjungan/${visitId}`),
  // Komit billing + majukan antrean (RME tetap bisa dilengkapi belakangan).
  kirimKasir:       (visitId, data)       => api.put(`/dokter/kunjungan/${visitId}/kirim-kasir`, data),
  // Status tagihan ringkas → gating "Buka Kembali" Tab 3 (revisi pra-bayar).
  billingStatus:    (visitId)             => api.get(`/dokter/kunjungan/${visitId}/billing-status`),
  // Finalisasi mengunci RME — kirim body SOAP (S/O/A/P).
  finalize:         (visitId, soap = {})  => api.post(`/dokter/kunjungan/${visitId}/finalize`, soap),
  // Buka kembali RME final utk revisi (hanya pra-bayar — paritas Buka Kembali Tab 3).
  bukaFinalisasi:   (visitId)             => api.post(`/dokter/kunjungan/${visitId}/buka-finalisasi`),

  // Resume Medis — auto-generate dari data kunjungan, edit, lalu finalisasi (terbit).
  showResumeMedis:     (visitId)          => api.get(`/dokter/kunjungan/${visitId}/resume-medis`),
  generateResumeMedis: (visitId)          => api.post(`/dokter/kunjungan/${visitId}/resume-medis`),
  updateResumeMedis:   (id, data)         => api.put(`/dokter/resume-medis/${id}`, data),
  finalizeResumeMedis: (id)               => api.post(`/dokter/resume-medis/${id}/finalize`),
  // Riwayat SOAP/CPPT lintas-kunjungan pasien (RME aggregator) untuk kartu "SOAP / CPPT".
  riwayatKunjungan: (patientId)           => api.get(`/rekam-medis/pasien/${patientId}/kunjungan`),
  // Riwayat hasil penunjang lintas-kunjungan (RME aggregator) untuk kartu sidebar.
  riwayatPenunjang: (patientId)           => api.get(`/rekam-medis/pasien/${patientId}/penunjang`),
  // CPPT lintas-episode (RAJAL/IGD/RANAP + SOAP poli) — 1 timeline kronologis.
  riwayatCppt:      (patientId)           => api.get(`/rekam-medis/pasien/${patientId}/cppt`),
  // Riwayat DOKUMEN RM lintas-kunjungan (PatientDocument) — tab "Riwayat Dokumen" Dokumen RM.
  riwayatDokumen:   (patientId, params = {}) => api.get(`/rekam-medis/pasien/${patientId}/dokumen`, { params }),
  // Snapshot HTML dokumen final untuk cetak/lihat (auth-aware via interceptor).
  renderDokumen:    (docId)               => api.get(`/rekam-medis/document/${docId}/render`),

  tarifTindakan:    (visitId)             => api.get('/dokter/tarif-tindakan', { params: { visit_id: visitId } }),
  daftarObat:       (params = {})         => api.get('/dokter/obat', { params }),
  bedahSlot:        (tanggal, locationType) => api.get('/dokter/bedah/slot', { params: { tanggal, location_type: locationType } }),

  // Rujukan internal antar-poli (mis. Poli Mata Umum → Poli Retina)
  rujukInternalTargets: (visitId)         => api.get(`/dokter/kunjungan/${visitId}/rujuk-internal/targets`),
  rujukInternal:    (visitId, data)       => api.post(`/dokter/kunjungan/${visitId}/rujuk-internal`, data),
  gantiDokter:      (visitId, doctorScheduleId) => api.put(`/dokter/kunjungan/${visitId}/ganti-dokter`, { doctor_schedule_id: doctorScheduleId }),
  // Rujukan keluar (faskes lain). Pasien BPJS → terbit ke VClaim.
  rujukanKeluar:    (data)                => api.post('/dokter/rujukan-keluar', data),
  // Referensi VClaim (faskes/poli/diagnosa) untuk form rujukan — diekspos di grup dokter
  // (dokter tak punya permission integrasi.read; endpoint /integrasi/... terkunci 403).
  referensi:        (jenis, p)            => api.get(`/dokter/vclaim/referensi/${jenis}`, { params: p }),
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

  // Paket PEMERIKSAAN (poliklinik): terapkan (merge tindakan + snapshot diskon) / lepas.
  applyExaminationPackage:  (visitId, packageId) => api.post(`/dokter/kunjungan/${visitId}/apply-package`, { package_id: packageId }),
  removeExaminationPackage: (visitId)            => api.delete(`/dokter/kunjungan/${visitId}/package`),

  indexResep:       (visitId)             => api.get(`/dokter/kunjungan/${visitId}/resep`),
  storeResep:       (visitId, data)       => api.post(`/dokter/kunjungan/${visitId}/resep`, data),

  indexOrderPenunjang:  (visitId)         => api.get(`/dokter/kunjungan/${visitId}/order-penunjang`),
  indexHasilPenunjang: (visitId)          => api.get(`/dokter/kunjungan/${visitId}/hasil-penunjang`),
  storeOrderPenunjang: (data)             => api.post('/dokter/order-penunjang', data),
  cancelOrderPenunjang: (id)              => api.delete(`/dokter/order-penunjang/${id}`),

  // Keputusan IOL dari biometri (Quantel): biometri+tabel IOL+master+keputusan, lalu simpan.
  biometriIol:      (visitId)             => api.get(`/dokter/kunjungan/${visitId}/biometri-iol`),
  decideIol:        (visitId, data)       => api.post(`/dokter/kunjungan/${visitId}/keputusan-iol`, data),
}

/** Refraksionis */
export const refraksiApi = {
  antrian:            ()                 => api.get('/refraksi/antrian'),
  kunjungan:          (visitId)          => api.get(`/refraksi/kunjungan/${visitId}`),
  panggil:            (id)               => api.put(`/refraksi/antrian/${id}/panggil`),
  mulai:              (id)               => api.put(`/refraksi/antrian/${id}/mulai`),
  lewati:             (id)               => api.put(`/refraksi/antrian/${id}/lewati`),
  skipRefraksi:       (id)               => api.put(`/refraksi/antrian/${id}/skip`),
  selesai:            (id)               => api.put(`/refraksi/antrian/${id}/selesai`),

  showPemeriksaan:    (visitId)          => api.get(`/refraksi/pemeriksaan/${visitId}`),
  storePemeriksaan:   (data)             => api.post('/refraksi/pemeriksaan', data),
  updatePemeriksaan:  (id, data)         => api.put(`/refraksi/pemeriksaan/${id}`, data),
  finalizePemeriksaan: (id, pin)         => api.post(`/refraksi/pemeriksaan/${id}/finalize`, { pin }),
  reopenPemeriksaan:   (id)              => api.post(`/refraksi/pemeriksaan/${id}/reopen`),

  showResep:          (refractionId)     => api.get(`/refraksi/resep-kacamata/${refractionId}`),
  storeResep:         (data)             => api.post('/refraksi/resep-kacamata', data),
  updateResep:        (id, data)         => api.put(`/refraksi/resep-kacamata/${id}`, data),

  riwayat:            (patientId)        => api.get(`/refraksi/pasien/${patientId}/riwayat`),
  // CPPT lintas-episode terpadu (kartu SOAP/CPPT) — sumber sama dgn DokterView.
  riwayatCppt:        (patientId)        => api.get(`/refraksi/pasien/${patientId}/cppt`),
  statusParallel:     (visitId)          => api.get(`/refraksi/kunjungan/${visitId}/status-parallel`),

  // Opsi combobox (kind → daftar nilai siap-pakai). Master di masterApi.refraksiOpsi.
  opsi:               ()                 => api.get('/refraksi/opsi'),
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
  pendapatanChart: ()       => api.get('/dashboard/pendapatan-chart'),
  diagnosisStats:  ()       => api.get('/dashboard/diagnosis-stats'),
  distribusiPenjamin: (range) => api.get('/dashboard/distribusi-penjamin', { params: range ? { range } : {} }),
  jamTersibuk:     (days)   => api.get('/dashboard/jam-tersibuk', { params: days ? { days } : {} }),
}

/** Master Data */
// Penjamin (insurers) — callable as function utk back-compat (list shorthand)
// + properties list/create/update/remove untuk CRUD lengkap.
const _penjamin = (params) => api.get('/master/penjamin', { params })
_penjamin.list   = (params)   => api.get('/master/penjamin', { params })
_penjamin.create = (data)     => api.post('/master/penjamin', data)
_penjamin.update = (id, data) => api.put(`/master/penjamin/${id}`, data)
_penjamin.remove = (id)       => api.delete(`/master/penjamin/${id}`)
// CSV / Excel — template / export / import (format='xlsx' utk Excel, default CSV)
_penjamin.csvTemplate = (format) => api.get('/master/penjamin/template-csv', { params: format ? { format } : {}, responseType: 'blob' })
_penjamin.csvExport   = (format) => api.get('/master/penjamin/export-csv',   { params: format ? { format } : {}, responseType: 'blob' })
_penjamin.csvImport   = (file) => {
  const fd = new FormData()
  fd.append('file', file)
  return api.post('/master/penjamin/import-csv', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
}
// TPA membership — kelola anggota dari halaman TPA induk
_penjamin.memberCandidates = (tpaId)            => api.get(`/master/penjamin/${tpaId}/member-candidates`)
// addMember terima string (id existing) ATAU object { insurerId, newName }.
_penjamin.addMember        = (tpaId, arg) => {
  const body = typeof arg === 'string'
    ? { insurer_id: arg }
    : { insurer_id: arg?.insurerId || undefined, new_name: arg?.newName || undefined }
  return api.post(`/master/penjamin/${tpaId}/members`, body)
}
_penjamin.removeMember     = (tpaId, memberId)  => api.delete(`/master/penjamin/${tpaId}/members/${memberId}`)

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

  // Penomoran Dokumen (document_number_configs) — dipakai PengaturanView
  nomorDokumen: {
    list:   ()         => api.get('/master/nomor-dokumen'),
    create: (data)     => api.post('/master/nomor-dokumen', data),
    update: (id, data) => api.put(`/master/nomor-dokumen/${id}`, data),
    remove: (id)       => api.delete(`/master/nomor-dokumen/${id}`),
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
      csvTemplate: (format) => api.get('/master/tindakan/kategori/template-csv', { params: format ? { format } : {}, responseType: 'blob' }),
      csvExport:   (format) => api.get('/master/tindakan/kategori/export-csv',   { params: format ? { format } : {}, responseType: 'blob' }),
      csvImport:   (file) => {
        const fd = new FormData()
        fd.append('file', file)
        return api.post('/master/tindakan/kategori/import-csv', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      },
    },
  },

  // Opsi Refraksi (master combobox RefraksionisView) — range/list per kind
  refraksiOpsi: {
    list:   (params)   => api.get('/master/refraksi-opsi', { params }),
    create: (data)     => api.post('/master/refraksi-opsi', data),
    update: (id, data) => api.put(`/master/refraksi-opsi/${id}`, data),
    remove: (id)       => api.delete(`/master/refraksi-opsi/${id}`),
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

  // Buku Tarif terpadu (Tindakan+Obat+BHP+IOL satu daftar berkategori)
  bukuTarif: {
    list:     (params)  => api.get('/master/buku-tarif', { params }),
    setHarga: (payload) => api.put('/master/buku-tarif/harga', payload),
    // CSV/Excel terpadu — 1 file lintas-tipe (Tindakan+Obat+BHP+IOL), roundtrip harga UMUM.
    csvTemplate: (format)        => api.get('/master/buku-tarif/template-csv', { params: format ? { format } : {}, responseType: 'blob' }),
    csvExport:   (params, format) => api.get('/master/buku-tarif/export-csv', { params: { ...(params || {}), ...(format ? { format } : {}) }, responseType: 'blob' }),
    csvImport:   (file) => {
      const fd = new FormData(); fd.append('file', file)
      return api.post('/master/buku-tarif/import-csv', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    },
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
    scan:   (code)     => api.post('/master/iol/scan', { code }), // parse UDI + lookup master
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
    // format: 'csv' (default) | 'xlsx' — backend menyalurkan ke csvOrXlsx().
    template: (type, format) => api.get(`/master/${type}/template-csv`, { params: format ? { format } : {}, responseType: 'blob' }),
    export:   (type, format) => api.get(`/master/${type}/export-csv`,   { params: format ? { format } : {}, responseType: 'blob' }),
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
  prefillForm: (code, visitId) => api.get(`/rekam-medis/form/${code}/prefill`, { params: { visit_id: visitId } }),
  submitForm:  (code, visitId, data) => api.post(`/rekam-medis/form/${code}/submit`, { visit_id: visitId, data }),
  markRendered:(docId) => api.post(`/rekam-medis/document/${docId}/mark-rendered`),
  finalize:    (docId, signatureIds = []) => api.post(`/rekam-medis/document/${docId}/finalize`, { signature_ids: signatureIds }),
  snapshot:    (docId) => api.get(`/rekam-medis/document/${docId}/render`),
  saveDraftContent: (docId, renderedHtml) => api.put(`/rekam-medis/document/${docId}/draft-content`, { rendered_html: renderedHtml }),
  discardDraft:     (docId) => api.delete(`/rekam-medis/document/${docId}`),

  // ─── Fase 4 — Signature flow ───────────────────────────────────────────
  sign:                (docId, payload) => api.post(`/rekam-medis/document/${docId}/sign`, payload),
  listSignatures:      (docId) => api.get(`/rekam-medis/document/${docId}/signatures`),
  verifySignature:     (sigId) => api.get(`/rekam-medis/signature/${sigId}/verify`),
  auditSignature:      (sigId) => api.get(`/rekam-medis/signature/${sigId}/audit`),
  ttdQueue:            (params) => api.get('/rekam-medis/ttd-queue', { params }),
  ttdSignedToday:      (params) => api.get('/rekam-medis/ttd-signed-today', { params }),
  ttdCount:            () => api.get('/rekam-medis/ttd-count'),
  bulkSign:            (ids, pin) => api.post('/rekam-medis/ttd-bulk-sign', { document_ids: ids, signature_pin: pin }),
  createAddendum:      (docId, payload) => api.post(`/rekam-medis/document/${docId}/addendum`, payload),
  reviseDocument:      (docId, payload) => api.post(`/rekam-medis/document/${docId}/revisi`, payload),
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

  // --- Kemasan jual obat (varian per Strip/Box, harga independen per kemasan) ---
  kemasanObat: {
    list:   (medicationId)       => api.get(`/tarif-paket/obat/${medicationId}/kemasan`),
    create: (medicationId, data) => api.post(`/tarif-paket/obat/${medicationId}/kemasan`, data),
    update: (id, data)           => api.put(`/tarif-paket/kemasan-obat/${id}`, data),
    remove: (id)                 => api.delete(`/tarif-paket/kemasan-obat/${id}`),
  },

  // --- Metode Bayar (detail insurer + CSV per-insurer per-type) ---
  metodeBayar: {
    detail:      (id)              => api.get(`/tarif-paket/metode-bayar/${id}`),
    csvTemplate: (id, type, format)        => api.get(`/tarif-paket/metode-bayar/${id}/tarif/${type}/template-csv`, { params: format ? { format } : {}, responseType: 'blob' }),
    csvExport:   (id, type, format)        => api.get(`/tarif-paket/metode-bayar/${id}/tarif/${type}/export-csv`,   { params: format ? { format } : {}, responseType: 'blob' }),
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
    csvTemplate: (format)    => api.get('/tarif-paket/paket-bedah/template-csv', { params: format ? { format } : {}, responseType: 'blob' }),
    csvExport:   (format)    => api.get('/tarif-paket/paket-bedah/export-csv',   { params: format ? { format } : {}, responseType: 'blob' }),
    csvImport:   (file) => {
      const fd = new FormData()
      fd.append('file', file)
      return api.post('/tarif-paket/paket-bedah/import-csv', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    },
    // CSV per-paket (dari halaman detail) — template/export komposisi 1 paket
    csvTemplateOne: (id, format) => api.get(`/tarif-paket/paket-bedah/${id}/template-csv`, { params: format ? { format } : {}, responseType: 'blob' }),
    csvExportOne:   (id, format) => api.get(`/tarif-paket/paket-bedah/${id}/export-csv`,   { params: format ? { format } : {}, responseType: 'blob' }),
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
  markBedAvailable: (bedId)          => api.post(`/rawat-inap/bed/${bedId}/available`),

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

  // Permintaan obat ke Farmasi (dispensing rawat inap ke ruangan).
  permintaanObatList:   (visitId)          => api.get(`/rawat-inap/${visitId}/permintaan-obat`),
  createPermintaanObat: (visitId, payload) => api.post(`/rawat-inap/${visitId}/permintaan-obat`, payload),

  // eMAR — pemberian obat ke pasien (PKPO 4.3)
  marBoard:            (visitId)          => api.get(`/rawat-inap/${visitId}/mar`),
  recordAdministration:(visitId, payload) => api.post(`/rawat-inap/${visitId}/mar`, payload),
  deleteAdministration:(visitId, id)      => api.delete(`/rawat-inap/${visitId}/mar/${id}`),

  // Balance cairan (intake/output)
  fluidBalance:        (visitId)          => api.get(`/rawat-inap/${visitId}/fluid-balance`),
  addFluidBalance:     (visitId, payload) => api.post(`/rawat-inap/${visitId}/fluid-balance`, payload),
  deleteFluidBalance:  (visitId, id)      => api.delete(`/rawat-inap/${visitId}/fluid-balance/${id}`),

  // CPPT
  cpptList:      (visitId)           => api.get(`/rawat-inap/${visitId}/cppt`),
  addCppt:       (visitId, payload)  => api.post(`/rawat-inap/${visitId}/cppt`, payload),
  updateCppt:    (cpptId, payload)   => api.put(`/rawat-inap/cppt/${cpptId}`, payload),
  verifyCppt:    (cpptId)            => api.post(`/rawat-inap/cppt/${cpptId}/verify`),

  // SEP / SPRI
  getSep:        (visitId)           => api.get(`/rawat-inap/${visitId}/sep`),
  updateSep:     (visitId, payload)  => api.put(`/rawat-inap/${visitId}/sep`, payload),
  updateTglPulang: (visitId, payload) => api.put(`/rawat-inap/${visitId}/sep/tgl-pulang`, payload),
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
  // Opsi modal disposisi: BEDAH (paket/operator/anestesi) & RAJAL (poli tujuan).
  bedahOptions:  (visitId)           => api.get(`/igd/${visitId}/bedah-options`),
  rajalTargets:  (visitId)           => api.get(`/igd/${visitId}/rajal-targets`),

  // CPPT IGD
  cpptList:      (visitId)           => api.get(`/igd/${visitId}/cppt`),
  addCppt:       (visitId, payload)  => api.post(`/igd/${visitId}/cppt`, payload),
  updateCppt:    (cpptId, payload)   => api.put(`/igd/cppt/${cpptId}`, payload),
  verifyCppt:    (cpptId)            => api.post(`/igd/cppt/${cpptId}/verify`),

  // SEP IGD (BPJS gawat darurat)
  sepInfo:       (visitId)           => api.get(`/igd/${visitId}/sep`),
  generateSep:   (visitId, payload)  => api.post(`/igd/${visitId}/sep`, payload),

  // RM 3.7 — Asesmen/Pengkajian Gawat Darurat
  getAssessment:      (visitId)          => api.get(`/igd/${visitId}/assessment`),
  saveAssessment:     (visitId, payload) => api.put(`/igd/${visitId}/assessment`, payload),
  finalizeAssessment: (visitId, payload) => api.post(`/igd/${visitId}/assessment/finalize`, payload),

  // Self-checkout IGD (hari libur / kasir tidak bertugas)
  billingPreview: (visitId)          => api.get(`/igd/${visitId}/billing-preview`),
  selfCheckout:   (visitId, payload) => api.post(`/igd/${visitId}/self-checkout`, payload),
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
  uploadMediaImage:    (formData, onProgress) => api.post('/antrean-tv/media-settings/image', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 5 * 60 * 1000,
    onUploadProgress: onProgress,
  }),
  // Registry TV per-perangkat
  registerDevice:      (payload) => api.post('/antrean-tv/device/register', payload),
  deviceMedia:         (deviceKey) => api.get(`/antrean-tv/device/${deviceKey}`),
  listDevices:         () => api.get('/antrean-tv/devices'),
  updateDevice:        (id, payload) => api.put(`/antrean-tv/devices/${id}`, payload),
  deleteDevice:        (id) => api.delete(`/antrean-tv/devices/${id}`),
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
  csvTemplate:   (format)     => api.get('/jadwal-dokter/template-csv', { params: format ? { format } : {}, responseType: 'blob' }),
  csvExport:     (params, format) => api.get('/jadwal-dokter/export-csv', { params: { ...(params || {}), ...(format ? { format } : {}) }, responseType: 'blob' }),
  csvImport:     (file, weekStart) => {
    const fd = new FormData()
    fd.append('file', file)
    if (weekStart) fd.append('week_start', weekStart)
    return api.post('/jadwal-dokter/import-csv', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },
}

/** Laporan Marketing — daftar pasien siap-olah untuk campaign (read-only + export) */
export const marketingApi = {
  list:          (params)         => api.get('/laporan-marketing', { params }),
  notifications: ()               => api.get('/laporan-marketing/notifications'),
  csvExport:     (params, format) => api.get('/laporan-marketing/export-csv', {
    params: { ...(params || {}), ...(format ? { format } : {}) },
    responseType: 'blob',
  }),
}

/** Keuangan — rekap honor (jasa medis) dokter per periode + aturan honor (PKS/edaran) */
export const keuanganApi = {
  recap:      (params)         => api.get('/keuangan/honor-recap', { params }),
  csvExport:  (params, format) => api.get('/keuangan/honor-recap/export', {
    params: { ...(params || {}), ...(format ? { format } : {}) },
    responseType: 'blob',
  }),
  options:    ()               => api.get('/keuangan/fee-rules/options'),
  listRules:  (params)         => api.get('/keuangan/fee-rules', { params }),
  createRule: (body)           => api.post('/keuangan/fee-rules', body),
  updateRule: (id, body)       => api.put(`/keuangan/fee-rules/${id}`, body),
  deleteRule: (id)             => api.delete(`/keuangan/fee-rules/${id}`),
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
  updatePenjamin: (id, data)    => api.put(`/admisi/kunjungan/${id}/penjamin`, data),
  gantiDokter:    (id, doctorScheduleId) => api.put(`/admisi/kunjungan/${id}/dokter`, { doctor_schedule_id: doctorScheduleId }),
  cariPasien:    (keyword)      => api.get('/admisi/pasien', { params: { keyword } }),
  showPasien:    (id)           => api.get(`/admisi/pasien/${id}`),
  kunjunganPasien: (id, params) => api.get(`/admisi/pasien/${id}/kunjungan`, { params }),
  jadwalBedahAktif: (id)        => api.get(`/admisi/pasien/${id}/jadwal-bedah-aktif`),
  kontrolGratis: (id)           => api.get(`/admisi/pasien/${id}/kontrol-gratis`),
  updatePasien:  (id, data)     => api.put(`/admisi/pasien/${id}`, data),
  resolveIhs:    (id)           => api.post(`/admisi/pasien/${id}/resolve-ihs`),
  daftar:        (data)         => api.post('/admisi/daftar', data),
  daftarkanWalkIn: (visitId, data) => api.put(`/admisi/kunjungan/${visitId}/daftarkan-walkin`, data),
  previewConsent: (data)        => api.post('/admisi/consent/preview', data),

  // Berkas identitas pasien (KTP — foto/PDF), disk privat ber-auth.
  identityDocs:      (id)               => api.get(`/admisi/pasien/${id}/identity-documents`),
  uploadIdentityDoc: (id, formData, onUploadProgress) =>
    api.post(`/admisi/pasien/${id}/identity-documents`, formData, { onUploadProgress }),
  identityDocFile:   (id, docId)        => api.get(`/admisi/pasien/${id}/identity-documents/${docId}/file`, { responseType: 'blob' }),
  deleteIdentityDoc: (id, docId)        => api.delete(`/admisi/pasien/${id}/identity-documents/${docId}`),

  // BPJS (VClaim/Antrean) — cek peserta/rujukan + terbitkan/batal SEP
  bpjs: {
    cekPeserta:      (data) => api.post('/admisi/bpjs/cek-peserta', data),
    generateSep:     (data) => api.post('/admisi/bpjs/generate-sep', data),
    updateSep:       (data) => api.put('/admisi/bpjs/update-sep', data),
    cancelSep:       (data) => api.post('/admisi/bpjs/cancel-sep', data),
    cetakSep:        (visitId) => api.get(`/admisi/bpjs/cetak-sep/${visitId}`, { responseType: 'blob' }),
    cekRujukan:      (data) => api.post('/admisi/bpjs/cek-rujukan', data),
    cekSuratKontrol: (data) => api.post('/admisi/bpjs/cek-surat-kontrol', data),
    rujukanByKartu:      (data) => api.post('/admisi/bpjs/rujukan-by-kartu', data),
    suratKontrolByKartu: (data) => api.post('/admisi/bpjs/surat-kontrol-by-kartu', data),
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
  lewatiAntrian:     (id)         => api.put(`/farmasi/antrian/${id}/lewati`),
  selesaiAntrian:    (id)         => api.put(`/farmasi/antrian/${id}/selesai`),

  // Preview harga obat tambahan sesuai penjamin pasien (harga ditagih kasir).
  hargaObat:         (params)     => api.get('/farmasi/harga-obat', { params }),

  // Verifikasi Farmasi (gate sebelum tagihan Kasir, alur D→K→F).
  verifikasiQueue:   (params)     => api.get('/farmasi/verifikasi', { params }),
  verifikasiResep:   (id)         => api.put(`/farmasi/resep/${id}/verifikasi`),
  bukaVerifikasi:    (id)         => api.put(`/farmasi/resep/${id}/buka-verifikasi`),

  // Resep
  resep:             (params)     => api.get('/farmasi/resep', { params }),
  showResep:         (id)         => api.get(`/farmasi/resep/${id}`),
  startDispensing:   (id)         => api.put(`/farmasi/resep/${id}/dispensing`),
  selesaiDispensing: (id)         => api.put(`/farmasi/resep/${id}/selesai`),
  cancelResep:       (id)         => api.put(`/farmasi/resep/${id}/cancel`),
  storeItem:         (rid, items) => api.post(`/farmasi/resep/${rid}/item`, { items }),
  updateItem:        (id, data)   => api.put(`/farmasi/resep-item/${id}`, data),
  // Pilih varian kemasan jual (Strip/Box) saat verifikasi; sale_unit_id null = lepas.
  setKemasan:        (id, data)   => api.put(`/farmasi/resep-item/${id}/kemasan`, data),
  // Hapus item resep (opsional alasan utk audit substitusi/koreksi).
  deleteItem:        (id, reason) => api.delete(`/farmasi/resep-item/${id}`, { data: reason ? { change_reason: reason } : {} }),
  // Penjualan obat tambahan (OTC) untuk pasien antrean Farmasi tanpa resep dokter.
  storeOtc:          (visitId, items) => api.post(`/farmasi/kunjungan/${visitId}/resep-otc`, { items }),

  // Dispensing rawat inap — permintaan obat pasien dirawat (serah ke ruangan).
  ranapList:         ()           => api.get('/farmasi/ranap/permintaan'),
  ranapSiapkan:      (id)         => api.put(`/farmasi/ranap/permintaan/${id}/siapkan`),
  ranapSerah:        (id)         => api.put(`/farmasi/ranap/permintaan/${id}/serah`),
  ranapTolak:        (id)         => api.put(`/farmasi/ranap/permintaan/${id}/tolak`),

  // Riwayat pemberian satu obat (laporan "diberikan ke siapa").
  obatRiwayat:       (id, params) => api.get(`/farmasi/obat/${id}/riwayat-pemberian`, { params }),
  // Riwayat GLOBAL obat yang diberikan ke pasien (resep + POS) — search/date/page.
  riwayatPemberian:  (params)     => api.get('/farmasi/riwayat-pemberian', { params }),
  // Export seluruh riwayat pemberian (sesuai filter) ke Excel (.xlsx).
  riwayatPemberianExport: (params) => api.get('/farmasi/riwayat-pemberian/export', { params, responseType: 'blob' }),

  // Stok
  stokObat:          (params)     => api.get('/farmasi/stok/obat', { params }),
  updateStokObat:    (id, data)   => api.put(`/farmasi/stok/obat/${id}`, data),
  // Stok BHP (bahan habis pakai) — dikonsumsi & ditagih ke kwitansi (mis. rawat inap).
  stokBhp:           (params)     => api.get('/farmasi/stok/bhp', { params }),
  updateStokBhp:     (id, data)   => api.put(`/farmasi/stok/bhp/${id}`, data),
  stokAlert:         ()           => api.get('/farmasi/stok/alert'),
  // Export lembar kerja stok opname (xlsx default).
  opnameExport:      (params)     => api.get('/farmasi/stok/opname/export', { params, responseType: 'blob' }),

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
  templateCsv: (type, format) => api.get(`/inventori-farmasi/stock/${type}/template-csv`, { params: format ? { format } : {}, responseType: 'blob' }),
  exportCsv:   (type, location, format) => api.get(`/inventori-farmasi/stock/${type}/export-csv`, { params: { ...(location ? { location } : {}), ...(format ? { format } : {}) }, responseType: 'blob' }),
  importCsv:   (type, file, location) => {
    const fd = new FormData()
    fd.append('file', file)
    if (location) fd.append('location', location)
    return api.post(`/inventori-farmasi/stock/${type}/import-csv`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },
}

/** Inventori Farmasi — Sesi Stock Opname (Berita Acara) + detail per item */
export const opnameSessionApi = {
  list:   (params)  => api.get('/inventori-farmasi/opname-session', { params }),
  show:   (id)      => api.get(`/inventori-farmasi/opname-session/${id}`),
  create: (payload) => api.post('/inventori-farmasi/opname-session', payload),
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

/** Laporan Inventori — ringkasan + pemesanan + retur (tracking konsumsi). */
export const inventoriLaporanApi = {
  summary:  (params) => api.get('/inventori-farmasi/laporan/summary', { params }),
  pemesanan: (params) => api.get('/inventori-farmasi/laporan/pemesanan', { params }),
  pemesananExport: (params, format) => api.get('/inventori-farmasi/laporan/pemesanan/export', {
    params: { ...(params || {}), ...(format ? { format } : {}) }, responseType: 'blob',
  }),
  retur: (params) => api.get('/inventori-farmasi/laporan/retur', { params }),
  returExport: (params, format) => api.get('/inventori-farmasi/laporan/retur/export', {
    params: { ...(params || {}), ...(format ? { format } : {}) }, responseType: 'blob',
  }),
  selisih: (params) => api.get('/inventori-farmasi/laporan/selisih', { params }),
  selisihExport: (params, format) => api.get('/inventori-farmasi/laporan/selisih/export', {
    params: { ...(params || {}), ...(format ? { format } : {}) }, responseType: 'blob',
  }),
}

// Penentuan Harga (inventoriHargaApi) DIHAPUS — harga jual obat/BHP/IOL kini di
// Buku Tarif (lihat tarifPaketApi.tarif & MetodeBayarTarifTab, baris insurer UMUM).

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

  // Tarif tindakan per-penjamin visit untuk picker komposisi paket (gate bedah.read,
  // tanpa kunci DPJP — beda dari dokterApi.tarifTindakan yang 403 bagi non-DPJP).
  tarifTindakan:          (visitId)       => api.get('/bedah/tarif-tindakan', { params: { visit_id: visitId } }),

  // Komponen paket pasien (snapshot) — edit BHP & Tindakan saat operasi.
  // getVisitPackage kini balikkan ARRAY paket (multi-paket per visit, mis. Phaco + TIVA).
  getVisitPackage:        (visitId)       => api.get(`/bedah/visit-package/${visitId}`),
  addVisitPackage:        (visitId, packageId, tariffId = null) => api.post(`/bedah/visit-package/${visitId}/package`, { package_id: packageId, tariff_id: tariffId || undefined }),
  removeVisitPackage:     (snapshotId)    => api.delete(`/bedah/visit-package/${snapshotId}`),
  addVisitPackageItem:    (visitId, data) => api.post(`/bedah/visit-package/${visitId}/items`, data),
  updateVisitPackageItem: (itemId, data)  => api.put(`/bedah/visit-package-item/${itemId}`, data),
  removeVisitPackageItem: (itemId)        => api.delete(`/bedah/visit-package-item/${itemId}`),

  // Operasi lifecycle (jadwal): Sign In → Time Out
  mulaiOperasi:   (id)       => api.put(`/bedah/jadwal/${id}/mulai`),
  selesaiOperasi: (id, data) => api.put(`/bedah/jadwal/${id}/selesai`, data),

  // Laporan operasi (surgery_records): laporan + post-op + finalize
  showRecord:     (scheduleId) => api.get(`/bedah/record/${scheduleId}`),
  storeRecord:    (data)       => api.post('/bedah/record', data),
  updateRecord:   (id, data)   => api.put(`/bedah/record/${id}`, data),
  storePostOp:    (id, data)   => api.put(`/bedah/record/${id}/post-op`, data),
  finalizeRecord: (id)         => api.post(`/bedah/record/${id}/finalize`),

  // Master lookup (Bedah-scoped, gate bedah.read)
  daftarObat:     (search) => api.get('/bedah/obat', { params: { search } }),
  listIol:        (params) => api.get('/bedah/iol', { params }),
  // IOL terpasang saat operasi (surgery_iol_usage)
  listIolUsage:   (recordId) => api.get('/bedah/iol-usage', { params: { surgery_record_id: recordId } }),
  storeIolUsage:  (data)   => api.post('/bedah/iol-usage', data),
  updateIolUsage: (id, data) => api.put(`/bedah/iol-usage/${id}`, data),
  deleteIolUsage: (id)     => api.delete(`/bedah/iol-usage/${id}`),
  scanIol:        (code)   => masterApi.iol.scan(code), // delegasi parse UDI (inventori_farmasi.read|bedah.read)
  // Resep pasca-bedah → Farmasi (Prescription SUBMITTED)
  storeResepPasca: (recordId, data) => api.post(`/bedah/record/${recordId}/resep-pasca`, data),
  // Muat resep pasca-bedah aktif + status tagihan → hidrasi & gating "Buka Kembali".
  getResepPasca:   (recordId)       => api.get(`/bedah/record/${recordId}/resep-pasca`),

  // Paket Obat Pasca-Bedah (template resep rutin) — read bedah.read, tulis bedah.write
  paketObat: {
    list:   (search)   => api.get('/bedah/paket-obat', { params: { search } }),
    create: (data)     => api.post('/bedah/paket-obat', data),
    update: (id, data) => api.put(`/bedah/paket-obat/${id}`, data),
    remove: (id)       => api.delete(`/bedah/paket-obat/${id}`),
  },

  // Perioperatif (PAB/WHO): checklist keselamatan (bedah.checklist) + laporan + Aldrete
  saveSafetyChecklist:    (id, phase, data, bypass_reason = null) =>
    api.put(`/bedah/record/${id}/safety-checklist`, { phase, data, bypass_reason }),
  saveOperationReport:    (id, data) => api.put(`/bedah/record/${id}/operation-report`, data),
  saveRecoveryAssessment: (id, data) => api.put(`/bedah/record/${id}/recovery-assessment`, data),

  // Anestesi (RM 5.2 + monitoring vital durante) — dipakai AnesthesiaReportWizard.
  anesthesiologists:       ()           => api.get('/bedah/anesthesiologists'),
  getAnesthesiaReport:     (recordId)   => api.get(`/bedah/record/${recordId}/anesthesia`),
  saveAnesthesiaReport:    (recordId, data) => api.post(`/bedah/record/${recordId}/anesthesia`, data),
  listAnesthesiaVitals:    (recordId)   => api.get(`/bedah/record/${recordId}/anesthesia-vitals`),
  recordAnesthesiaVital:   (payload)    => api.post('/bedah/anesthesia-vital', payload),
  updateAnesthesiaVital:   (id, payload) => api.put(`/bedah/anesthesia-vital/${id}`, payload),
  deleteAnesthesiaVital:   (id)         => api.delete(`/bedah/anesthesia-vital/${id}`),
}

/** Ruang Tindakan — stasiun laser YAG/PRP (gate ruang_tindakan.*). */
export const ruangTindakanApi = {
  antrian:        ()         => api.get('/ruang-tindakan/antrian'),
  panggil:        (id)       => api.put(`/ruang-tindakan/antrian/${id}/panggil`),
  lewati:         (id)       => api.put(`/ruang-tindakan/antrian/${id}/lewati`),
  mulai:          (scheduleId) => api.put(`/ruang-tindakan/jadwal/${scheduleId}/mulai`),
  selesai:        (scheduleId, data) => api.put(`/ruang-tindakan/jadwal/${scheduleId}/selesai`, data),
  showRecord:     (scheduleId) => api.get(`/ruang-tindakan/record/${scheduleId}`),
  saveLaporan:    (recordId, laporan) => api.put(`/ruang-tindakan/record/${recordId}/laporan`, { laporan }),
  jadwal:         (dateFrom, dateTo) => api.get('/ruang-tindakan/jadwal', { params: { date_from: dateFrom, date_to: dateTo } }),
  daftarObat:     (search)   => api.get('/ruang-tindakan/daftar-obat', { params: { search } }),
  resep:          (scheduleId, data) => api.post(`/ruang-tindakan/jadwal/${scheduleId}/resep`, data),
}

/** Penunjang — antrian, order, hasil pemeriksaan */
export const penunjangApi = {
  // Antrian
  antrian:           (params)     => api.get('/penunjang/antrian', { params }),
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

  // Inbox hasil tak-tertaut (ingest alat gagal cocok otomatis → tautkan manual)
  inbox:              (params)        => api.get('/penunjang/inbox', { params }),
  inboxAssignable:    (params)        => api.get('/penunjang/inbox/assignable', { params }),
  assignInbox:        (id, orderId)   => api.post(`/penunjang/inbox/${id}/assign`, { order_id: orderId }),
  discardInbox:       (id)            => api.post(`/penunjang/inbox/${id}/discard`),
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
  invoiceCoverages:  (id)                 => api.get(`/kasir/invoice/${id}/coverages`),
  generateInvoice:   (visitId)            => api.post(`/kasir/invoice/${visitId}/generate`),
  updateInvoice:     (id, data)           => api.put(`/kasir/invoice/${id}`, data),
  finalizeInvoice:   (id)                 => api.post(`/kasir/invoice/${id}/finalize`),
  resyncTarif:       (id)                 => api.post(`/kasir/invoice/${id}/resync-tarif`),
  bayarInvoice:      (id, data)           => api.post(`/kasir/invoice/${id}/bayar`, data),
  confirmCoverage:   (id, data)           => api.post(`/kasir/invoice/${id}/confirm-coverage`, data),
  confirmBpjs:       (id, data)           => api.post(`/kasir/invoice/${id}/confirm-bpjs`, data ?? {}),
  settleZero:        (id, data)           => api.post(`/kasir/invoice/${id}/settle-zero`, data ?? {}),
  cancelInvoice:     (id)                 => api.post(`/kasir/invoice/${id}/cancel`),
  cetakInvoice:      (id)                 => api.get(`/kasir/invoice/${id}/cetak`),
  // Kirim kwitansi PDF ke email pasien (alternatif cetak fisik)
  emailInvoice:      (id, email)          => api.post(`/kasir/invoice/${id}/email`, { email }),

  // Billing items (override saat edit tagihan)
  storeItem:         (invoiceId, data)    => api.post(`/kasir/invoice/${invoiceId}/item`, data),
  updateItem:        (id, data)           => api.put(`/kasir/invoice-item/${id}`, data),
  deleteItem:        (id)                 => api.delete(`/kasir/invoice-item/${id}`),
  // Toggle "terserap ke paket" baris obat/BHP tambahan (DISKON_PAKET menyesuaikan)
  absorbItem:        (visitId, data)      => api.post(`/kasir/invoice/${visitId}/absorb-item`, data),

  // Tarif tindakan per-penjamin (Edit Tagihan — pilih dari master)
  tarifTindakan:     (visitId)            => api.get('/kasir/tarif-tindakan', { params: { visit_id: visitId } }),
  // Pencarian buku tarif lintas kategori (tindakan/obat/BHP/IOL/alkes) — Edit Tagihan
  tarifBuku:         (visitId, q, type)   => api.get('/kasir/tarif-buku', { params: { visit_id: visitId, q, type: type || 'ALL' } }),

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
  // CSV / Excel — template / export / import (format='xlsx' utk Excel, default CSV)
  csvTemplate:   (format)     => api.get('/rbac/users/csv-template', { params: format ? { format } : {}, responseType: 'blob' }),
  exportCsv:     (format)     => api.get('/rbac/users/export',       { params: format ? { format } : {}, responseType: 'blob' }),
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
  // CSV / Excel — template / export / import (format='xlsx' utk Excel, default CSV)
  csvTemplate:     (format)     => api.get('/rbac/roles/csv-template', { params: format ? { format } : {}, responseType: 'blob' }),
  exportCsv:       (format)     => api.get('/rbac/roles/export',       { params: format ? { format } : {}, responseType: 'blob' }),
  importCsv:       (file)       => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post('/rbac/roles/import', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },
}

export const permissionApi = {
  list: () => api.get('/rbac/permissions'),
  flat: () => api.get('/rbac/permissions/flat'),
  updateModuleLabel: (module, label) => api.put(`/rbac/permissions/module-label/${module}`, { label }),
  resetModuleLabel:  (module)        => api.post(`/rbac/permissions/module-label/${module}/reset`),
}

/** Audit Log (read-only system_logs) — tab Audit Log di DataPenggunaView */
export const auditLogApi = {
  list: (params) => api.get('/rbac/audit-logs', { params }),
}

/** Asuransi/TPA Non-BPJS — verifikasi eligibility + workflow klaim */
export const asuransiApi = {
  // Verifikasi eligibility (input manual hasil cek portal TPA)
  pendingVerifications: (params)    => api.get('/asuransi/verifikasi/pending', { params }),
  inServiceVerifications: (params)  => api.get('/asuransi/verifikasi/in-service', { params }),
  getVerifikasi:        (visitId)   => api.get(`/asuransi/verifikasi/${visitId}`),
  getVerifikasiAll:     (visitId)   => api.get(`/asuransi/verifikasi-all/${visitId}`),
  cobBasis:             (visitId)   => api.get(`/asuransi/cob-basis/${visitId}`),
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
  // Sinkron master ICD-10/ICD-9 dari referensi VClaim (cakupan oftalmologi)
  syncIcd:            (type, keywords) => api.post('/integrasi/bpjs/sync-icd', { type, ...(keywords ? { keywords } : {}) }),

  // Satu Sehat (dashboard monitoring + sync/log/retry)
  satusehatDashboard: (params)   => api.get('/integrasi/satusehat/dashboard', { params }),
  // Sync/retry/backfill SINKRON di BE (1 Bundle/visit ke Kemenkes) — default 15s
  // pasti putus duluan: UI tampil "gagal" padahal server masih jalan (lalu user
  // klik ulang → run dobel). Beri timeout panjang sesuai volume.
  satusehatSyncManual:()         => api.post('/integrasi/satusehat/sync-manual', null, { timeout: 10 * 60_000 }),
  satusehatRetry:     (logId)    => api.post(`/integrasi/satusehat/retry/${logId}`, null, { timeout: 10 * 60_000 }),
  satusehatBackfillPreview: (params) => api.get('/integrasi/satusehat/backfill/preview', { params }),
  satusehatBackfill:  (data)     => api.post('/integrasi/satusehat/backfill', data, { timeout: 30 * 60_000 }),
  // Resolve IHS massal (1 GET Kemenkes per pasien; 500 ≈ menit-an).
  satusehatResolveIhs:(data)     => api.post('/integrasi/satusehat/resolve-ihs', data, { timeout: 30 * 60_000 }),
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
