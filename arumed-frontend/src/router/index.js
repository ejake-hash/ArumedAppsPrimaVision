import { createRouter, createWebHistory } from 'vue-router'

import AppLayout from '@/layouts/AppLayout.vue'
import LoginView from '@/views/LoginView.vue'
import AdmisiView from '@/views/AdmisiView.vue'
import DokterView from '@/views/DokterView.vue'
import PenunjangView from '@/views/PenunjangView.vue'
import FarmasiView from '@/views/FarmasiView.vue'
import AntreanTVView from '@/views/AntreanTVView.vue'
import AnjunganView from '@/views/AnjunganView.vue'
import DataPenggunaView from '@/views/DataPenggunaView.vue'
import DashboardView from '@/views/DashboardView.vue'
import RekamMedisView from '@/views/RekamMedisView.vue'
import KasirView from '@/views/KasirView.vue'
import PerawatView from '@/views/PerawatView.vue'
import RefraksionisView from '@/views/RefraksionisView.vue'
import KlaimView from '@/views/KlaimView.vue'
import BedahView from '@/views/BedahView.vue'
import BedahTerjadwalView from '@/views/BedahTerjadwalView.vue'
import StubView from '@/views/StubView.vue'
import JadwalDokterView from '@/views/JadwalDokterView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: LoginView, meta: { layout: 'blank' } },
    { path: '/antrean-tv', name: 'antrean-tv', component: AntreanTVView, meta: { title: 'Antrean TV' } },
    { path: '/anjungan', name: 'anjungan', component: AnjunganView, meta: { title: 'Anjungan Mandiri' } },
    {
      path: '/',
      component: AppLayout,
      children: [
        { path: '', redirect: '/admisi' },
        { path: 'admisi', name: 'admisi', component: AdmisiView, meta: { title: 'Admisi & Pendaftaran' } },
        { path: 'dokter', name: 'dokter', component: DokterView, meta: { title: 'Poliklinik Dokter', permission: 'rme_dokter.read' } },
        { path: 'dashboard', name: 'dashboard', component: DashboardView, meta: { title: 'Dashboard', permission: ['keuangan.read', 'marketing.read'] } },
        { path: 'perawat', name: 'perawat', component: PerawatView, meta: { title: 'Triase / Perawat', permission: 'perawat.read' } },
        { path: 'refraksionis', name: 'refraksionis', component: RefraksionisView, meta: { title: 'Refraksionis', permission: 'refraksionis.read' } },
        { path: 'rekam-medis', name: 'rekam-medis', component: RekamMedisView, meta: { title: 'Rekam Medis', permission: 'rekam_medis.read' } },
        { path: 'penunjang', name: 'penunjang', component: PenunjangView, meta: { title: 'Pemeriksaan Penunjang', permission: 'penunjang.read' } },
        { path: 'bedah', name: 'bedah', component: BedahView, meta: { title: 'Bedah', permission: 'bedah.read' } },
        { path: 'bedah/terjadwal', name: 'bedah-terjadwal', component: BedahTerjadwalView, meta: { title: 'Pasien Terjadwal Bedah', permission: 'bedah.read' } },
        { path: 'ruang-tindakan', name: 'ruang-tindakan', component: () => import('@/views/RuangTindakanView.vue'), meta: { title: 'Ruang Tindakan', permission: 'ruang_tindakan.read' } },
        { path: 'rawat-inap', name: 'rawat-inap', component: () => import('@/views/RawatInapView.vue'), meta: { title: 'Rawat Inap', permission: 'rawat_inap.read' } },
        { path: 'igd', name: 'igd', component: () => import('@/views/IgdView.vue'), meta: { title: 'IGD (Darurat)', permission: 'igd.read' } },
        { path: 'farmasi', name: 'farmasi', component: FarmasiView, meta: { title: 'Farmasi', permission: 'farmasi.read' } },
        { path: 'kasir', name: 'kasir', component: KasirView, meta: { title: 'Kasir & Billing', permission: 'kasir.read' } },
        { path: 'bpjs', name: 'bpjs', component: KlaimView, meta: { title: 'BPJS & Klaim', permission: 'bpjs.read' } },
        { path: 'rekap-kunjungan-bpjs', name: 'rekap-kunjungan-bpjs', component: () => import('@/views/RekapKunjunganBpjsView.vue'), meta: { title: 'Rekap Kunjungan BPJS', permission: 'bpjs.read' } },
        { path: 'asuransi', name: 'asuransi', component: () => import('@/views/AsuransiView.vue'), meta: { title: 'Asuransi & Klaim TPA', permission: 'asuransi.read' } },
        { path: 'DataPengguna', name: 'DataPengguna', component: DataPenggunaView, meta: { title: 'Kepegawaian & RBAC', superadmin: true } },
        { path: 'jadwal-dokter', name: 'jadwal-dokter', component: JadwalDokterView, meta: { title: 'Jadwal Dokter', permission: 'jadwal_dokter.write' } },
        { path: 'laporan-marketing', name: 'laporan-marketing', component: () => import('@/views/LaporanMarketingView.vue'), meta: { title: 'Laporan Marketing', permission: 'marketing.read' } },
        { path: 'keuangan', name: 'keuangan', component: () => import('@/views/KeuanganView.vue'), meta: { title: 'Keuangan — Rekap Honor', permission: 'keuangan.read' } },
        { path: 'ttd-dokumen', name: 'ttd-dokumen', component: () => import('@/views/TtdDokumenView.vue'), meta: { title: 'Tanda Tangan Dokumen', permission: 'ttd_dokumen.read' } },
        { path: 'pengaturan', name: 'pengaturan', component: () => import('@/views/PengaturanView.vue'), meta: { title: 'Pengaturan', permission: 'master_data.read' } },

        // Master Data — shell + child views (sub-route per resource)
        {
          path: 'master-data',
          component: () => import('@/views/master-data/MasterDataLayout.vue'),
          meta: { title: 'Master Data', permissions: ['master_data.read'] },
          children: [
            { path: '',                name: 'master-data',          redirect: { name: 'master-profil-klinik' } },
            { path: 'profil-klinik',   name: 'master-profil-klinik', component: () => import('@/views/master-data/ProfilKlinikView.vue'),  meta: { title: 'Profil Klinik' } },
            { path: 'icd10',           name: 'master-icd10',         component: () => import('@/views/master-data/Icd10View.vue'),         meta: { title: 'ICD-10' } },
            { path: 'icd9',            name: 'master-icd9',          component: () => import('@/views/master-data/Icd9View.vue'),          meta: { title: 'ICD-9' } },
            { path: 'wilayah',         name: 'master-wilayah',       component: () => import('@/views/master-data/WilayahView.vue'),       meta: { title: 'Wilayah Indonesia' } },
            { path: 'ruang-fasilitas', name: 'master-ruang-fasilitas', component: () => import('@/views/master-data/RuangFasilitasView.vue'), meta: { title: 'Fasilitas & Ruang', permission: ['rawat_inap.read', 'master_data.read'] } },
            { path: 'opsi-refraksi',   name: 'master-opsi-refraksi', component: () => import('@/views/master-data/OpsiRefraksiView.vue'), meta: { title: 'Opsi Refraksi', permission: 'master_data.read' } },
            { path: 'document-type',       name: 'master-document-type',      component: () => import('@/views/master-data/DocumentTypeView.vue'),                  meta: { title: 'Jenis Dokumen RM', permission: 'master_data.read' } },
            { path: 'form-template',       name: 'master-form-template',      component: () => import('@/views/master-data/form-template/FormTemplateView.vue'),  meta: { title: 'Form Rekam Medis', permission: 'master_data.read' } },
            { path: 'form-template/new',   name: 'master-form-template-new',  component: () => import('@/views/master-data/form-template/FormTemplateWizard.vue'), meta: { title: 'Form RM — Buat Baru', permission: 'master_data.write' } },
            { path: 'form-template/:id',   name: 'master-form-template-edit', component: () => import('@/views/master-data/form-template/FormTemplateWizard.vue'), meta: { title: 'Form RM — Edit', permission: 'master_data.write' }, props: true },
          ],
        },

        // Sub-modul standalone: Tarif & Paket Bedah
        {
          path: 'tarif-paket',
          component: () => import('@/views/tarif-paket/TarifPaketLayout.vue'),
          meta: { title: 'Tarif & Paket Bedah', permission: 'tarif_paket.read' },
          children: [
            { path: '',                  name: 'tarif-paket',               redirect: '/tarif-paket/tarif-tindakan' },
            { path: 'tarif-tindakan',    name: 'tarif-paket-tindakan',      component: () => import('@/views/master-data/TarifTindakanView.vue'),       meta: { title: 'Tarif Tindakan' } },
            { path: 'metode-bayar',      name: 'tarif-paket-metode-bayar',  component: () => import('@/views/tarif-paket/MetodeBayarView.vue'),         meta: { title: 'Metode Bayar' } },
            { path: 'metode-bayar/:id',  name: 'metode-bayar-detail',       component: () => import('@/views/tarif-paket/MetodeBayarDetailView.vue'),   meta: { title: 'Detail Metode Bayar' } },
            { path: 'paket-bedah',       name: 'paket-bedah-list',          component: () => import('@/views/tarif-paket/PaketBedahListView.vue'),      meta: { title: 'Paket Bedah' } },
            { path: 'paket-bedah/:id',   name: 'paket-bedah-detail',        component: () => import('@/views/tarif-paket/PaketBedahDetailView.vue'),    meta: { title: 'Detail Paket Bedah' } },
            { path: 'tarif-kamar',       name: 'tarif-paket-tarif-kamar',   component: () => import('@/views/tarif-paket/TarifKamarView.vue'),          meta: { title: 'Tarif Kamar' } },
            { path: 'kategori-tagihan',  name: 'tarif-paket-kategori-tagihan', component: () => import('@/views/tarif-paket/KategoriTagihanView.vue'),  meta: { title: 'Kategori Buku Tarif' } },
          ],
        },

        // Sub-modul standalone: Inventori Farmasi (Obat / BHP / IOL)
        {
          path: 'inventori-farmasi',
          component: () => import('@/views/inventori-farmasi/InventoriFarmasiLayout.vue'),
          meta: { title: 'Inventori Farmasi', permission: 'inventori_farmasi.read' },
          children: [
            // Index redirect RESILIEN: default Request dari Unit (alur harian gudang),
            // TAPI route itu di-gate `request_unit.read` — beda dari permission modul
            // (`inventori_farmasi.read`). Role inventori yang TIDAK punya request_unit
            // dulu dipental ke /dashboard (menu tampil, klik bouncing). Jatuhkan ke tab
            // ber-`inventori_farmasi.read` (Obat) supaya selalu mendarat di modul.
            { path: '', name: 'inventori-farmasi', redirect: () => {
              const auth = useAuthStore()
              return auth.can('request_unit.read')
                ? '/inventori-farmasi/request-unit'
                : '/inventori-farmasi/obat'
            } },
            { path: 'obat', name: 'inventori-farmasi-obat', component: () => import('@/views/master-data/ObatView.vue'), meta: { title: 'Obat' } },
            { path: 'bhp',  name: 'inventori-farmasi-bhp',  component: () => import('@/views/master-data/BhpView.vue'),  meta: { title: 'BHP' } },
            { path: 'iol',  name: 'inventori-farmasi-iol',  component: () => import('@/views/master-data/IolView.vue'),  meta: { title: 'IOL' } },
            { path: 'alat-medis', name: 'inventori-farmasi-alat-medis', component: () => import('@/views/inventori-farmasi/AlatMedisView.vue'), meta: { title: 'Alat Medis' } },
            { path: 'stock-opname', name: 'inventori-farmasi-stock-opname', component: () => import('@/views/inventori-farmasi/StockOpnameView.vue'), meta: { title: 'Stock Opname', permission: 'inventori_farmasi.read' } },
            { path: 'supplier', name: 'inventori-farmasi-supplier', component: () => import('@/views/inventori-farmasi/SupplierView.vue'), meta: { title: 'Supplier', permission: 'inventori_farmasi.read' } },
            { path: 'pembelian', name: 'inventori-farmasi-pembelian', component: () => import('@/views/inventori-farmasi/PembelianView.vue'), meta: { title: 'Pembelian', permission: 'inventori_farmasi.read' } },
            { path: 'penerimaan', name: 'inventori-farmasi-penerimaan', component: () => import('@/views/inventori-farmasi/PenerimaanView.vue'), meta: { title: 'Penerimaan', permission: 'inventori_farmasi.read' } },
            { path: 'request-unit', name: 'inventori-farmasi-request-unit', component: () => import('@/views/inventori-farmasi/RequestUnitView.vue'), meta: { title: 'Request dari Unit', permission: 'request_unit.read' } },
            { path: 'laporan', name: 'inventori-farmasi-laporan', component: () => import('@/views/inventori-farmasi/LaporanView.vue'), meta: { title: 'Laporan', permission: 'inventori_farmasi.read' } },
          ],
        },

        // Sub-modul standalone: Bridging BPJS (VClaim / Antrean / Satu Sehat)
        {
          path: 'bridging',
          component: () => import('@/views/bridging/BridgingLayout.vue'),
          meta: { title: 'Bridging BPJS', permission: 'integrasi.read' },
          children: [
            { path: '',            name: 'bridging',             redirect: '/bridging/konfigurasi' },
            { path: 'konfigurasi', name: 'bridging-konfigurasi', component: () => import('@/views/bridging/BridgingKonfigurasiView.vue'), meta: { title: 'Konfigurasi & Status' } },
            { path: 'vclaim',      name: 'bridging-vclaim',      component: () => import('@/views/bridging/BridgingVClaimView.vue'),       meta: { title: 'VClaim' } },
            // Antrean Online dipindah ke Jadwal Dokter → Pemetaan BPJS. Redirect link lama.
            { path: 'antrean',     name: 'bridging-antrean',     redirect: '/jadwal-dokter' },
            { path: 'satusehat',   name: 'bridging-satusehat',   component: () => import('@/views/bridging/BridgingSatusehatView.vue'),   meta: { title: 'Satu Sehat' } },
            { path: 'log',         name: 'bridging-log',         component: () => import('@/views/bridging/BridgingLogView.vue'),         meta: { title: 'Log Integrasi' } },
          ],
        },
      ],
    },
  ],
})

import { useAuthStore } from '@/stores/auth'

// Route yang tidak perlu login (public display screens + login itu sendiri)
const publicRoutes = ['login', 'antrean-tv', 'anjungan']

// Rute yang tampil "fluid"/responsif (mengalir mengikuti lebar layar). Hanya
// Login & TTD Dokumen yang perlu jalan nyaman di HP/tablet; halaman kerja lain
// SELALU desktop penuh (scroll horizontal bila jendela sempit). Lihat base.css
// (kelas `app-fluid` pada <html>).
const fluidRoutes = ['login', 'ttd-dokumen']

// Terapkan mode tata letak per-rute: pasang/lepas kelas `app-fluid` di <html>
// dan setel meta viewport. Browser desktop mengabaikan meta viewport, jadi
// "desktop penuh" sebenarnya ditegakkan CSS (body min-width 1280); viewport=1280
// hanya membuat HP menampilkan layout desktop yang di-zoom-out (perilaku lama).
function applyLayoutMode(routeName) {
  const fluid = fluidRoutes.includes(routeName)
  document.documentElement.classList.toggle('app-fluid', fluid)
  const vp = document.querySelector('meta[name="viewport"]')
  if (vp) {
    vp.setAttribute(
      'content',
      fluid ? 'width=device-width, initial-scale=1.0' : 'width=1280',
    )
  }
}

router.beforeEach((to) => {
  applyLayoutMode(to.name)

  const auth = useAuthStore()

  // 1. Unauthenticated → login
  if (!publicRoutes.includes(to.name) && !auth.isAuthenticated) {
    return { name: 'login' }
  }

  // 2. Cek permission dengan pola DEEPEST-WINS.
  //    Telusuri rantai `to.matched` (parent → child) dan ambil permission dari
  //    segmen PALING SPESIFIK yang punya meta. Efek:
  //    - child TANPA meta MEWARISI permission parent (mis. semua `bridging/*`,
  //      `tarif-paket/*`, child master-data/inventori tanpa meta sendiri).
  //    - child DENGAN meta MENIMPA parent (mis. `master-data/ruang-fasilitas`
  //      butuh `rawat_inap.read`, bukan permission parent Master Data).
  let perm = null
  for (const record of to.matched) {
    const recordPerm = record.meta?.permission ?? record.meta?.permissions
    if (recordPerm) perm = recordPerm
  }
  if (perm && auth.isAuthenticated && !auth.can(perm)) {
    // Tidak punya akses — jatuhkan ke landing default yang TAK ber-gate (Admisi).
    // Jangan pakai 'dashboard': sejak Dashboard ikut di-gate (manajemen-only),
    // memantulkan user yang ditolak ke sana akan memicu redirect-loop.
    return { name: 'admisi' }
  }

  // 3. Route KHUSUS SUPERADMIN (mis. Kepegawaian & RBAC). Backend mengunci seluruh
  //    grup /rbac/* ke role:superadmin, jadi FE harus konsisten — role lain (walau
  //    diberi role_akses.read) tak boleh masuk agar tak melihat halaman kosong (403).
  const superadminOnly = to.matched.some((r) => r.meta?.superadmin)
  if (superadminOnly && auth.isAuthenticated && !auth.isSuperadmin) {
    return { name: 'admisi' }
  }
})

export default router
