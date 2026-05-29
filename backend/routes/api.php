<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdmisiController;
use App\Http\Controllers\PerawatController;
use App\Http\Controllers\RefraksiController;
use App\Http\Controllers\DokterController;
use App\Http\Controllers\PenunjangController;
use App\Http\Controllers\BedahController;
use App\Http\Controllers\FarmasiController;
use App\Http\Controllers\KasirController;
use App\Http\Controllers\KlaimController;
use App\Http\Controllers\RekamMedisController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\InventoryPriceController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\UnitRequestController;
use App\Http\Controllers\InventoryStockController;
use App\Http\Controllers\MedicalEquipmentController;
use App\Http\Controllers\UnitReturnController;
use App\Http\Controllers\TarifPaketController;
use App\Http\Controllers\IntegrasiController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\JadwalDokterController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\TvDisplaySettingController;
use App\Http\Controllers\TvAudioSettingController;
use App\Http\Controllers\TvMediaSettingController;
use App\Http\Controllers\TvBrandingSettingController;
use App\Http\Controllers\AsuransiController;

/*
|--------------------------------------------------------------------------
| API Routes — Arumed Apps v1
|--------------------------------------------------------------------------
| Prefix  : /api/v1
| Auth    : JWT (tymon/jwt-auth) via auth:api middleware
| Response: { success, data, message, errors }
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // =========================================================================
    // 1. PUBLIC — Auth (no middleware)
    // =========================================================================
    Route::prefix('auth')->group(function () {
        Route::post('/login',   [AuthController::class, 'login']);
    });

    // =========================================================================
    // 2. PUBLIC SEMENTARA — Modul Rekam Medis Auto-Generate (Tanpa Token)
    // =========================================================================
    Route::prefix('rekam-medis')->group(function () {
        Route::post('/templates/auto-generate', [App\Http\Controllers\RmTemplateGeneratorController::class, 'autoGenerate']);
        Route::post('/templates/store', [App\Http\Controllers\RmTemplateController::class, 'store']);
        Route::get('/templates', [App\Http\Controllers\RmTemplateController::class, 'index']);
    });

    // =========================================================================
    // 2b. PUBLIC — Anjungan Mandiri (Kiosk Self-Service, no auth)
    // =========================================================================
    Route::prefix('anjungan')->group(function () {
        Route::post('/tiket-umum', [AdmisiController::class, 'anjunganTiketUmum']);
    });

    // =========================================================================
    // 2c. PUBLIC — Antrean TV (lobby display, no auth — data sudah ditampilkan
    //     di layar publik, jadi tidak ada privacy regression)
    // =========================================================================
    Route::prefix('antrean-tv')->group(function () {
        Route::get('/snapshot',          [QueueController::class, 'index']);
        // Dokter aktif hari ini — untuk panel TV display (public, data non-sensitif)
        Route::get('/dokter-aktif',      [JadwalDokterController::class, 'aktifHariIni']);
        // Display settings (TTS template, flash text, badge, toggle kartu) — public read
        Route::get('/display-settings',  [TvDisplaySettingController::class, 'index']);
        // Audio settings (sound preset, volume, TTS voice, flash duration) — public read
        Route::get('/audio-settings',    [TvAudioSettingController::class, 'show']);
        // Branding (logo + nama klinik) — public read
        Route::get('/branding-settings', [TvBrandingSettingController::class, 'show']);
        // Media (mode, YouTube URL, video lokal, slideshow) — public read
        Route::get('/media-settings',    [TvMediaSettingController::class, 'show']);
    });

    // =========================================================================
    // 3. PROTECTED — semua route di bawah wajib auth:api
    // =========================================================================
    Route::middleware('auth:api')->group(function () {

        // -----------------------------------------------------------------
        // Antrean TV — edit display settings (per-stasiun template TTS,
        // flash, badge, toggle kartu). Read-nya public, write wajib login.
        // -----------------------------------------------------------------
        Route::prefix('antrean-tv/display-settings')->group(function () {
            Route::put('/{station}',        [TvDisplaySettingController::class, 'update']);
            Route::post('/{station}/reset', [TvDisplaySettingController::class, 'reset']);
        });
        // Antrean TV — audio settings (singleton)
        Route::put('/antrean-tv/audio-settings', [TvAudioSettingController::class, 'update']);
        // Antrean TV — branding settings (singleton: logo + nama klinik)
        Route::put('/antrean-tv/branding-settings',        [TvBrandingSettingController::class, 'update']);
        Route::post('/antrean-tv/branding-settings/reset', [TvBrandingSettingController::class, 'reset']);
        // Antrean TV — media (mode/youtube/video upload/slideshow) — sync ke semua TV
        Route::put('/antrean-tv/media-settings',           [TvMediaSettingController::class, 'update']);
        Route::post('/antrean-tv/media-settings/video',    [TvMediaSettingController::class, 'uploadVideo']);
        Route::delete('/antrean-tv/media-settings/video',  [TvMediaSettingController::class, 'deleteVideo']);

        // -----------------------------------------------------------------
        // AUTH
        // -----------------------------------------------------------------
        Route::prefix('auth')->group(function () {
            Route::post('/logout',  [AuthController::class, 'logout']);
            Route::post('/refresh', [AuthController::class, 'refresh']);
            Route::get('/me',       [AuthController::class, 'me']);
            Route::put('/password', [AuthController::class, 'changePassword']);
        });

        // -----------------------------------------------------------------
        // ANTRIAN (cross-cutting) — Section 11 Service Flow
        // -----------------------------------------------------------------
        Route::prefix('antrian')->group(function () {
            Route::get('/',                          [QueueController::class, 'index']);          // snapshot semua station
            Route::get('/station/{station}',         [QueueController::class, 'byStation']);      // per station
            Route::get('/{id}',                      [QueueController::class, 'show']);
            Route::put('/{id}/panggil',              [QueueController::class, 'panggil']);
            Route::put('/{id}/mulai',                [QueueController::class, 'mulai']);
            Route::put('/{id}/lewati',               [QueueController::class, 'lewati']);
            Route::put('/{id}/selesai',              [QueueController::class, 'selesai']);        // advance ke next station
            Route::put('/{id}/batal',                [QueueController::class, 'batal']);
        });

        // -----------------------------------------------------------------
        // ADMISI
        // -----------------------------------------------------------------
        Route::prefix('admisi')->group(function () {
            Route::get('/dashboard',              [AdmisiController::class, 'dashboard']);
            Route::get('/kunjungan',              [AdmisiController::class, 'indexKunjungan']);
            Route::get('/kunjungan/{id}',         [AdmisiController::class, 'showKunjungan']);
            Route::put('/kunjungan/{id}/cancel',  [AdmisiController::class, 'cancelKunjungan']);
            Route::put('/kunjungan/{visitId}/daftarkan-walkin', [AdmisiController::class, 'daftarkanWalkIn']);

            Route::get('/pasien',                 [AdmisiController::class, 'cariPasien']);
            Route::post('/pasien',                [AdmisiController::class, 'storePasien']);
            Route::get('/pasien/{id}',            [AdmisiController::class, 'showPasien']);
            Route::get('/pasien/{id}/kunjungan',  [AdmisiController::class, 'indexKunjunganPasien']);
            Route::get('/pasien/{id}/jadwal-bedah-aktif', [AdmisiController::class, 'jadwalBedahAktif']);
            Route::put('/pasien/{id}',            [AdmisiController::class, 'updatePasien']);

            Route::post('/daftar',                [AdmisiController::class, 'daftarKunjungan']);
            Route::post('/consent/preview',       [AdmisiController::class, 'previewConsent']);

            Route::get('/antrian',                [AdmisiController::class, 'indexAntrian']);
            Route::post('/antrian',               [AdmisiController::class, 'createAntrian']);
            Route::put('/antrian/{id}/panggil',   [AdmisiController::class, 'panggilAntrian']);
            Route::put('/antrian/{id}/selesai',   [AdmisiController::class, 'selesaiAntrian']);

            Route::prefix('bpjs')->group(function () {
                Route::post('/cek-peserta',        [AdmisiController::class, 'bpjsCekPeserta']);
                Route::post('/generate-sep',       [AdmisiController::class, 'bpjsGenerateSep']);
                Route::post('/cancel-sep',         [AdmisiController::class, 'bpjsCancelSep']);
                Route::post('/cek-rujukan',        [AdmisiController::class, 'bpjsCekRujukan']);
                Route::post('/cek-surat-kontrol',  [AdmisiController::class, 'bpjsCekSuratKontrol']);
                Route::post('/validasi-booking',   [AdmisiController::class, 'bpjsValidasiBooking']);
            });
        });

        // -----------------------------------------------------------------
        // PERAWAT / TRIASE
        // -----------------------------------------------------------------
        Route::prefix('perawat')->group(function () {
            Route::get('/antrian',                          [PerawatController::class, 'indexAntrian']);
            Route::put('/antrian/{id}/panggil',             [PerawatController::class, 'panggilAntrian']);
            Route::put('/antrian/{id}/mulai',               [PerawatController::class, 'mulaiAntrian']);
            Route::put('/antrian/{id}/selesai',             [PerawatController::class, 'selesaiAntrian']);

            Route::get('/asesmen/{visitId}',                [PerawatController::class, 'showAsesmen']);
            Route::post('/asesmen',                         [PerawatController::class, 'storeAsesmen']);
            Route::put('/asesmen/{id}',                     [PerawatController::class, 'updateAsesmen']);
            Route::post('/asesmen/{id}/finalize',           [PerawatController::class, 'finalizeAsesmen']);

            // CPPT — Catatan Perkembangan Pasien Terintegrasi (timeline append + soft-edit)
            Route::get('/cppt/visit/{visitId}',             [PerawatController::class, 'indexCppt']);
            Route::post('/cppt',                            [PerawatController::class, 'storeCppt']);
            Route::put('/cppt/{id}',                        [PerawatController::class, 'updateCppt']);

            Route::put('/antrian/{id}/lewati',                     [PerawatController::class, 'lewatiAntrian']);
            Route::post('/antrian/{id}/kirim-ke-bedah',            [PerawatController::class, 'kirimKeBedah']);
            Route::get('/kunjungan/{visitId}',                     [PerawatController::class, 'showKunjungan']);
            Route::get('/kunjungan/{visitId}/status-parallel',     [PerawatController::class, 'statusParallel']);
            Route::get('/pasien/{patientId}/vital-history',        [PerawatController::class, 'vitalHistory']);
            Route::get('/pasien/{patientId}/rekam-medis',          [PerawatController::class, 'rekamMedisPasien']);
            Route::get('/dokumen/{documentId}',                    [PerawatController::class, 'showDokumen']);
        });

        // -----------------------------------------------------------------
        // REFRAKSIONIS
        // -----------------------------------------------------------------
        Route::prefix('refraksi')->group(function () {
            Route::get('/antrian',                         [RefraksiController::class, 'indexAntrian']);
            Route::put('/antrian/{id}/panggil',            [RefraksiController::class, 'panggilAntrian']);
            Route::put('/antrian/{id}/mulai',              [RefraksiController::class, 'mulaiAntrian']);
            Route::put('/antrian/{id}/lewati',             [RefraksiController::class, 'lewatiAntrian']);
            Route::put('/antrian/{id}/selesai',            [RefraksiController::class, 'selesaiAntrian']);

            Route::get('/pemeriksaan/{visitId}',           [RefraksiController::class, 'showPemeriksaan']);
            Route::post('/pemeriksaan',                    [RefraksiController::class, 'storePemeriksaan']);
            Route::put('/pemeriksaan/{id}',                [RefraksiController::class, 'updatePemeriksaan']);
            Route::post('/pemeriksaan/{id}/finalize',      [RefraksiController::class, 'finalizePemeriksaan']);

            Route::get('/resep-kacamata/{refractionId}',  [RefraksiController::class, 'showResepKacamata']);
            Route::post('/resep-kacamata',                 [RefraksiController::class, 'storeResepKacamata']);
            Route::put('/resep-kacamata/{id}',             [RefraksiController::class, 'updateResepKacamata']);

            Route::get('/iol-rekomendasi/{visitId}',       [RefraksiController::class, 'showIolRekomendasi']);
            Route::post('/iol-rekomendasi',                [RefraksiController::class, 'storeIolRekomendasi']);
            Route::put('/iol-rekomendasi/{id}',            [RefraksiController::class, 'updateIolRekomendasi']);

            Route::get('/kunjungan/{visitId}',                     [RefraksiController::class, 'showKunjungan']);
            Route::get('/kunjungan/{visitId}/status-parallel',     [RefraksiController::class, 'statusParallel']);
            Route::get('/pasien/{patientId}/riwayat',              [RefraksiController::class, 'riwayatRefraksi']);
        });

        // -----------------------------------------------------------------
        // DOKTER
        // -----------------------------------------------------------------
        Route::prefix('dokter')->group(function () {
            Route::post('/verify-pin',                          [DokterController::class, 'verifyPin']);
            Route::get('/antrian',                              [DokterController::class, 'indexAntrian']);
            Route::put('/antrian/{id}/panggil',                 [DokterController::class, 'panggilAntrian']);
            Route::put('/antrian/{id}/selesai',                 [DokterController::class, 'selesaiAntrian']);
            Route::put('/antrian/{id}/ke-penunjang',            [DokterController::class, 'kirimKePenunjang']);

            // Referensi Tab 3: tarif tindakan per metode bayar + daftar obat ber-harga
            Route::get('/tarif-tindakan',                       [DokterController::class, 'tarifTindakan']);
            Route::get('/obat',                                 [DokterController::class, 'daftarObat']);

            // Referensi Tab 4 (Jadwalkan Bedah): preview jumlah & jam terisi per tanggal
            Route::get('/bedah/slot',                           [DokterController::class, 'bedahSlot']);

            Route::get('/kunjungan/{visitId}',                  [DokterController::class, 'showKunjungan']);

            Route::get('/kunjungan/{visitId}/tab2',             [DokterController::class, 'showTab2']);
            Route::post('/kunjungan/{visitId}/tab2',            [DokterController::class, 'storeTab2']);
            Route::put('/kunjungan/{visitId}/tab2',             [DokterController::class, 'updateTab2']);

            Route::get('/kunjungan/{visitId}/tab4',             [DokterController::class, 'showTab4']);
            Route::post('/kunjungan/{visitId}/tab4',            [DokterController::class, 'storeTab4']);
            Route::put('/kunjungan/{visitId}/tab4',             [DokterController::class, 'updateTab4']);

            Route::post('/kunjungan/{visitId}/finalize',        [DokterController::class, 'finalizeKunjungan']);

            Route::post('/kunjungan/{visitId}/follow-up',       [DokterController::class, 'storeFollowUp']);
            Route::put('/kunjungan/{visitId}/follow-up',        [DokterController::class, 'updateFollowUp']);
            Route::delete('/kunjungan/{visitId}/follow-up',     [DokterController::class, 'deleteFollowUp']);

            Route::get('/kunjungan/{visitId}/tindakan',         [DokterController::class, 'indexTindakan']);
            Route::post('/kunjungan/{visitId}/tindakan',        [DokterController::class, 'storeTindakan']);
            Route::delete('/tindakan/{id}',                     [DokterController::class, 'deleteTindakan']);

            Route::get('/kunjungan/{visitId}/resep',            [DokterController::class, 'indexResep']);
            Route::post('/kunjungan/{visitId}/resep',           [DokterController::class, 'storeResep']);

            Route::get('/kunjungan/{visitId}/order-penunjang',  [DokterController::class, 'indexOrderPenunjang']);
            Route::post('/order-penunjang',                     [DokterController::class, 'storeOrderPenunjang']);
            Route::delete('/order-penunjang/{id}',              [DokterController::class, 'cancelOrderPenunjang']);

            Route::get('/kunjungan/{visitId}/hasil-penunjang',  [DokterController::class, 'indexHasilPenunjang']);
            Route::get('/kunjungan/{visitId}/penunjang-billing', [DokterController::class, 'penunjangBilling']);
            Route::get('/kunjungan/{visitId}/iol-rekomendasi',  [DokterController::class, 'showIolRekomendasi']);

            Route::get('/kunjungan/{visitId}/resume-medis',     [DokterController::class, 'showResumeMedis']);
            Route::post('/kunjungan/{visitId}/resume-medis',    [DokterController::class, 'generateResumeMedis']);
            Route::put('/resume-medis/{id}',                    [DokterController::class, 'updateResumeMedis']);
            Route::post('/resume-medis/{id}/finalize',          [DokterController::class, 'finalizeResumeMedis']);

            Route::post('/rujukan-keluar',                      [DokterController::class, 'storeRujukanKeluar']);

            // (dihapus) /jadwal-bedah GET+POST — method indexJadwalBedah/storeJadwalBedah
            // tidak pernah ada (selalu 500), tanpa konsumen frontend. Penjadwalan bedah
            // ditangani DokterService::resolveSurgerySchedule (auto saat finalize) + bedahSlot.

            Route::get('/notifikasi',                           [DokterController::class, 'indexNotifikasi']);
            Route::put('/notifikasi/{id}/baca',                 [DokterController::class, 'bacaNotifikasi']);
            Route::post('/dokumen/{id}/tanda-tangan',           [DokterController::class, 'tandaTanganDokumen']);
            Route::post('/dokumen/{id}/tolak',                  [DokterController::class, 'tolakDokumen']);
        });

        // -----------------------------------------------------------------
        // JADWAL DOKTER (standalone module)
        // -----------------------------------------------------------------
        Route::prefix('jadwal-dokter')->group(function () {
            // GET  /jadwal-dokter/aktif-hari-ini  ← untuk admisi dropdown (authenticated)
            Route::get('/aktif-hari-ini',      [JadwalDokterController::class, 'aktifHariIni']);
            // Static routes — WAJIB di atas /{id} agar tidak ditangkap sebagai param.
            Route::get('/minggu-tersedia',     [JadwalDokterController::class, 'availableWeeks']);
            Route::get('/template-csv',        [JadwalDokterController::class, 'template']);
            Route::post('/import-csv',         [JadwalDokterController::class, 'import']);
            Route::post('/salin-minggu-depan', [JadwalDokterController::class, 'copyToNextWeek']);
            Route::get('/',                    [JadwalDokterController::class, 'index']);
            Route::post('/',                   [JadwalDokterController::class, 'store']);
            Route::get('/{id}',                [JadwalDokterController::class, 'show']);
            Route::put('/{id}',                [JadwalDokterController::class, 'update']);
            Route::delete('/{id}',             [JadwalDokterController::class, 'destroy']);
            Route::patch('/{id}/toggle',       [JadwalDokterController::class, 'toggle']);
        });

        // -----------------------------------------------------------------
        // PENUNJANG
        // -----------------------------------------------------------------
        Route::prefix('penunjang')->group(function () {
            Route::get('/antrian',                        [PenunjangController::class, 'indexAntrian']);
            Route::put('/antrian/{id}/panggil',           [PenunjangController::class, 'panggilAntrian']);
            Route::put('/antrian/{id}/lewati',            [PenunjangController::class, 'lewatiAntrian']);
            Route::put('/antrian/{id}/selesai',           [PenunjangController::class, 'selesaiAntrian']);

            Route::get('/order',                          [PenunjangController::class, 'indexOrder']);
            Route::post('/order',                         [PenunjangController::class, 'storeOrder']);
            Route::get('/order/{id}',                     [PenunjangController::class, 'showOrder']);
            Route::put('/order/{id}/proses',              [PenunjangController::class, 'prosesOrder']);
            Route::put('/order/{id}/cancel',              [PenunjangController::class, 'cancelOrder']);

            Route::post('/hasil/upload-attachment',       [PenunjangController::class, 'uploadHasilAttachment']);
            Route::get('/hasil/{orderId}',                [PenunjangController::class, 'showHasil']);
            Route::post('/hasil',                         [PenunjangController::class, 'storeHasil']);
            Route::put('/hasil/{id}',                     [PenunjangController::class, 'updateHasil']);
            Route::post('/hasil/{id}/selesai',            [PenunjangController::class, 'selesaiHasil']);

            Route::get('/iol-rekomendasi/{visitId}',      [PenunjangController::class, 'showIolRekomendasi']);
            Route::post('/iol-rekomendasi',               [PenunjangController::class, 'storeIolRekomendasi']);
            Route::put('/iol-rekomendasi/{id}',           [PenunjangController::class, 'updateIolRekomendasi']);
        });

        // -----------------------------------------------------------------
        // BEDAH
        // -----------------------------------------------------------------
        Route::prefix('bedah')->group(function () {
            Route::get('/antrian',                          [BedahController::class, 'indexAntrian']);
            Route::put('/antrian/{id}/panggil',             [BedahController::class, 'panggilAntrian']);
            Route::put('/antrian/{id}/selesai',             [BedahController::class, 'selesaiAntrian']);

            Route::get('/jadwal',                           [BedahController::class, 'indexJadwal']);
            Route::get('/jadwal/{id}',                      [BedahController::class, 'showJadwal']);
            Route::post('/jadwal',                          [BedahController::class, 'storeJadwal']);
            Route::put('/jadwal/{id}',                      [BedahController::class, 'updateJadwal']);
            Route::delete('/jadwal/{id}',                   [BedahController::class, 'deleteJadwal']);
            Route::put('/jadwal/{id}/mulai',                [BedahController::class, 'mulaiOperasi']);
            Route::put('/jadwal/{id}/selesai',              [BedahController::class, 'selesaiOperasi']);

            // 1-klik request BHP/IOL dari komposisi paket bedah (preview + kirim).
            Route::get('/jadwal/{id}/auto-request/preview', [BedahController::class, 'previewAutoRequest']);
            Route::post('/jadwal/{id}/auto-request',        [BedahController::class, 'sendAutoRequest']);

            Route::get('/request',                          [BedahController::class, 'indexRequest']);
            Route::get('/request/{id}',                     [BedahController::class, 'showRequest']);
            Route::post('/request',                         [BedahController::class, 'storeRequest']);
            Route::put('/request/{id}',                     [BedahController::class, 'updateRequest']);
            Route::put('/request/{id}/kirim',               [BedahController::class, 'kirimRequest']);
            Route::put('/request/{id}/terima',              [BedahController::class, 'terimaRequest']);
            Route::post('/request/{id}/adjust-bhp',         [BedahController::class, 'adjustBhpUsage']);

            Route::get('/record/{scheduleId}',              [BedahController::class, 'showRecord']);
            Route::post('/record',                          [BedahController::class, 'storeRecord']);
            Route::put('/record/{id}',                      [BedahController::class, 'updateRecord']);
            Route::put('/record/{id}/post-op',              [BedahController::class, 'storePostOp']);
            Route::post('/record/{id}/finalize',            [BedahController::class, 'finalizeRecord']);

            Route::post('/iol-usage',                       [BedahController::class, 'storeIolUsage']);
            Route::put('/iol-usage/{id}',                   [BedahController::class, 'updateIolUsage']);
        });

        // -----------------------------------------------------------------
        // FARMASI
        // -----------------------------------------------------------------
        Route::prefix('farmasi')->group(function () {
            Route::get('/antrian',                           [FarmasiController::class, 'indexAntrian']);
            Route::put('/antrian/{id}/panggil',              [FarmasiController::class, 'panggilAntrian']);
            Route::put('/antrian/{id}/selesai',              [FarmasiController::class, 'selesaiAntrian']);

            Route::get('/resep',                             [FarmasiController::class, 'indexResep']);
            Route::get('/resep/{id}',                        [FarmasiController::class, 'showResep']);
            Route::put('/resep/{id}/dispensing',             [FarmasiController::class, 'startDispensing']);
            Route::put('/resep/{id}/selesai',                [FarmasiController::class, 'selesaiDispensing']);
            Route::put('/resep/{id}/cancel',                 [FarmasiController::class, 'cancelResep']);

            Route::post('/resep/{resepId}/item',             [FarmasiController::class, 'storeItemDispensing']);
            Route::put('/resep-item/{id}',                   [FarmasiController::class, 'updateItemDispensing']);
            Route::delete('/resep-item/{id}',                [FarmasiController::class, 'deleteItemDispensing']);

            Route::get('/surgery-request',                   [FarmasiController::class, 'indexSurgeryRequest']);
            Route::get('/surgery-request/{id}',              [FarmasiController::class, 'showSurgeryRequest']);
            Route::put('/surgery-request/{id}/siapkan',      [FarmasiController::class, 'siapkanSurgeryRequest']);
            Route::post('/surgery-request/{id}/kirim',       [FarmasiController::class, 'kirimSurgeryRequest']);
            Route::post('/surgery-request/{id}/assign-iol',  [FarmasiController::class, 'assignIol']);

            Route::get('/stok/obat',                         [FarmasiController::class, 'indexStokObat']);
            Route::get('/stok/obat/{id}',                    [FarmasiController::class, 'showStokObat']);
            Route::put('/stok/obat/{id}',                    [FarmasiController::class, 'updateStokObat']);

            Route::get('/stok/bhp',                          [FarmasiController::class, 'indexStokBhp']);
            Route::put('/stok/bhp/{id}',                     [FarmasiController::class, 'updateStokBhp']);

            Route::get('/stok/iol',                          [FarmasiController::class, 'indexStokIol']);
            Route::put('/stok/iol/{id}',                     [FarmasiController::class, 'updateStokIol']);
            Route::get('/stok/alert',                        [FarmasiController::class, 'stokAlert']);
        });

        // -----------------------------------------------------------------
        // KASIR
        // -----------------------------------------------------------------
        Route::prefix('kasir')->group(function () {
            Route::get('/antrian',                         [KasirController::class, 'indexAntrian']);
            Route::put('/antrian/{id}/panggil',            [KasirController::class, 'panggilAntrian']);
            Route::put('/antrian/{id}/lewati',             [KasirController::class, 'lewatiAntrian']);
            Route::put('/antrian/{id}/selesai',            [KasirController::class, 'selesaiAntrian']);

            Route::get('/invoice',                         [KasirController::class, 'indexInvoice']);
            Route::get('/invoice/{visitId}',               [KasirController::class, 'showInvoice']);
            Route::get('/insurance-warning/{visitId}',     [KasirController::class, 'insuranceWarning']);
            Route::post('/invoice/{visitId}/generate',     [KasirController::class, 'generateInvoice']);
            Route::put('/invoice/{id}',                    [KasirController::class, 'updateInvoice']);
            Route::post('/invoice/{id}/finalize',          [KasirController::class, 'finalizeInvoice']);
            Route::post('/invoice/{id}/bayar',             [KasirController::class, 'bayarInvoice']);
            Route::post('/invoice/{id}/confirm-coverage',  [KasirController::class, 'confirmCoverage']);
            Route::post('/invoice/{id}/cancel',            [KasirController::class, 'cancelInvoice']);
            Route::get('/invoice/{id}/cetak',              [KasirController::class, 'cetakInvoice']);

            Route::post('/invoice/{invoiceId}/item',       [KasirController::class, 'storeItemInvoice']);
            Route::put('/invoice-item/{id}',               [KasirController::class, 'updateItemInvoice']);
            Route::delete('/invoice-item/{id}',            [KasirController::class, 'deleteItemInvoice']);

            Route::get('/cob/{visitId}',                   [KasirController::class, 'showCob']);
            Route::put('/cob/{visitId}',                   [KasirController::class, 'updateCob']);

            Route::put('/watermark',                       [KasirController::class, 'updateWatermark']);

            Route::get('/laporan',                         [KasirController::class, 'laporanHarian']);
            Route::get('/laporan/rekap',                   [KasirController::class, 'laporanRekap']);
        });

        // -----------------------------------------------------------------
        // KLAIM BPJS
        // -----------------------------------------------------------------
        Route::prefix('klaim')->group(function () {
            Route::get('/',                               [KlaimController::class, 'index']);

            // Route statis (segmen pertama bukan parameter) WAJIB sebelum `/{id}`,
            // kalau tidak `/{id}` menangkap 'vclaim-log' dsb. → diparse sebagai UUID → 500.
            Route::get('/vclaim-log',                     [KlaimController::class, 'vclaimpLog']);
            Route::get('/icare/monitoring',               [KlaimController::class, 'icareMonitoring']);
            Route::get('/grouping-log/{klaimId}',         [KlaimController::class, 'groupingLog']);

            Route::get('/{id}',                           [KlaimController::class, 'show']);

            Route::post('/{id}/grouping',                 [KlaimController::class, 'runGrouping']);

            Route::put('/{id}/review',                    [KlaimController::class, 'setReview']);
            Route::put('/{id}/verifikasi',                [KlaimController::class, 'setVerifikasi']);
            Route::put('/{id}/reject',                    [KlaimController::class, 'setReject']);

            Route::post('/{id}/submit',                   [KlaimController::class, 'submitKlaim']);

            Route::post('/{id}/lupis',                    [KlaimController::class, 'generateLupis']);

            Route::get('/{id}/audit-log',                 [KlaimController::class, 'auditLog']);
        });

        // -----------------------------------------------------------------
        // REKAM MEDIS (Protected Data)
        // -----------------------------------------------------------------
        Route::prefix('rekam-medis')->group(function () {
            Route::get('/pasien',                          [RekamMedisController::class, 'cariPasien']);
            Route::get('/pasien/{patientId}',              [RekamMedisController::class, 'riwayatPasien']);
            Route::get('/pasien/{patientId}/kunjungan',    [RekamMedisController::class, 'indexKunjungan']);
            Route::get('/pasien/{patientId}/ringkasan',    [RekamMedisController::class, 'ringkasanPasien']);
            Route::get('/pasien/{patientId}/refraksi',     [RekamMedisController::class, 'refraksiPasien']);
            Route::get('/pasien/{patientId}/penunjang',    [RekamMedisController::class, 'penunjangPasien']);
            Route::get('/pasien/{patientId}/obat',         [RekamMedisController::class, 'obatPasien']);
            Route::get('/pasien/{patientId}/bedah',        [RekamMedisController::class, 'bedahPasien']);
            Route::get('/pasien/{patientId}/diagnosis',    [RekamMedisController::class, 'diagnosisPasien']);
            Route::get('/pasien/{patientId}/dokumen',      [RekamMedisController::class, 'dokumenPasien']);

            Route::get('/dokumen',                         [RekamMedisController::class, 'indexDokumen']);
            Route::get('/dokumen/{id}',                    [RekamMedisController::class, 'showDokumen']);
            Route::post('/dokumen',                        [RekamMedisController::class, 'storeDokumen']);
            Route::put('/dokumen/{id}',                    [RekamMedisController::class, 'updateDokumen']);
            Route::post('/dokumen/{id}/submit',            [RekamMedisController::class, 'submitDokumen']);
            Route::post('/dokumen/{id}/void',              [RekamMedisController::class, 'voidDokumen']);
            Route::get('/dokumen/{id}/cetak',              [RekamMedisController::class, 'cetakDokumen']);
            Route::post('/dokumen/{id}/resend-notif',      [RekamMedisController::class, 'resendNotifDokumen']);

            Route::get('/verifikasi/{token}',              [RekamMedisController::class, 'verifikasiDokumen']);

            Route::get('/medical-record/{visitId}',        [RekamMedisController::class, 'showMedicalRecord']);
            Route::post('/medical-record',                 [RekamMedisController::class, 'storeMedicalRecord']);
            Route::put('/medical-record/{id}',             [RekamMedisController::class, 'updateMedicalRecord']);
            Route::get('/medical-record/{id}/versions',    [RekamMedisController::class, 'versionsMedicalRecord']);

            Route::get('/notifikasi',                      [RekamMedisController::class, 'indexNotifikasi']);
            Route::put('/notifikasi/{id}/baca',            [RekamMedisController::class, 'bacaNotifikasi']);

            // -----------------------------------------------------------------
            // FORM REGISTRY — Runtime (Fase 1 + 3 + 4)
            // -----------------------------------------------------------------
            Route::get('/forms',                           [RekamMedisController::class, 'indexForms']);
            Route::get('/form/{code}/render',              [RekamMedisController::class, 'renderForm']);
            Route::post('/form/{code}/submit',             [RekamMedisController::class, 'submitForm']);
            Route::post('/document/{id}/mark-rendered',    [RekamMedisController::class, 'markDocumentRendered']);
            Route::post('/document/{id}/finalize',         [RekamMedisController::class, 'finalizeDocument']);
            Route::get('/document/{id}/render',            [RekamMedisController::class, 'showDocumentSnapshot']);

            // Fase 4 — Signature flow
            Route::post('/document/{id}/sign',             [RekamMedisController::class, 'signDocument']);
            Route::get('/document/{id}/signatures',        [RekamMedisController::class, 'listDocumentSignatures']);
            Route::post('/document/{id}/addendum',         [RekamMedisController::class, 'createAddendum']);
            Route::get('/signature/{signatureId}/verify',  [RekamMedisController::class, 'verifySignature']);
            Route::get('/signature/{signatureId}/audit',   [RekamMedisController::class, 'auditSignature']);
            Route::get('/ttd-queue',                       [RekamMedisController::class, 'ttdQueue']);

            // Fase 6 — Audit log per dokumen
            Route::get('/document/{id}/audit-log',         [RekamMedisController::class, 'documentAuditLog']);
        });

        // -----------------------------------------------------------------
        // DASHBOARD
        // -----------------------------------------------------------------
        Route::prefix('dashboard')->group(function () {
            Route::get('/statistik',                        [DashboardController::class, 'statistik']);
            Route::get('/kunjungan-hari-ini',               [DashboardController::class, 'kunjunganHariIni']);
            Route::get('/antrian-aktif',                    [DashboardController::class, 'antrianAktif']);
            Route::get('/pendapatan',                       [DashboardController::class, 'pendapatan']);
            Route::get('/kunjungan-chart',                  [DashboardController::class, 'getVisitChart']);
            Route::get('/diagnosis-stats',                  [DashboardController::class, 'getDiagnosisStats']);

            Route::get('/follow-up/hari-ini',               [DashboardController::class, 'followUpHariIni']);
            Route::get('/follow-up/minggu-ini',             [DashboardController::class, 'followUpMingguIni']);
            Route::get('/follow-up/statistik',              [DashboardController::class, 'followUpStatistik']);

            Route::get('/stok-alert',                       [DashboardController::class, 'stokAlert']);
            Route::get('/bpjs-expired',                     [DashboardController::class, 'bpjsExpiredAlert']);
            Route::get('/satusehat-status',                 [DashboardController::class, 'satusehatStatus']);

            Route::get('/laporan/kunjungan',                [DashboardController::class, 'laporanKunjungan']);
            Route::get('/laporan/pendapatan',               [DashboardController::class, 'laporanPendapatan']);
            Route::get('/laporan/klaim',                    [DashboardController::class, 'laporanKlaim']);
        });

        // -----------------------------------------------------------------
        // MASTER DATA
        // -----------------------------------------------------------------
        Route::prefix('master')->group(function () {
            Route::get('/profil-klinik',                    [MasterDataController::class, 'showProfilKlinik']);
            Route::put('/profil-klinik',                    [MasterDataController::class, 'updateProfilKlinik']);
            Route::post('/profil-klinik/logo',              [MasterDataController::class, 'uploadProfilKlinikLogo']);
            Route::delete('/profil-klinik/logo',            [MasterDataController::class, 'deleteProfilKlinikLogo']);

            Route::get('/nomor-dokumen',                    [MasterDataController::class, 'indexNomorDokumen']);
            Route::put('/nomor-dokumen/{id}',               [MasterDataController::class, 'updateNomorDokumen']);

            Route::get('/roles',                            [MasterDataController::class, 'indexRoles']);
            Route::post('/roles',                           [MasterDataController::class, 'storeRole']);
            Route::put('/roles/{id}',                       [MasterDataController::class, 'updateRole']);
            Route::delete('/roles/{id}',                    [MasterDataController::class, 'deleteRole']);

            Route::get('/pegawai',                          [MasterDataController::class, 'indexPegawai']);
            Route::get('/pegawai/{id}',                     [MasterDataController::class, 'showPegawai']);
            Route::post('/pegawai',                         [MasterDataController::class, 'storePegawai']);
            Route::put('/pegawai/{id}',                     [MasterDataController::class, 'updatePegawai']);
            Route::delete('/pegawai/{id}',                  [MasterDataController::class, 'deletePegawai']);
            Route::put('/pegawai/{id}/reset-password',      [MasterDataController::class, 'resetPasswordPegawai']);

            Route::get('/penjamin',                         [MasterDataController::class, 'indexPenjamin']);
            Route::post('/penjamin',                        [MasterDataController::class, 'storePenjamin']);
            Route::put('/penjamin/{id}',                    [MasterDataController::class, 'updatePenjamin']);
            Route::delete('/penjamin/{id}',                 [MasterDataController::class, 'deletePenjamin']);

            // Billing Categories — kategori grouping rincian tagihan Kasir
            Route::get('/kategori-tagihan',                 [MasterDataController::class, 'indexBillingCategory']);
            Route::post('/kategori-tagihan',                [MasterDataController::class, 'storeBillingCategory']);
            Route::put('/kategori-tagihan/reorder',         [MasterDataController::class, 'reorderBillingCategory']);
            Route::put('/kategori-tagihan/{id}',            [MasterDataController::class, 'updateBillingCategory']);
            Route::delete('/kategori-tagihan/{id}',         [MasterDataController::class, 'deleteBillingCategory']);

            Route::get('/tindakan/template-csv',            [MasterDataController::class, 'templateCsv'])->defaults('type', 'tindakan');
            Route::get('/tindakan/export-csv',              [MasterDataController::class, 'exportCsv'])->defaults('type', 'tindakan');
            Route::post('/tindakan/import-csv',             [MasterDataController::class, 'importCsv'])->defaults('type', 'tindakan');
            Route::get('/tindakan/kategori-list',           [MasterDataController::class, 'kategoriListTindakan']);
            Route::get('/tindakan/kategori',                [MasterDataController::class, 'indexProcedureCategories']);
            Route::post('/tindakan/kategori',               [MasterDataController::class, 'storeProcedureCategory']);
            Route::put('/tindakan/kategori/{id}',           [MasterDataController::class, 'updateProcedureCategory']);
            Route::delete('/tindakan/kategori/{id}',        [MasterDataController::class, 'deleteProcedureCategory']);
            Route::get('/tindakan',                         [MasterDataController::class, 'indexTindakan']);
            Route::post('/tindakan',                        [MasterDataController::class, 'storeTindakan']);
            Route::put('/tindakan/{id}',                    [MasterDataController::class, 'updateTindakan']);
            Route::delete('/tindakan/{id}',                 [MasterDataController::class, 'deleteTindakan']);

            // CSV (template/export/import) — WAJIB didaftar SEBELUM route /{id}
            Route::get('/icd10/template-csv',               [MasterDataController::class, 'templateCsv'])->defaults('type', 'icd10')->middleware('permission:master_icd.read');
            Route::get('/icd10/export-csv',                 [MasterDataController::class, 'exportCsv'])->defaults('type', 'icd10')->middleware('permission:master_icd.read');
            Route::post('/icd10/import-csv',                [MasterDataController::class, 'importCsv'])->defaults('type', 'icd10')->middleware('permission:master_icd.write');
            Route::get('/icd10',                            [MasterDataController::class, 'indexIcd10'])->middleware('permission:master_icd.read');
            Route::post('/icd10',                           [MasterDataController::class, 'storeIcd10'])->middleware('permission:master_icd.write');
            Route::put('/icd10/{id}',                       [MasterDataController::class, 'updateIcd10'])->middleware('permission:master_icd.write');
            Route::delete('/icd10/{id}',                    [MasterDataController::class, 'deleteIcd10'])->middleware('permission:master_icd.delete');

            Route::get('/icd9/template-csv',                [MasterDataController::class, 'templateCsv'])->defaults('type', 'icd9')->middleware('permission:master_icd.read');
            Route::get('/icd9/export-csv',                  [MasterDataController::class, 'exportCsv'])->defaults('type', 'icd9')->middleware('permission:master_icd.read');
            Route::post('/icd9/import-csv',                 [MasterDataController::class, 'importCsv'])->defaults('type', 'icd9')->middleware('permission:master_icd.write');
            Route::get('/icd9',                             [MasterDataController::class, 'indexIcd9'])->middleware('permission:master_icd.read');
            Route::post('/icd9',                            [MasterDataController::class, 'storeIcd9'])->middleware('permission:master_icd.write');
            Route::put('/icd9/{id}',                        [MasterDataController::class, 'updateIcd9'])->middleware('permission:master_icd.write');
            Route::delete('/icd9/{id}',                     [MasterDataController::class, 'deleteIcd9'])->middleware('permission:master_icd.delete');

            // Jenis Penunjang (diagnostic_test_types) — master dikelola di modul Penunjang
            Route::get('/diagnostic-test-type',             [MasterDataController::class, 'indexDiagnosticTestType']);
            Route::post('/diagnostic-test-type',            [MasterDataController::class, 'storeDiagnosticTestType']);
            Route::put('/diagnostic-test-type/{id}',        [MasterDataController::class, 'updateDiagnosticTestType']);
            Route::delete('/diagnostic-test-type/{id}',     [MasterDataController::class, 'deleteDiagnosticTestType']);

            Route::get('/obat/template-csv',                [MasterDataController::class, 'templateCsv'])->defaults('type', 'obat')->middleware('permission:master_obat.read');
            Route::get('/obat/export-csv',                  [MasterDataController::class, 'exportCsv'])->defaults('type', 'obat')->middleware('permission:master_obat.read');
            Route::post('/obat/import-csv',                 [MasterDataController::class, 'importCsv'])->defaults('type', 'obat')->middleware('permission:master_obat.write');
            Route::get('/obat',                             [MasterDataController::class, 'indexObat'])->middleware('permission:master_obat.read');
            Route::post('/obat',                            [MasterDataController::class, 'storeObat'])->middleware('permission:master_obat.write');
            Route::put('/obat/{id}',                        [MasterDataController::class, 'updateObat'])->middleware('permission:master_obat.write');
            Route::delete('/obat/{id}',                     [MasterDataController::class, 'deleteObat'])->middleware('permission:master_obat.delete');

            Route::get('/bhp/template-csv',                 [MasterDataController::class, 'templateCsv'])->defaults('type', 'bhp')->middleware('permission:master_bhp.read');
            Route::get('/bhp/export-csv',                   [MasterDataController::class, 'exportCsv'])->defaults('type', 'bhp')->middleware('permission:master_bhp.read');
            Route::post('/bhp/import-csv',                  [MasterDataController::class, 'importCsv'])->defaults('type', 'bhp')->middleware('permission:master_bhp.write');
            Route::get('/bhp',                              [MasterDataController::class, 'indexBhp'])->middleware('permission:master_bhp.read');
            Route::post('/bhp',                             [MasterDataController::class, 'storeBhp'])->middleware('permission:master_bhp.write');
            Route::put('/bhp/{id}',                         [MasterDataController::class, 'updateBhp'])->middleware('permission:master_bhp.write');
            Route::delete('/bhp/{id}',                      [MasterDataController::class, 'deleteBhp'])->middleware('permission:master_bhp.delete');

            Route::get('/iol/template-csv',                 [MasterDataController::class, 'templateCsv'])->defaults('type', 'iol')->middleware('permission:master_iol.read');
            Route::get('/iol/export-csv',                   [MasterDataController::class, 'exportCsv'])->defaults('type', 'iol')->middleware('permission:master_iol.read');
            Route::post('/iol/import-csv',                  [MasterDataController::class, 'importCsv'])->defaults('type', 'iol')->middleware('permission:master_iol.write');
            Route::get('/iol',                              [MasterDataController::class, 'indexIol'])->middleware('permission:master_iol.read');
            Route::post('/iol',                             [MasterDataController::class, 'storeIol'])->middleware('permission:master_iol.write');
            Route::put('/iol/{id}',                         [MasterDataController::class, 'updateIol'])->middleware('permission:master_iol.write');
            Route::delete('/iol/{id}',                      [MasterDataController::class, 'deleteIol'])->middleware('permission:master_iol.delete');

            // Alat Medis CSV (view fisik di /inventori-farmasi/alat-medis, CRUD pakai MedicalEquipmentController;
            // CSV pakai generic MasterDataController karena frontend csv client hit /master/{type}/*-csv)
            Route::get('/alat-medis/template-csv',          [MasterDataController::class, 'templateCsv'])->defaults('type', 'alat-medis')->middleware('permission:inventori_farmasi.read');
            Route::get('/alat-medis/export-csv',            [MasterDataController::class, 'exportCsv'])->defaults('type', 'alat-medis')->middleware('permission:inventori_farmasi.read');
            Route::post('/alat-medis/import-csv',           [MasterDataController::class, 'importCsv'])->defaults('type', 'alat-medis')->middleware('permission:inventori_farmasi.write');

            Route::get('/paket-bedah',                      [MasterDataController::class, 'indexPaketBedah']);
            Route::post('/paket-bedah',                     [MasterDataController::class, 'storePaketBedah']);
            Route::put('/paket-bedah/{id}',                 [MasterDataController::class, 'updatePaketBedah']);
            Route::delete('/paket-bedah/{id}',              [MasterDataController::class, 'deletePaketBedah']);

            Route::get('/tarif/tindakan',                   [MasterDataController::class, 'indexTarifTindakan']);
            Route::post('/tarif/tindakan',                  [MasterDataController::class, 'storeTarifTindakan']);
            Route::put('/tarif/tindakan/{id}',              [MasterDataController::class, 'updateTarifTindakan']);
            Route::delete('/tarif/tindakan/{id}',           [MasterDataController::class, 'deleteTarifTindakan']);
            Route::get('/tarif/tindakan/export-csv',        [MasterDataController::class, 'exportTarifCsv'])->defaults('type', 'tindakan');
            Route::post('/tarif/tindakan/import-csv',       [MasterDataController::class, 'importTarifCsv'])->defaults('type', 'tindakan');

            Route::get('/tarif/obat',                       [MasterDataController::class, 'indexTarifObat']);
            Route::post('/tarif/obat',                      [MasterDataController::class, 'storeTarifObat']);
            Route::put('/tarif/obat/{id}',                  [MasterDataController::class, 'updateTarifObat']);
            Route::delete('/tarif/obat/{id}',               [MasterDataController::class, 'deleteTarifObat']);
            Route::get('/tarif/obat/export-csv',            [MasterDataController::class, 'exportTarifCsv'])->defaults('type', 'obat');
            Route::post('/tarif/obat/import-csv',           [MasterDataController::class, 'importTarifCsv'])->defaults('type', 'obat');

            Route::get('/tarif/bhp',                        [MasterDataController::class, 'indexTarifBhp']);
            Route::post('/tarif/bhp',                       [MasterDataController::class, 'storeTarifBhp']);
            Route::put('/tarif/bhp/{id}',                   [MasterDataController::class, 'updateTarifBhp']);
            Route::delete('/tarif/bhp/{id}',                [MasterDataController::class, 'deleteTarifBhp']);
            Route::get('/tarif/bhp/export-csv',             [MasterDataController::class, 'exportTarifCsv'])->defaults('type', 'bhp');
            Route::post('/tarif/bhp/import-csv',            [MasterDataController::class, 'importTarifCsv'])->defaults('type', 'bhp');

            Route::get('/tarif/iol',                        [MasterDataController::class, 'indexTarifIol']);
            Route::post('/tarif/iol',                       [MasterDataController::class, 'storeTarifIol']);
            Route::put('/tarif/iol/{id}',                   [MasterDataController::class, 'updateTarifIol']);
            Route::delete('/tarif/iol/{id}',                [MasterDataController::class, 'deleteTarifIol']);
            Route::get('/tarif/iol/export-csv',             [MasterDataController::class, 'exportTarifCsv'])->defaults('type', 'iol');
            Route::post('/tarif/iol/import-csv',            [MasterDataController::class, 'importTarifCsv'])->defaults('type', 'iol');

            Route::get('/jenis-dokumen',                    [MasterDataController::class, 'indexJenisDokumen']);
            Route::post('/jenis-dokumen',                   [MasterDataController::class, 'storeJenisDokumen']);
            Route::put('/jenis-dokumen/{id}',               [MasterDataController::class, 'updateJenisDokumen']);
            Route::delete('/jenis-dokumen/{id}',            [MasterDataController::class, 'deleteJenisDokumen']);

            Route::get('/template-dokumen',                 [MasterDataController::class, 'indexTemplateDokumen']);
            Route::get('/template-dokumen/{id}',            [MasterDataController::class, 'showTemplateDokumen']);
            Route::post('/template-dokumen',                [MasterDataController::class, 'storeTemplateDokumen']);
            Route::put('/template-dokumen/{id}',            [MasterDataController::class, 'updateTemplateDokumen']);
            Route::delete('/template-dokumen/{id}',         [MasterDataController::class, 'deleteTemplateDokumen']);

            Route::get('/stasiun-dokumen',                  [MasterDataController::class, 'indexStasiunDokumen']);
            Route::put('/stasiun-dokumen/{id}',             [MasterDataController::class, 'updateStasiunDokumen']);

            // -----------------------------------------------------------------
            // FORM REGISTRY — Master Form Template (Fase 1 + Fase 2)
            // RBAC: form_template.{read|write|delete}; superadmin auto-bypass.
            // -----------------------------------------------------------------
            Route::get('/field-registry',                              [MasterDataController::class, 'fieldRegistry'])->middleware('permission:form_template.read');
            Route::get('/station-sections',                            [MasterDataController::class, 'stationSections'])->middleware('permission:form_template.read');
            Route::get('/document-types',                              [MasterDataController::class, 'indexDocumentTypes'])->middleware('permission:form_template.read');
            Route::post('/document-type',                              [MasterDataController::class, 'storeDocumentType'])->middleware('permission:form_template.write');
            Route::put('/document-type/{id}',                          [MasterDataController::class, 'updateDocumentType'])->middleware('permission:form_template.write');
            Route::delete('/document-type/{id}',                       [MasterDataController::class, 'destroyDocumentType'])->middleware('permission:form_template.write');

            // Parser (Fase 2) — WAJIB didaftar SEBELUM /form-template/{id}
            Route::post('/form-template/upload',                       [MasterDataController::class, 'uploadFormTemplate'])->middleware('permission:form_template.write');
            Route::get('/form-template/parse-result/{parseId}',        [MasterDataController::class, 'parseResultFormTemplate'])->middleware('permission:form_template.write');

            Route::get('/form-template',                               [MasterDataController::class, 'indexFormTemplate'])->middleware('permission:form_template.read');
            Route::post('/form-template',                              [MasterDataController::class, 'storeFormTemplate'])->middleware('permission:form_template.write');
            Route::get('/form-template/{id}',                          [MasterDataController::class, 'showFormTemplate'])->middleware('permission:form_template.read');
            Route::put('/form-template/{id}',                          [MasterDataController::class, 'updateFormTemplate'])->middleware('permission:form_template.write');
            Route::post('/form-template/{id}/activate',                [MasterDataController::class, 'activateFormTemplate'])->middleware('permission:form_template.write');
            Route::post('/form-template/{id}/deactivate',              [MasterDataController::class, 'deactivateFormTemplate'])->middleware('permission:form_template.write');
        });

        // -----------------------------------------------------------------
        // INVENTORI FARMASI — Master Supplier
        // -----------------------------------------------------------------
        Route::prefix('inventori-farmasi/supplier')->group(function () {
            Route::get('/',         [SupplierController::class, 'index'])->middleware('permission:supplier.read');
            Route::post('/',        [SupplierController::class, 'store'])->middleware('permission:supplier.write');
            Route::put('/{id}',     [SupplierController::class, 'update'])->middleware('permission:supplier.write');
            Route::delete('/{id}',  [SupplierController::class, 'destroy'])->middleware('permission:supplier.delete');
        });

        // -----------------------------------------------------------------
        // INVENTORI FARMASI — Pembelian (Purchase Order)
        // -----------------------------------------------------------------
        Route::prefix('inventori-farmasi/pembelian')->group(function () {
            Route::get('/',              [PurchaseOrderController::class, 'index'])->middleware('permission:pembelian.read');
            Route::post('/',             [PurchaseOrderController::class, 'store'])->middleware('permission:pembelian.write');
            Route::get('/{id}',          [PurchaseOrderController::class, 'show'])->middleware('permission:pembelian.read');
            Route::put('/{id}',          [PurchaseOrderController::class, 'update'])->middleware('permission:pembelian.write');
            Route::delete('/{id}',       [PurchaseOrderController::class, 'destroy'])->middleware('permission:pembelian.delete');
            Route::post('/{id}/cancel',  [PurchaseOrderController::class, 'cancel'])->middleware('permission:pembelian.write');
        });

        // -----------------------------------------------------------------
        // INVENTORI FARMASI — Penerimaan Barang (Goods Receipt / GRN)
        // -----------------------------------------------------------------
        Route::prefix('inventori-farmasi/penerimaan')->group(function () {
            Route::get('/from-po/{poId}',  [GoodsReceiptController::class, 'prepareFromPo'])->middleware('permission:penerimaan.write');
            Route::get('/',                [GoodsReceiptController::class, 'index'])->middleware('permission:penerimaan.read');
            Route::post('/',               [GoodsReceiptController::class, 'store'])->middleware('permission:penerimaan.write');
            Route::get('/{id}',            [GoodsReceiptController::class, 'show'])->middleware('permission:penerimaan.read');
            Route::delete('/{id}',         [GoodsReceiptController::class, 'destroy'])->middleware('permission:penerimaan.delete');
        });

        // -----------------------------------------------------------------
        // INVENTORI FARMASI — Inbox admin (gabung SUBMITTED request + retur)
        // -----------------------------------------------------------------
        Route::get('inventori-farmasi/inbox', [UnitRequestController::class, 'inbox'])
            ->middleware('permission:request_unit.read');

        Route::get('inventori-farmasi/stock/{type}', [UnitRequestController::class, 'stock'])
            ->middleware('permission:request_unit.read');

        // Opname & CSV stok (obat & bhp)
        Route::post('inventori-farmasi/stock/opname', [InventoryStockController::class, 'opname'])
            ->middleware('permission:inventori_farmasi.write');
        Route::get('inventori-farmasi/stock/{type}/template-csv', [InventoryStockController::class, 'templateCsv'])
            ->middleware('permission:inventori_farmasi.read');
        Route::get('inventori-farmasi/stock/{type}/export-csv', [InventoryStockController::class, 'exportCsv'])
            ->middleware('permission:inventori_farmasi.read');
        Route::post('inventori-farmasi/stock/{type}/import-csv', [InventoryStockController::class, 'importCsv'])
            ->middleware('permission:inventori_farmasi.write');

        // -----------------------------------------------------------------
        // INVENTORI FARMASI — Alat Medis (master + tarif + usage)
        // -----------------------------------------------------------------
        Route::prefix('inventori-farmasi/alat-medis')->group(function () {
            Route::get('/',                       [MedicalEquipmentController::class, 'index'])->middleware('permission:inventori_farmasi.read');
            Route::post('/',                      [MedicalEquipmentController::class, 'store'])->middleware('permission:inventori_farmasi.write');
            Route::get('/{id}',                   [MedicalEquipmentController::class, 'show'])->middleware('permission:inventori_farmasi.read');
            Route::put('/{id}',                   [MedicalEquipmentController::class, 'update'])->middleware('permission:inventori_farmasi.write');
            Route::delete('/{id}',                [MedicalEquipmentController::class, 'destroy'])->middleware('permission:inventori_farmasi.delete');

            // Tarif per insurer
            Route::get('/{id}/tarif',             [MedicalEquipmentController::class, 'listTariffs'])->middleware('permission:inventori_farmasi.read');
            Route::post('/{id}/tarif',            [MedicalEquipmentController::class, 'upsertTariff'])->middleware('permission:inventori_farmasi.write');
            Route::delete('/tarif/{tariffId}',    [MedicalEquipmentController::class, 'deleteTariff'])->middleware('permission:inventori_farmasi.write');
        });
        // Usage (dipakai dari BedahView / DokterView)
        Route::get('/alat-medis/visit/{visitId}/usages',     [MedicalEquipmentController::class, 'usagesByVisit']);
        Route::post('/alat-medis/usage',                     [MedicalEquipmentController::class, 'recordUsage']);
        Route::delete('/alat-medis/usage/{id}',              [MedicalEquipmentController::class, 'deleteUsage']);

        // -----------------------------------------------------------------
        // INVENTORI FARMASI — Request dari Unit (klinik → gudang inventori)
        // -----------------------------------------------------------------
        Route::prefix('inventori-farmasi/unit-request')->group(function () {
            Route::get('/',              [UnitRequestController::class, 'index'])->middleware('permission:request_unit.read');
            Route::post('/',             [UnitRequestController::class, 'store'])->middleware('permission:request_unit.write');
            Route::get('/{id}',          [UnitRequestController::class, 'show'])->middleware('permission:request_unit.read');
            Route::put('/{id}',          [UnitRequestController::class, 'update'])->middleware('permission:request_unit.write');
            Route::post('/{id}/submit',  [UnitRequestController::class, 'submit'])->middleware('permission:request_unit.write');
            Route::post('/{id}/approve', [UnitRequestController::class, 'approve'])->middleware('permission:request_unit.write');
            Route::post('/{id}/reject',  [UnitRequestController::class, 'reject'])->middleware('permission:request_unit.write');
            Route::post('/{id}/deliver', [UnitRequestController::class, 'deliver'])->middleware('permission:request_unit.write');
            Route::post('/{id}/close',   [UnitRequestController::class, 'close'])->middleware('permission:request_unit.write');
            Route::delete('/{id}',       [UnitRequestController::class, 'destroy'])->middleware('permission:request_unit.delete');
        });

        // -----------------------------------------------------------------
        // INVENTORI FARMASI — Retur dari Unit (kembali ke stok inventori)
        // -----------------------------------------------------------------
        Route::prefix('inventori-farmasi/unit-return')->group(function () {
            Route::get('/',              [UnitReturnController::class, 'index'])->middleware('permission:request_unit.read');
            Route::post('/',             [UnitReturnController::class, 'store'])->middleware('permission:request_unit.write');
            Route::get('/{id}',          [UnitReturnController::class, 'show'])->middleware('permission:request_unit.read');
            Route::put('/{id}',          [UnitReturnController::class, 'update'])->middleware('permission:request_unit.write');
            Route::post('/{id}/submit',  [UnitReturnController::class, 'submit'])->middleware('permission:request_unit.write');
            Route::post('/{id}/receive', [UnitReturnController::class, 'receive'])->middleware('permission:request_unit.write');
            Route::post('/{id}/reject',  [UnitReturnController::class, 'reject'])->middleware('permission:request_unit.write');
            Route::delete('/{id}',       [UnitReturnController::class, 'destroy'])->middleware('permission:request_unit.delete');
        });

        // -----------------------------------------------------------------
        // INVENTORI FARMASI — Penentuan Harga (HPP & HJA)
        // -----------------------------------------------------------------
        Route::prefix('inventori-farmasi/harga')->group(function () {
            Route::get('/settings',           [InventoryPriceController::class, 'getSettings'])->middleware('permission:inventori_farmasi.read');
            Route::put('/settings',           [InventoryPriceController::class, 'updateSettings'])->middleware('permission:inventori_farmasi.write');

            // CSV — daftar SEBELUM route generic /{type} & /{type}/{itemId}
            Route::get('/{type}/template-csv', [InventoryPriceController::class, 'templateCsv'])->middleware('permission:inventori_farmasi.read');
            Route::get('/{type}/export-csv',   [InventoryPriceController::class, 'exportCsv'])->middleware('permission:inventori_farmasi.read');
            Route::post('/{type}/import-csv',  [InventoryPriceController::class, 'importCsv'])->middleware('permission:inventori_farmasi.write');

            Route::get('/{type}',             [InventoryPriceController::class, 'index'])->middleware('permission:inventori_farmasi.read');
            Route::put('/{type}/{itemId}',    [InventoryPriceController::class, 'upsert'])->middleware('permission:inventori_farmasi.write');
            Route::delete('/{type}/{itemId}', [InventoryPriceController::class, 'destroy'])->middleware('permission:inventori_farmasi.write');
        });

        // -----------------------------------------------------------------
        // TARIF & PAKET BEDAH (sub-modul standalone)
        // -----------------------------------------------------------------
        // Endpoint lama di /master/tarif/* dan /master/paket-bedah/* tetap
        // dipertahankan sebagai backward-compatible alias.
        Route::prefix('tarif-paket')->group(function () {
            // --- METODE BAYAR (insurer detail + tarif CSV per-insurer per-type) ---
            // CSV WAJIB didaftar SEBELUM route generic /metode-bayar/{id}
            Route::get('/metode-bayar/{id}/tarif/{type}/template-csv', [TarifPaketController::class, 'templateMetodeBayarCsv'])->middleware('permission:tarif_paket.read');
            Route::get('/metode-bayar/{id}/tarif/{type}/export-csv',   [TarifPaketController::class, 'exportMetodeBayarCsv'])->middleware('permission:tarif_paket.read');
            Route::post('/metode-bayar/{id}/tarif/{type}/import-csv',  [TarifPaketController::class, 'importMetodeBayarCsv'])->middleware('permission:tarif_paket.write');
            Route::get('/metode-bayar/{id}',                [TarifPaketController::class, 'showMetodeBayar'])->middleware('permission:tarif_paket.read');

            // --- TARIF per penjamin (tindakan/obat/bhp/iol) — CRUD per-row ---
            Route::get('/tarif/{type}',                     [TarifPaketController::class, 'indexTarif'])->middleware('permission:tarif_paket.read');
            Route::post('/tarif/{type}',                    [TarifPaketController::class, 'storeTarif'])->middleware('permission:tarif_paket.write');
            Route::put('/tarif/{type}/{id}',                [TarifPaketController::class, 'updateTarif'])->middleware('permission:tarif_paket.write');
            Route::delete('/tarif/{type}/{id}',             [TarifPaketController::class, 'deleteTarif'])->middleware('permission:tarif_paket.delete');

            // --- HELPER: harga master live per item (untuk auto-fill saat tambah tarif) ---
            Route::get('/master-price/{type}/{itemId}',     [TarifPaketController::class, 'masterPrice'])->middleware('permission:tarif_paket.read');

            // --- PAKET BEDAH — CSV (WAJIB didaftar SEBELUM /paket-bedah/{id}) ---
            Route::get('/paket-bedah/template-csv',         [TarifPaketController::class, 'templatePaketCsv'])->middleware('permission:tarif_paket.read');
            Route::get('/paket-bedah/export-csv',           [TarifPaketController::class, 'exportPaketCsv'])->middleware('permission:tarif_paket.read');
            Route::post('/paket-bedah/import-csv',          [TarifPaketController::class, 'importPaketCsv'])->middleware('permission:tarif_paket.write');

            // --- PAKET BEDAH (CRUD utama) ---
            Route::get('/paket-bedah',                      [TarifPaketController::class, 'indexPaket'])->middleware('permission:tarif_paket.read');
            Route::post('/paket-bedah',                     [TarifPaketController::class, 'storePaket'])->middleware('permission:tarif_paket.write');
            Route::get('/paket-bedah/{id}',                 [TarifPaketController::class, 'showPaket'])->middleware('permission:tarif_paket.read');
            Route::put('/paket-bedah/{id}',                 [TarifPaketController::class, 'updatePaket'])->middleware('permission:tarif_paket.write');
            Route::delete('/paket-bedah/{id}',              [TarifPaketController::class, 'deletePaket'])->middleware('permission:tarif_paket.delete');

            // --- PAKET BEDAH — items (komposisi paket) ---
            Route::get('/paket-bedah/{id}/items',           [TarifPaketController::class, 'indexItems'])->middleware('permission:tarif_paket.read');
            Route::post('/paket-bedah/{id}/items',          [TarifPaketController::class, 'addItem'])->middleware('permission:tarif_paket.write');
            Route::put('/paket-bedah/{id}/items/{itemId}',  [TarifPaketController::class, 'updateItem'])->middleware('permission:tarif_paket.write');
            Route::delete('/paket-bedah/{id}/items/{itemId}', [TarifPaketController::class, 'removeItem'])->middleware('permission:tarif_paket.delete');

            // --- PAKET BEDAH — tariffs (harga jual per penjamin, auto-diskon) ---
            Route::get('/paket-bedah/{id}/tariffs',         [TarifPaketController::class, 'indexTariffs'])->middleware('permission:tarif_paket.read');
            Route::post('/paket-bedah/{id}/tariffs',        [TarifPaketController::class, 'upsertTariff'])->middleware('permission:tarif_paket.write');
            Route::delete('/paket-bedah/{id}/tariffs/{tariffId}', [TarifPaketController::class, 'deleteTariff'])->middleware('permission:tarif_paket.delete');
        });

        // -----------------------------------------------------------------
        // INTEGRASI
        // -----------------------------------------------------------------
        Route::prefix('integrasi')->group(function () {
            Route::get('/status',                           [IntegrasiController::class, 'statusSemua']);
            Route::post('/test/{system}',                   [IntegrasiController::class, 'testKoneksi']);
            Route::get('/config',                           [IntegrasiController::class, 'indexConfig']);
            Route::put('/config/{id}',                      [IntegrasiController::class, 'updateConfig']);

            Route::get('/bpjs/vclaim-log',                  [IntegrasiController::class, 'vclaimpLog']);
            Route::get('/bpjs/vclaim-log/{id}',             [IntegrasiController::class, 'showVclaimpLog']);
            Route::get('/bpjs/antrean-log',                 [IntegrasiController::class, 'antreanLog']);
            Route::get('/bpjs/icare-log',                   [IntegrasiController::class, 'icareLog']);
            Route::get('/bpjs/inacbgs-log',                 [IntegrasiController::class, 'inacbgsLog']);

            Route::get('/bpjs/rujukan-masuk',               [IntegrasiController::class, 'indexRujukanMasuk']);
            Route::get('/bpjs/rujukan-masuk/{id}',          [IntegrasiController::class, 'showRujukanMasuk']);
            Route::get('/bpjs/rujukan-keluar',              [IntegrasiController::class, 'indexRujukanKeluar']);
            Route::get('/bpjs/rujukan-keluar/{id}',         [IntegrasiController::class, 'showRujukanKeluar']);

            Route::get('/bpjs/surat-kontrol',               [IntegrasiController::class, 'indexSuratKontrol']);
            Route::get('/bpjs/surat-kontrol/{id}',          [IntegrasiController::class, 'showSuratKontrol']);
            Route::post('/bpjs/surat-kontrol/{id}/submit',  [IntegrasiController::class, 'submitSuratKontrol']);

            Route::get('/satusehat/sync-log',               [IntegrasiController::class, 'satusehatSyncLog']);
            Route::get('/satusehat/sync-log/{id}',          [IntegrasiController::class, 'showSatusehatSyncLog']);
            Route::get('/satusehat/resource-log',           [IntegrasiController::class, 'satusehatResourceLog']);
            Route::post('/satusehat/sync-manual',           [IntegrasiController::class, 'satusehatSyncManual']);
            Route::post('/satusehat/retry/{logId}',         [IntegrasiController::class, 'satusehatRetry']);
        });

        // -----------------------------------------------------------------
        // ASURANSI / TPA Non-BPJS — verifikasi eligibility + klaim workflow
        // Tidak menyentuh BPJS (KlaimController). Permission: kasir.* (billing).
        // Spec: Docs/ARUMED_INSURANCE_TPA_MODULE.md
        // -----------------------------------------------------------------
        Route::prefix('asuransi')->group(function () {
            // Verifikasi eligibility (input manual hasil cek portal TPA)
            Route::get('/verifikasi/pending',     [AsuransiController::class, 'pendingVerifications'])->middleware('permission:kasir.read');
            Route::get('/verifikasi/in-service',  [AsuransiController::class, 'inServiceVerifications'])->middleware('permission:kasir.read');
            Route::get('/verifikasi/{visitId}',   [AsuransiController::class, 'showVerifikasi'])->middleware('permission:kasir.read');
            Route::post('/verifikasi',            [AsuransiController::class, 'storeVerifikasi'])->middleware('permission:kasir.write');
            Route::put('/verifikasi/{id}',        [AsuransiController::class, 'updateVerifikasi'])->middleware('permission:kasir.write');
            Route::get('/billing/{visitId}',      [AsuransiController::class, 'showBilling'])->middleware('permission:kasir.read');

            // Klaim
            Route::get('/klaim',                  [AsuransiController::class, 'indexKlaim'])->middleware('permission:kasir.read');
            Route::get('/klaim/{id}',             [AsuransiController::class, 'showKlaim'])->middleware('permission:kasir.read');
            Route::post('/klaim',                 [AsuransiController::class, 'storeKlaim'])->middleware('permission:kasir.write');
            Route::put('/klaim/{id}',             [AsuransiController::class, 'updateKlaim'])->middleware('permission:kasir.write');
            Route::post('/klaim/{id}/submit',     [AsuransiController::class, 'submitKlaim'])->middleware('permission:kasir.write');
            Route::put('/klaim/{id}/status',      [AsuransiController::class, 'updateStatusKlaim'])->middleware('permission:kasir.write');
            Route::post('/klaim/{id}/resubmit',   [AsuransiController::class, 'resubmitKlaim'])->middleware('permission:kasir.write');
            Route::get('/klaim/{id}/logs',        [AsuransiController::class, 'logsKlaim'])->middleware('permission:kasir.read');

            // Laporan
            Route::get('/aging',                  [AsuransiController::class, 'agingReport'])->middleware('permission:kasir.read');
            Route::get('/outstanding',            [AsuransiController::class, 'outstandingReport'])->middleware('permission:kasir.read');
            Route::get('/summary',                [AsuransiController::class, 'dashboardSummary'])->middleware('permission:kasir.read');

            // Master — Document Requirements per TPA
            Route::get('/insurer/{insurerId}/dokumen-requirement',  [AsuransiController::class, 'indexDocRequirement'])->middleware('permission:kasir.read');
            Route::post('/insurer/{insurerId}/dokumen-requirement', [AsuransiController::class, 'storeDocRequirement'])->middleware('permission:kasir.write');
            Route::put('/dokumen-requirement/{id}',                 [AsuransiController::class, 'updateDocRequirement'])->middleware('permission:kasir.write');
            Route::delete('/dokumen-requirement/{id}',              [AsuransiController::class, 'deleteDocRequirement'])->middleware('permission:kasir.delete');
        });

        // -----------------------------------------------------------------
        // RBAC (User / Role / Permission management) — Superadmin only
        // -----------------------------------------------------------------
        Route::middleware('role:superadmin')->prefix('rbac')->group(function () {
            // Permissions (read-only, seeded)
            Route::get('/permissions',          [PermissionController::class, 'index']);
            Route::get('/permissions/flat',     [PermissionController::class, 'flat']);
            // Override nama tampilan modul (UI-only) untuk matriks
            Route::put('/permissions/module-label/{module}',        [PermissionController::class, 'updateLabel']);
            Route::post('/permissions/module-label/{module}/reset', [PermissionController::class, 'resetLabel']);

            // Roles
            Route::get('/roles',                [RoleController::class, 'index']);
            Route::post('/roles',               [RoleController::class, 'store']);
            Route::get('/roles/{id}',           [RoleController::class, 'show']);
            Route::put('/roles/{id}',           [RoleController::class, 'update']);
            Route::delete('/roles/{id}',        [RoleController::class, 'destroy']);
            Route::put('/roles/{id}/permissions',[RoleController::class, 'syncPermissions']);

            // Users
            // CSV (definisikan sebelum /users/{id} agar tidak ketangkap wildcard)
            Route::get('/users/csv-template',          [UserController::class, 'csvTemplate']);
            Route::get('/users/export',                [UserController::class, 'exportCsv']);
            Route::post('/users/import',               [UserController::class, 'importCsv']);

            Route::get('/users',                       [UserController::class, 'index']);
            Route::post('/users',                      [UserController::class, 'store']);
            Route::get('/users/{id}',                  [UserController::class, 'show']);
            Route::put('/users/{id}',                  [UserController::class, 'update']);
            Route::delete('/users/{id}',               [UserController::class, 'destroy']);
            Route::patch('/users/{id}/toggle-aktif',   [UserController::class, 'toggleAktif']);
            Route::put('/users/{id}/reset-password',   [UserController::class, 'resetPassword']);
            Route::put('/users/{id}/reset-pin',        [UserController::class, 'resetPin']);
        });

    }); // <--- INI ADALAH PENUTUP WAJIB LOGIN (AUTH:API)

}); // <--- INI ADALAH PENUTUP PREFIX V1 (SELESAI)