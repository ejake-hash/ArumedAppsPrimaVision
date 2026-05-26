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
        { path: 'dokter', name: 'dokter', component: DokterView, meta: { title: 'RME Dokter' } },
        { path: 'dashboard', name: 'dashboard', component: DashboardView, meta: { title: 'Dashboard' } },
        { path: 'perawat', name: 'perawat', component: PerawatView, meta: { title: 'Triase / Perawat' } },
        { path: 'refraksionis', name: 'refraksionis', component: RefraksionisView, meta: { title: 'Refraksionis' } },
        { path: 'rekam-medis', name: 'rekam-medis', component: RekamMedisView, meta: { title: 'Rekam Medis' } },
        { path: 'penunjang', name: 'penunjang', component: PenunjangView, meta: { title: 'Pemeriksaan Penunjang' } },
        { path: 'bedah', name: 'bedah', component: BedahView, meta: { title: 'Bedah' } },
        { path: 'bedah/terjadwal', name: 'bedah-terjadwal', component: BedahTerjadwalView, meta: { title: 'Pasien Terjadwal Bedah' } },
        { path: 'farmasi', name: 'farmasi', component: FarmasiView, meta: { title: 'Farmasi' } },
        { path: 'kasir', name: 'kasir', component: KasirView, meta: { title: 'Kasir & Billing' } },
        { path: 'bpjs', name: 'bpjs', component: KlaimView, meta: { title: 'BPJS & Klaim' } },
        { path: 'DataPengguna', name: 'DataPengguna', component: DataPenggunaView, meta: { title: 'Kepegawaian & RBAC', permission: 'role_akses.read' } },
        { path: 'jadwal-dokter', name: 'jadwal-dokter', component: JadwalDokterView, meta: { title: 'Jadwal Dokter' } },
        { path: 'pengaturan', name: 'pengaturan', component: StubView, props: { title: 'Pengaturan' }, meta: { title: 'Pengaturan' } },

        // Master Data — shell + child views (sub-route per resource)
        {
          path: 'master-data',
          component: () => import('@/views/master-data/MasterDataLayout.vue'),
          meta: { title: 'Master Data', permission: 'pengaturan.read' },
          children: [
            { path: '',                name: 'master-data',          redirect: { name: 'master-profil-klinik' } },
            { path: 'profil-klinik',   name: 'master-profil-klinik', component: () => import('@/views/master-data/ProfilKlinikView.vue'),  meta: { title: 'Profil Klinik' } },
            { path: 'icd10',           name: 'master-icd10',         component: () => import('@/views/master-data/Icd10View.vue'),         meta: { title: 'ICD-10' } },
            { path: 'icd9',            name: 'master-icd9',          component: () => import('@/views/master-data/Icd9View.vue'),          meta: { title: 'ICD-9' } },
            { path: 'wilayah',         name: 'master-wilayah',       component: () => import('@/views/master-data/WilayahView.vue'),       meta: { title: 'Wilayah Indonesia' } },
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
          ],
        },

        // Sub-modul standalone: Inventori Farmasi (Obat / BHP / IOL)
        {
          path: 'inventori-farmasi',
          component: () => import('@/views/inventori-farmasi/InventoriFarmasiLayout.vue'),
          meta: { title: 'Inventori Farmasi', permission: 'inventori_farmasi.read' },
          children: [
            { path: '',     name: 'inventori-farmasi',      redirect: '/inventori-farmasi/obat' },
            { path: 'obat', name: 'inventori-farmasi-obat', component: () => import('@/views/master-data/ObatView.vue'), meta: { title: 'Obat' } },
            { path: 'bhp',  name: 'inventori-farmasi-bhp',  component: () => import('@/views/master-data/BhpView.vue'),  meta: { title: 'BHP' } },
            { path: 'iol',  name: 'inventori-farmasi-iol',  component: () => import('@/views/master-data/IolView.vue'),  meta: { title: 'IOL' } },
            { path: 'harga', name: 'inventori-farmasi-harga', component: () => import('@/views/inventori-farmasi/PenentuanHargaView.vue'), meta: { title: 'Penentuan Harga' } },
            { path: 'supplier', name: 'inventori-farmasi-supplier', component: () => import('@/views/inventori-farmasi/SupplierView.vue'), meta: { title: 'Supplier', permission: 'supplier.read' } },
            { path: 'pembelian', name: 'inventori-farmasi-pembelian', component: () => import('@/views/inventori-farmasi/PembelianView.vue'), meta: { title: 'Pembelian', permission: 'pembelian.read' } },
            { path: 'penerimaan', name: 'inventori-farmasi-penerimaan', component: () => import('@/views/inventori-farmasi/PenerimaanView.vue'), meta: { title: 'Penerimaan', permission: 'penerimaan.read' } },
            { path: 'request-unit', name: 'inventori-farmasi-request-unit', component: () => import('@/views/inventori-farmasi/RequestUnitView.vue'), meta: { title: 'Request dari Unit', permission: 'request_unit.read' } },
          ],
        },

        {
          path: 'rekam-medis/template-builder',
          name: 'RmTemplateBuilder',
          component: () => import('@/views/rekam-medis/RmTemplateBuilder.vue'),
          meta: { title: 'Auto-Schema EMR Generator' }
        },
      ],
    },
  ],
})

import { useAuthStore } from '@/stores/auth'

// Route yang tidak perlu login (public display screens + login itu sendiri)
const publicRoutes = ['login', 'antrean-tv', 'anjungan']

router.beforeEach((to) => {
  const auth = useAuthStore()

  // 1. Unauthenticated → login
  if (!publicRoutes.includes(to.name) && !auth.isAuthenticated) {
    return { name: 'login' }
  }

  // 2. Cek meta.permission (single key) atau meta.permissions (array, OR)
  const perm = to.meta?.permission ?? to.meta?.permissions
  if (perm && auth.isAuthenticated && !auth.can(perm)) {
    // Tidak punya akses — redirect ke landing default
    return { name: 'dashboard' }
  }
})

export default router
