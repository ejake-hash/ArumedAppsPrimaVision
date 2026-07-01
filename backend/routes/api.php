<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdmisiController;
use App\Http\Controllers\PerawatController;
use App\Http\Controllers\RefraksiController;
use App\Http\Controllers\RefractionOptionController;
use App\Http\Controllers\DokterController;
use App\Http\Controllers\PenunjangController;
use App\Http\Controllers\PenunjangWorklistController;
use App\Http\Controllers\PenunjangIngestController;
use App\Http\Controllers\BedahController;
use App\Http\Controllers\RuangTindakanController;
use App\Http\Controllers\RanapController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\FarmasiController;
use App\Http\Controllers\PharmacySaleController;
use App\Http\Controllers\KasirController;
use App\Http\Controllers\KlaimController;
use App\Http\Controllers\RekamMedisController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\InventoriReportController;
use App\Http\Controllers\UnitRequestController;
use App\Http\Controllers\InventoryStockController;
use App\Http\Controllers\StockOpnameSessionController;
use App\Http\Controllers\MedicalEquipmentController;
use App\Http\Controllers\UnitReturnController;
use App\Http\Controllers\TarifPaketController;
use App\Http\Controllers\IntegrasiController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\JadwalDokterController;
use App\Http\Controllers\MarketingReportController;
use App\Http\Controllers\KeuanganController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\TvDisplaySettingController;
use App\Http\Controllers\TvAudioSettingController;
use App\Http\Controllers\TvMediaSettingController;
use App\Http\Controllers\TvDeviceController;
use App\Http\Controllers\TvBrandingSettingController;
use App\Http\Controllers\TvBedAvailabilityController;
use App\Http\Controllers\AsuransiController;
use App\Http\Controllers\IgdController;
use App\Http\Controllers\AntrolMobileController;

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
    // 0. ROUTE PATTERNS — id params yang SELALU UUID di-constraint ke format UUID.
    //    Tujuan: request dengan id non-UUID (mis. /farmasi/resep/notauuid) langsung
    //    404 sebelum menyentuh DB, sehingga TIDAK melempar QueryException 22P02 (500).
    //    Param non-UUID (type, station, jenis, module, code, token, system, noKartu)
    //    SENGAJA tidak di-constraint.
    // =========================================================================
    $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    foreach ([
        'id', 'visitId', 'patientId', 'itemId', 'spriId', 'tariffId', 'signatureId',
        'roomId', 'insurerId', 'employeeId', 'documentId', 'chargeId', 'scheduleId',
        'resepId', 'refractionId', 'poId', 'orderId', 'logId', 'klaimId', 'invoiceId', 'parseId',
    ] as $uuidParam) {
        Route::pattern($uuidParam, $uuid);
    }

    // =========================================================================
    // 1. PUBLIC — Auth (no middleware)
    // =========================================================================
    Route::prefix('auth')->group(function () {
        // Rate-limit brute-force: maks 5 percobaan login / menit per username+IP.
        // Penting karena password default akun stasiun ('888888') diketahui publik.
        // Limiter 'login' didefinisikan di App\Providers\AppServiceProvider.
        Route::post('/login',   [AuthController::class, 'login'])->middleware('throttle:login');
    });

    // =========================================================================
    // 2. Modul Rekam Medis Auto-Generate — WAJIB AUTH + permission master_data (maintenance template).
    //    (Dulu "PUBLIC SEMENTARA" tanpa token: siapa pun bisa list/buat template &
    //    upload 10MB ke auto-generate = vektor DoS + data liar. Ditutup.)
    // =========================================================================
    Route::prefix('rekam-medis')->middleware(['auth:api'])->group(function () {
        Route::post('/templates/auto-generate', [App\Http\Controllers\RmTemplateGeneratorController::class, 'autoGenerate'])->middleware('permission:master_data.write');
        Route::post('/templates/store', [App\Http\Controllers\RmTemplateController::class, 'store'])->middleware('permission:master_data.write');
        Route::get('/templates', [App\Http\Controllers\RmTemplateController::class, 'index'])->middleware('permission:master_data.read');
    });

    // =========================================================================
    // 2b. PUBLIC — Anjungan Mandiri (Kiosk Self-Service, no auth)
    // =========================================================================
    Route::prefix('anjungan')->group(function () {
        Route::get('/status',              [AdmisiController::class, 'anjunganStatus']);
        Route::get('/dokter-aktif',        [AdmisiController::class, 'anjunganDokterAktif']);
        // Rate-limit endpoint TULIS kiosk: limiter 'kiosk' dikunci per (PATH+IP) agar
        // tiap aksi punya bucket sendiri (bare 'throttle:N' berbagi 1 bucket/IP lintas
        // endpoint → pasien sah bisa 429 di tengah alur saat banyak kiosk 1 IP). Menahan
        // enumerasi NIK & DoS anonim; read polling TIDAK dibatasi. Lih. AppServiceProvider.
        Route::post('/tiket-umum',         [AdmisiController::class, 'anjunganTiketUmum'])->middleware('throttle:kiosk');
        Route::post('/checkin-bpjs',       [AdmisiController::class, 'anjunganCheckinBpjs'])->middleware('throttle:kiosk');
        Route::post('/ambil-antrean-bpjs', [AdmisiController::class, 'anjunganAmbilAntreanBpjs'])->middleware('throttle:kiosk');
        Route::post('/terbitkan-sep',      [AdmisiController::class, 'anjunganTerbitkanSep'])->middleware('throttle:kiosk');
    });

    // =========================================================================
    // 2c. PUBLIC — Antrean TV (lobby display, no auth — data sudah ditampilkan
    //     di layar publik, jadi tidak ada privacy regression)
    // =========================================================================
    Route::prefix('antrean-tv')->group(function () {
        Route::get('/snapshot',          [QueueController::class, 'snapshotPublic']);
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
        // Papan ketersediaan tempat tidur (agregat tanpa PII) — transparansi publik
        Route::get('/bed-availability',  [TvBedAvailabilityController::class, 'index']);
        // Registry TV per-perangkat — TV lapor diri & ambil media efektifnya (public)
        Route::post('/device/register',  [TvDeviceController::class, 'register'])->middleware('throttle:tv-register');
        Route::get('/device/{deviceKey}', [TvDeviceController::class, 'show']);
    });

    // =========================================================================
    // 2d. PUBLIC — Antrol (Antrean Online BPJS, Sisi B: Mobile JKN → RS).
    //     BUKAN JWT — auth via header x-token BPJS (middleware verify-antrol-token).
    //     /token PUBLIC tanpa middleware (terbitkan token via x-username+x-password).
    // =========================================================================
    Route::prefix('antrol')->group(function () {
        // Rate-limit penerbitan token (limiter 'antrol-token', per-IP): tahan brute-force
        // kredensial Mobile-JKN. Lih. AppServiceProvider.
        Route::get('/token', [AntrolMobileController::class, 'token'])->middleware('throttle:antrol-token');

        Route::middleware('verify-antrol-token')->group(function () {
            Route::post('/status',                 [AntrolMobileController::class, 'status']);
            Route::post('/ambil',                  [AntrolMobileController::class, 'ambil']);
            Route::post('/sisa',                   [AntrolMobileController::class, 'sisa']);
            Route::post('/batal',                  [AntrolMobileController::class, 'batal']);
            Route::post('/checkin',                [AntrolMobileController::class, 'checkin']);
            Route::post('/pasien-baru',            [AntrolMobileController::class, 'pasienBaru']);
            Route::post('/jadwal-operasi',         [AntrolMobileController::class, 'jadwalOperasi']);
            Route::post('/jadwal-operasi-pasien',  [AntrolMobileController::class, 'jadwalOperasiPasien']);
            Route::post('/farmasi/ambil',          [AntrolMobileController::class, 'farmasiAmbil']);
            Route::post('/farmasi/status',         [AntrolMobileController::class, 'farmasiStatus']);
        });
    });

    // =========================================================================
    // 2e. MESIN — Integrasi alat penunjang (bridge/feeder/watcher DICOM).
    //     BUKAN JWT — auth via service token (middleware service-token, .env
    //     PENUNJANG_BRIDGE_TOKEN). worklist = feed pasien ke alat; ingest = terima PDF.
    // =========================================================================
    Route::prefix('integrasi/penunjang')->middleware('service-token')->group(function () {
        Route::get('/worklist', [PenunjangWorklistController::class, 'index']);
        Route::post('/ingest',  [PenunjangIngestController::class, 'store']);
    });

    // =========================================================================
    // 3. PROTECTED — semua route di bawah wajib auth:api
    // =========================================================================
    Route::middleware('auth:api')->group(function () {

        // -----------------------------------------------------------------
        // Antrean TV — edit display settings (per-stasiun template TTS,
        // flash, badge, toggle kartu). Read-nya public, write wajib login.
        // -----------------------------------------------------------------
        // Read antrean-tv setting PUBLIK (TV tanpa login, lihat blok 2c di atas).
        // Write (konfigurasi oleh admin) WAJIB permission:antrian_tv.write.
        Route::prefix('antrean-tv/display-settings')->middleware('permission:antrian_tv.write')->group(function () {
            Route::put('/{station}',        [TvDisplaySettingController::class, 'update']);
            Route::post('/{station}/reset', [TvDisplaySettingController::class, 'reset']);
        });
        // Antrean TV — audio settings (singleton)
        Route::put('/antrean-tv/audio-settings', [TvAudioSettingController::class, 'update'])->middleware('permission:antrian_tv.write');
        // Antrean TV — branding settings (singleton: logo + nama klinik)
        Route::put('/antrean-tv/branding-settings',        [TvBrandingSettingController::class, 'update'])->middleware('permission:antrian_tv.write');
        Route::post('/antrean-tv/branding-settings/reset', [TvBrandingSettingController::class, 'reset'])->middleware('permission:antrian_tv.write');
        // Antrean TV — media (mode/youtube/video upload/slideshow) — sync ke semua TV
        Route::put('/antrean-tv/media-settings',           [TvMediaSettingController::class, 'update'])->middleware('permission:antrian_tv.write');
        Route::post('/antrean-tv/media-settings/video',    [TvMediaSettingController::class, 'uploadVideo'])->middleware('permission:antrian_tv.write');
        Route::delete('/antrean-tv/media-settings/video',  [TvMediaSettingController::class, 'deleteVideo'])->middleware('permission:antrian_tv.write');
        // Upload gambar slideshow (global atau per-TV) — kembalikan URL saja
        Route::post('/antrean-tv/media-settings/image',    [TvMediaSettingController::class, 'uploadImage'])->middleware('permission:antrian_tv.write');
        // Registry TV per-perangkat — kelola nama & media tiap TV
        Route::get('/antrean-tv/devices',         [TvDeviceController::class, 'index'])->middleware('permission:antrian_tv.write');
        Route::put('/antrean-tv/devices/{id}',    [TvDeviceController::class, 'update'])->middleware('permission:antrian_tv.write');
        Route::delete('/antrean-tv/devices/{id}', [TvDeviceController::class, 'destroy'])->middleware('permission:antrian_tv.write');

        // -----------------------------------------------------------------
        // AUTH
        // -----------------------------------------------------------------
        Route::prefix('auth')->group(function () {
            Route::post('/logout',  [AuthController::class, 'logout']);
            Route::post('/refresh', [AuthController::class, 'refresh']);
            Route::get('/me',       [AuthController::class, 'me']);
            Route::put('/password', [AuthController::class, 'changePassword']);
            // Self-service dokter: ubah PIN tanda tangan + reset kredensial ke default.
            Route::put('/pin',              [AuthController::class, 'changePin']);
            Route::post('/reset-to-default', [AuthController::class, 'resetToDefault']);
        });

        // -----------------------------------------------------------------
        // ANTRIAN (cross-cutting) — Section 11 Service Flow
        // -----------------------------------------------------------------
        Route::prefix('antrian')->group(function () {
            Route::get('/',                          [QueueController::class, 'index']);          // snapshot semua station
            Route::get('/station/{station}',         [QueueController::class, 'byStation']);      // per station
            Route::get('/{id}',                      [QueueController::class, 'show']);
            Route::put('/{id}/panggil',              [QueueController::class, 'panggil'])->middleware('role:superadmin');
            Route::put('/{id}/mulai',                [QueueController::class, 'mulai'])->middleware('role:superadmin');
            Route::put('/{id}/lewati',               [QueueController::class, 'lewati'])->middleware('role:superadmin');
            Route::put('/{id}/selesai',              [QueueController::class, 'selesai'])->middleware('role:superadmin');        // advance ke next station
            Route::put('/{id}/batal',                [QueueController::class, 'batal'])->middleware('role:superadmin');
        });

        // -----------------------------------------------------------------
        // ADMISI
        // -----------------------------------------------------------------
        Route::prefix('admisi')->middleware('permission:admisi.read')->group(function () {
            Route::get('/dashboard',              [AdmisiController::class, 'dashboard']);
            Route::get('/kunjungan',              [AdmisiController::class, 'indexKunjungan']);
            Route::get('/kunjungan/{id}',         [AdmisiController::class, 'showKunjungan']);
            Route::put('/kunjungan/{id}/cancel',  [AdmisiController::class, 'cancelKunjungan']);
            Route::put('/kunjungan/{id}/penjamin', [AdmisiController::class, 'updateGuarantor']);
            Route::put('/kunjungan/{id}/dokter',   [AdmisiController::class, 'gantiDokter']);
            Route::put('/kunjungan/{id}/antrean-jkn', [AdmisiController::class, 'updateAntreanJkn'])->middleware('permission:admisi.write');
            Route::put('/kunjungan/{visitId}/daftarkan-walkin', [AdmisiController::class, 'daftarkanWalkIn']);

            Route::get('/pasien',                 [AdmisiController::class, 'cariPasien']);
            Route::post('/pasien',                [AdmisiController::class, 'storePasien']);
            Route::get('/pasien/{id}',            [AdmisiController::class, 'showPasien']);
            Route::get('/pasien/{id}/kunjungan',  [AdmisiController::class, 'indexKunjunganPasien']);
            Route::get('/pasien/{id}/jadwal-bedah-aktif', [AdmisiController::class, 'jadwalBedahAktif']);
            Route::get('/pasien/{id}/kontrol-gratis', [AdmisiController::class, 'kontrolGratis']);
            Route::put('/pasien/{id}',            [AdmisiController::class, 'updatePasien']);
            // Resolve IHS Satu Sehat satu pasien (cek NIK ke Kemenkes sebelum backfill massal).
            Route::post('/pasien/{id}/resolve-ihs', [AdmisiController::class, 'resolveIhsPasien'])
                ->middleware('permission:admisi.write');

            // Berkas identitas pasien (KTP — foto/PDF), per-pasien, disk privat ber-auth.
            Route::get   ('/pasien/{id}/identity-documents',                [App\Http\Controllers\PatientIdentityDocumentController::class, 'index']);
            Route::post  ('/pasien/{id}/identity-documents',                [App\Http\Controllers\PatientIdentityDocumentController::class, 'store']);
            Route::get   ('/pasien/{id}/identity-documents/{docId}/file',   [App\Http\Controllers\PatientIdentityDocumentController::class, 'showFile']);
            Route::delete('/pasien/{id}/identity-documents/{docId}',        [App\Http\Controllers\PatientIdentityDocumentController::class, 'destroy']);

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
                // Cetak/lihat SEP (read-only) DIPINDAH ke luar grup admisi.read agar
                // bisa di-gate OR (admisi|perawat|dokter|bpjs) — lihat blok di bawah.
                Route::put('/update-sep',          [AdmisiController::class, 'bpjsUpdateSep']);
                Route::post('/cek-rujukan',        [AdmisiController::class, 'bpjsCekRujukan']);
                Route::post('/cek-surat-kontrol',  [AdmisiController::class, 'bpjsCekSuratKontrol']);
                // i-Care: URL viewer riwayat pelayanan peserta (informed consent di UI).
                Route::post('/icare-riwayat',      [AdmisiController::class, 'bpjsIcareRiwayat']);
                // Pre-flight kesiapan SEP sebelum pasien didaftarkan (wizard Konfirmasi).
                Route::post('/preflight-sep',      [AdmisiController::class, 'bpjsPreflightSep']);
                // Diagnosa awal SEP: tarik dari rujukan FKTP / set manual (override).
                Route::post('/tarik-diagnosa/{visitId}', [AdmisiController::class, 'bpjsTarikDiagnosa'])->middleware('permission:admisi.write');
                Route::put('/diagnosa/{visitId}',        [AdmisiController::class, 'bpjsSetDiagnosa'])->middleware('permission:admisi.write');
                // Tarik rujukan & surat kontrol by No. Kartu/NIK (pasien kontrol tanpa nomor).
                Route::post('/rujukan-by-kartu',       [AdmisiController::class, 'bpjsRujukanByKartu']);
                Route::post('/surat-kontrol-by-kartu', [AdmisiController::class, 'bpjsSuratKontrolByKartu']);
                Route::get('/surat-kontrol/{visitId}', [AdmisiController::class, 'bpjsGetSuratKontrol']);
                Route::put('/edit-surat-kontrol',  [AdmisiController::class, 'bpjsEditSuratKontrol']);
                Route::post('/validasi-booking',   [AdmisiController::class, 'bpjsValidasiBooking']);
            });
        });

        // Cetak/lihat SEP (read-only): SENGAJA di luar grup admisi.read. SEP dipakai
        // lintas peran — perawat & dokter (klinis) + verifikator BPJS (Rekap/Klaim,
        // tab History) sama-sama perlu mencetak SEP. Gate OR; auth:api tetap dari grup
        // induk. PermissionMiddleware memecah "a|b|c" jadi OR (lolos bila salah satu).
        Route::middleware('permission:admisi.read|perawat.read|rme_dokter.read|bpjs.read')
            ->prefix('admisi/bpjs')->group(function () {
                Route::get('/cetak-sep/{visitId}',      [AdmisiController::class, 'bpjsCetakSep']);
                Route::get('/cetak-sep-html/{visitId}', [AdmisiController::class, 'bpjsCetakSepHtml']);
            });

        // -----------------------------------------------------------------
        // PERAWAT / TRIASE
        // -----------------------------------------------------------------
        Route::prefix('perawat')->middleware('permission:perawat.read')->group(function () {
            Route::get('/antrian',                          [PerawatController::class, 'indexAntrian']);
            Route::put('/antrian/{id}/panggil',             [PerawatController::class, 'panggilAntrian']);
            Route::put('/antrian/{id}/mulai',               [PerawatController::class, 'mulaiAntrian']);
            Route::put('/antrian/{id}/selesai',             [PerawatController::class, 'selesaiAntrian']);

            Route::get('/asesmen/{visitId}',                [PerawatController::class, 'showAsesmen']);
            Route::post('/asesmen',                         [PerawatController::class, 'storeAsesmen']);
            Route::put('/asesmen/{id}',                     [PerawatController::class, 'updateAsesmen']);
            Route::post('/asesmen/{id}/finalize',           [PerawatController::class, 'finalizeAsesmen']);
            Route::post('/asesmen/{id}/reopen',             [PerawatController::class, 'reopenAsesmen']);

            // CPPT — Catatan Perkembangan Pasien Terintegrasi (timeline append + soft-edit)
            Route::get('/cppt/visit/{visitId}',             [PerawatController::class, 'indexCppt']);
            Route::post('/cppt',                            [PerawatController::class, 'storeCppt']);
            Route::put('/cppt/{id}',                        [PerawatController::class, 'updateCppt']);
            Route::post('/cppt/{id}/sign',                  [PerawatController::class, 'signCppt']);

            Route::put('/antrian/{id}/lewati',                     [PerawatController::class, 'lewatiAntrian']);
            Route::put('/antrian/{id}/dahulukan',                  [PerawatController::class, 'dahulukanAntrian']);
            Route::put('/antrian/{id}/skip',                       [PerawatController::class, 'skipTriase']);
            Route::post('/antrian/{id}/kirim-ke-bedah',            [PerawatController::class, 'kirimKeBedah']);
            Route::post('/antrian/{id}/kirim-ke-dokter',           [PerawatController::class, 'kirimKeDokter']);
            Route::post('/antrian/{id}/kirim-ke-ranap',            [PerawatController::class, 'kirimKeRanap']);
            Route::get('/kunjungan/{visitId}',                     [PerawatController::class, 'showKunjungan']);
            Route::get('/kunjungan/{visitId}/status-parallel',     [PerawatController::class, 'statusParallel']);
            // Instruksi obat pre-op dokter jaga (stat-dose, visit PREOP_BEDAH).
            // Write digate in-service: hanya user ber-employee dokter (DOCTOR_TYPES).
            Route::get('/kunjungan/{visitId}/preop-resep',         [PerawatController::class, 'showPreopResep']);
            Route::post('/kunjungan/{visitId}/preop-resep',        [PerawatController::class, 'storePreopResep']);
            Route::get('/obat',                                    [PerawatController::class, 'daftarObat']);
            Route::get('/pasien/{patientId}/vital-history',        [PerawatController::class, 'vitalHistory']);
            Route::get('/pasien/{patientId}/rekam-medis',          [PerawatController::class, 'rekamMedisPasien']);
            // CPPT lintas-episode terpadu (read-only) — kartu "SOAP/CPPT" di PerawatView.
            Route::get('/pasien/{patientId}/cppt',                 [RekamMedisController::class, 'cpptPasien']);
            Route::get('/dokumen/{documentId}',                    [PerawatController::class, 'showDokumen']);
        });

        // -----------------------------------------------------------------
        // REFRAKSIONIS
        // -----------------------------------------------------------------
        Route::prefix('refraksi')->middleware('permission:refraksionis.read')->group(function () {
            Route::get('/antrian',                         [RefraksiController::class, 'indexAntrian']);
            Route::put('/antrian/{id}/panggil',            [RefraksiController::class, 'panggilAntrian']);
            Route::put('/antrian/{id}/mulai',              [RefraksiController::class, 'mulaiAntrian']);
            Route::put('/antrian/{id}/lewati',             [RefraksiController::class, 'lewatiAntrian']);
            Route::put('/antrian/{id}/skip',               [RefraksiController::class, 'skipRefraksi']);
            Route::put('/antrian/{id}/selesai',            [RefraksiController::class, 'selesaiAntrian']);

            Route::get('/pemeriksaan/{visitId}',           [RefraksiController::class, 'showPemeriksaan']);
            Route::post('/pemeriksaan',                    [RefraksiController::class, 'storePemeriksaan']);
            Route::put('/pemeriksaan/{id}',                [RefraksiController::class, 'updatePemeriksaan']);
            Route::post('/pemeriksaan/{id}/finalize',      [RefraksiController::class, 'finalizePemeriksaan']);
            Route::post('/pemeriksaan/{id}/reopen',        [RefraksiController::class, 'reopenPemeriksaan']);

            Route::get('/resep-kacamata/{refractionId}',  [RefraksiController::class, 'showResepKacamata']);
            Route::post('/resep-kacamata',                 [RefraksiController::class, 'storeResepKacamata']);
            Route::put('/resep-kacamata/{id}',             [RefraksiController::class, 'updateResepKacamata']);

            Route::get('/iol-rekomendasi/{visitId}',       [RefraksiController::class, 'showIolRekomendasi']);
            Route::post('/iol-rekomendasi',                [RefraksiController::class, 'storeIolRekomendasi']);
            Route::put('/iol-rekomendasi/{id}',            [RefraksiController::class, 'updateIolRekomendasi']);

            Route::get('/kunjungan/{visitId}',                     [RefraksiController::class, 'showKunjungan']);
            Route::get('/kunjungan/{visitId}/status-parallel',     [RefraksiController::class, 'statusParallel']);
            Route::get('/pasien/{patientId}/riwayat',              [RefraksiController::class, 'riwayatRefraksi']);
            // CPPT lintas-episode terpadu (read-only) — kartu "SOAP/CPPT" di RefraksionisView.
            // Delegasi ke mesin RME yang sama dipakai DokterView.
            Route::get('/pasien/{patientId}/cppt',                 [RekamMedisController::class, 'cpptPasien']);

            // Opsi dropdown/combobox (Autoref/Keratometri/Visus/Refraksi) — read terbuka
            // utk station refraksi; master-nya dikelola di /master/refraksi-opsi.
            Route::get('/opsi',                                    [RefractionOptionController::class, 'options']);
        });

        // -----------------------------------------------------------------
        // DOKTER
        // -----------------------------------------------------------------
        Route::prefix('dokter')->middleware('permission:rme_dokter.read')->group(function () {
            Route::post('/verify-pin',                          [DokterController::class, 'verifyPin']);
            Route::get('/antrian',                              [DokterController::class, 'indexAntrian']);
            Route::put('/antrian/{id}/panggil',                 [DokterController::class, 'panggilAntrian']);
            Route::put('/antrian/{id}/lewati',                  [DokterController::class, 'lewatiAntrian']);
            Route::put('/antrian/{id}/selesai',                 [DokterController::class, 'selesaiAntrian']);
            Route::put('/antrian/{id}/ke-penunjang',            [DokterController::class, 'kirimKePenunjang']);
            // Batalkan kunjungan dari sisi dokter (Admisi terkunci begitu pasien
            // mencapai stasiun klinis) — hanya dokter pemilik / superadmin.
            Route::put('/kunjungan/{visitId}/cancel',           [DokterController::class, 'cancelKunjungan']);

            // Rujukan internal antar-poli (mis. Poli Mata Umum → Poli Retina)
            Route::get('/kunjungan/{visitId}/rujuk-internal/targets', [DokterController::class, 'rujukInternalTargets']);
            Route::post('/kunjungan/{visitId}/rujuk-internal',        [DokterController::class, 'rujukInternal']);
            // Ganti dokter pemeriksa (tetap 1 visit) — pasien belum dipanggil dokter
            Route::put('/kunjungan/{visitId}/ganti-dokter',           [DokterController::class, 'gantiDokter']);

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
            // Komit billing + majukan antrean (RME tetap bisa dilengkapi belakangan).
            Route::put('/kunjungan/{visitId}/kirim-kasir',      [DokterController::class, 'kirimKeKasir']);
            // Status tagihan ringkas → gating "Buka Kembali" Tab 3 (revisi pra-bayar).
            Route::get('/kunjungan/{visitId}/billing-status',   [DokterController::class, 'billingStatus']);

            Route::post('/kunjungan/{visitId}/finalize',        [DokterController::class, 'finalizeKunjungan']);
            // Buka kembali RME final utk revisi (hanya pra-bayar — paritas Buka Kembali Tab 3).
            Route::post('/kunjungan/{visitId}/buka-finalisasi', [DokterController::class, 'bukaFinalisasi']);

            Route::post('/kunjungan/{visitId}/follow-up',       [DokterController::class, 'storeFollowUp']);
            Route::put('/kunjungan/{visitId}/follow-up',        [DokterController::class, 'updateFollowUp']);
            Route::delete('/kunjungan/{visitId}/follow-up',     [DokterController::class, 'deleteFollowUp']);

            Route::get('/kunjungan/{visitId}/tindakan',         [DokterController::class, 'indexTindakan']);
            Route::post('/kunjungan/{visitId}/tindakan',        [DokterController::class, 'storeTindakan']);
            Route::delete('/tindakan/{id}',                     [DokterController::class, 'deleteTindakan']);

            // BHP yang dipakai dokter (mis. spuit/kasa untuk injeksi/prosedur kecil) —
            // ditagih lewat visit_bhp_usages, stok unit Farmasi dipotong saat input.
            Route::get('/tarif-bhp',                            [DokterController::class, 'tarifBhp']);
            Route::get('/kunjungan/{visitId}/bhp',              [DokterController::class, 'indexBhpUsage']);
            Route::post('/kunjungan/{visitId}/bhp',             [DokterController::class, 'storeBhpUsage']);
            Route::put('/bhp/{id}',                             [DokterController::class, 'updateBhpUsage']);
            Route::delete('/bhp/{id}',                          [DokterController::class, 'deleteBhpUsage']);

            // Paket PEMERIKSAAN (poliklinik): terapkan/lepas — merge tindakan + snapshot diskon.
            Route::post('/kunjungan/{visitId}/apply-package',   [DokterController::class, 'applyExaminationPackage']);
            Route::delete('/kunjungan/{visitId}/package',       [DokterController::class, 'removeExaminationPackage']);

            Route::get('/kunjungan/{visitId}/resep',            [DokterController::class, 'indexResep']);
            Route::post('/kunjungan/{visitId}/resep',           [DokterController::class, 'storeResep']);

            Route::get('/kunjungan/{visitId}/order-penunjang',  [DokterController::class, 'indexOrderPenunjang']);
            Route::post('/order-penunjang',                     [DokterController::class, 'storeOrderPenunjang']);
            Route::delete('/order-penunjang/{id}',              [DokterController::class, 'cancelOrderPenunjang']);

            Route::get('/kunjungan/{visitId}/hasil-penunjang',  [DokterController::class, 'indexHasilPenunjang']);
            Route::get('/kunjungan/{visitId}/iol-rekomendasi',  [DokterController::class, 'showIolRekomendasi']);
            Route::get('/kunjungan/{visitId}/biometri-iol',     [DokterController::class, 'showBiometriIol']);
            Route::post('/kunjungan/{visitId}/keputusan-iol',   [DokterController::class, 'decideIol']);

            Route::get('/kunjungan/{visitId}/resume-medis',     [DokterController::class, 'showResumeMedis']);
            Route::post('/kunjungan/{visitId}/resume-medis',    [DokterController::class, 'generateResumeMedis']);
            Route::put('/resume-medis/{id}',                    [DokterController::class, 'updateResumeMedis']);
            Route::post('/resume-medis/{id}/finalize',          [DokterController::class, 'finalizeResumeMedis']);

            Route::post('/rujukan-keluar',                      [DokterController::class, 'storeRujukanKeluar']);
            // Referensi VClaim (faskes/poli/diagnosa) untuk form rujukan keluar — read-only
            // lookup BPJS. Diekspos di grup DOKTER krn dokter butuh saat rujuk eksternal tapi
            // TIDAK punya permission integrasi.read (endpoint /integrasi/vclaim/referensi terkunci).
            Route::get('/vclaim/referensi/{jenis}',             [IntegrasiController::class, 'vclaimReferensi']);

            // Surat Kontrol BPJS (planning Pulang) — baca status + terbitkan ke VClaim
            Route::get('/kunjungan/{visitId}/surat-kontrol',        [DokterController::class, 'getSuratKontrol']);
            Route::post('/kunjungan/{visitId}/surat-kontrol/submit', [DokterController::class, 'submitSuratKontrol']);

            // i-Care BPJS — URL viewer riwayat pelayanan peserta (informed consent di UI).
            Route::post('/kunjungan/{visitId}/icare-riwayat',       [DokterController::class, 'icareRiwayat']);
            // WS Rekam Medis BPJS — kirim RM kunjungan ke BPJS (mengisi i-Care).
            Route::post('/kunjungan/{visitId}/rm-bpjs',             [DokterController::class, 'kirimRekamMedisBpjs']);

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
            // GET  /jadwal-dokter/aktif-hari-ini  ← dropdown lintas-stasiun (admisi/dokter/
            // antrian butuh): biarkan hanya auth:api agar tak memblokir flow non-admisi.
            Route::get('/aktif-hari-ini',      [JadwalDokterController::class, 'aktifHariIni']);
            // Jadwal Dokter = key sendiri (dipisah dari admisi). Default tetap diberikan
            // ke admisi (preserve), tapi kini bisa dicabut/diberikan terpisah di Data Pengguna.
            // Static routes — WAJIB di atas /{id} agar tidak ditangkap sebagai param.
            Route::get('/minggu-tersedia',     [JadwalDokterController::class, 'availableWeeks'])->middleware('permission:jadwal_dokter.read');
            Route::get('/template-csv',        [JadwalDokterController::class, 'template'])->middleware('permission:jadwal_dokter.write');
            Route::get('/export-csv',          [JadwalDokterController::class, 'export'])->middleware('permission:jadwal_dokter.read');
            Route::post('/import-csv',         [JadwalDokterController::class, 'import'])->middleware('permission:jadwal_dokter.write');
            Route::post('/salin-minggu-depan', [JadwalDokterController::class, 'copyToNextWeek'])->middleware('permission:jadwal_dokter.write');
            Route::get('/',                    [JadwalDokterController::class, 'index'])->middleware('permission:jadwal_dokter.read');
            Route::post('/',                   [JadwalDokterController::class, 'store'])->middleware('permission:jadwal_dokter.write');
            Route::get('/{id}',                [JadwalDokterController::class, 'show'])->middleware('permission:jadwal_dokter.read');
            Route::put('/{id}',                [JadwalDokterController::class, 'update'])->middleware('permission:jadwal_dokter.write');
            Route::delete('/{id}',             [JadwalDokterController::class, 'destroy'])->middleware('permission:jadwal_dokter.write');
            Route::patch('/{id}/toggle',       [JadwalDokterController::class, 'toggle'])->middleware('permission:jadwal_dokter.write');
        });

        // -----------------------------------------------------------------
        // PENUNJANG
        // -----------------------------------------------------------------
        Route::prefix('penunjang')->middleware('permission:penunjang.read')->group(function () {
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

            // Inbox hasil tak-tertaut (ingest alat gagal cocok otomatis → tautkan manual).
            Route::get('/inbox',                          [PenunjangController::class, 'indexInbox']);
            Route::get('/inbox/assignable',               [PenunjangController::class, 'assignableOrders']);
            Route::post('/inbox/{id}/assign',             [PenunjangController::class, 'assignInbox'])->middleware('permission:penunjang.write');
            Route::post('/inbox/{id}/discard',            [PenunjangController::class, 'discardInbox'])->middleware('permission:penunjang.write');
        });

        // -----------------------------------------------------------------
        // BEDAH
        // -----------------------------------------------------------------
        Route::prefix('bedah')->middleware('permission:bedah.read')->group(function () {
            Route::get('/antrian',                          [BedahController::class, 'indexAntrian']);
            Route::get('/history',                          [BedahController::class, 'history']);
            Route::put('/antrian/{id}/panggil',             [BedahController::class, 'panggilAntrian'])->middleware('permission:bedah.write');
            Route::put('/antrian/{id}/selesai',             [BedahController::class, 'selesaiAntrian'])->middleware('permission:bedah.write');

            Route::get('/jadwal',                           [BedahController::class, 'indexJadwal']);
            Route::get('/jadwal/{id}',                      [BedahController::class, 'showJadwal']);
            Route::post('/jadwal',                          [BedahController::class, 'storeJadwal'])->middleware('permission:bedah.write');
            Route::put('/jadwal/{id}',                      [BedahController::class, 'updateJadwal'])->middleware('permission:bedah.write');
            Route::delete('/jadwal/{id}',                   [BedahController::class, 'deleteJadwal'])->middleware('permission:bedah.write');
            Route::put('/jadwal/{id}/mulai',                [BedahController::class, 'mulaiOperasi'])->middleware('permission:bedah.write');
            Route::put('/jadwal/{id}/selesai',              [BedahController::class, 'selesaiOperasi'])->middleware('permission:bedah.write');
            // Batal bedah (sebelum operasi mulai) + disposisi POLI/RANAP/KASIR.
            Route::put('/jadwal/{id}/batal',                [BedahController::class, 'batalBedah'])->middleware('permission:bedah.write');
            Route::get('/poli-targets',                     [BedahController::class, 'poliTargets']);

            // 1-klik request BHP/IOL dari komposisi paket bedah (preview + kirim).
            Route::get('/jadwal/{id}/auto-request/preview', [BedahController::class, 'previewAutoRequest']);
            Route::post('/jadwal/{id}/auto-request',        [BedahController::class, 'sendAutoRequest'])->middleware('permission:bedah.write');

            Route::get('/request',                          [BedahController::class, 'indexRequest']);
            Route::get('/request/{id}',                     [BedahController::class, 'showRequest']);
            Route::post('/request',                         [BedahController::class, 'storeRequest'])->middleware('permission:bedah.write');
            Route::put('/request/{id}',                     [BedahController::class, 'updateRequest'])->middleware('permission:bedah.write');
            Route::put('/request/{id}/kirim',               [BedahController::class, 'kirimRequest'])->middleware('permission:bedah.write');
            Route::put('/request/{id}/terima',              [BedahController::class, 'terimaRequest'])->middleware('permission:bedah.write');
            Route::post('/request/{id}/adjust-bhp',         [BedahController::class, 'adjustBhpUsage'])->middleware('permission:bedah.write');

            // Tarif tindakan per-penjamin visit untuk picker komposisi paket (bedah.read,
            // tanpa kunci DPJP — beda dari /dokter/tarif-tindakan yang 403 bagi non-DPJP).
            Route::get('/tarif-tindakan',                   [BedahController::class, 'tarifTindakan'])->middleware('permission:bedah.read');

            // Komponen paket pasien (snapshot) — edit BHP & Tindakan saat operasi.
            // Multi-paket: satu visit boleh punya >1 paket (mis. Phaco + TIVA).
            Route::get('/visit-package/{visitId}',          [BedahController::class, 'getVisitPackage'])->middleware('permission:bedah.read');
            Route::post('/visit-package/{visitId}/package', [BedahController::class, 'addVisitPackage'])->middleware('permission:bedah.write');
            Route::delete('/visit-package/{snapshotId}',    [BedahController::class, 'removeVisitPackage'])->middleware('permission:bedah.write');
            Route::post('/visit-package/{visitId}/items',   [BedahController::class, 'addVisitPackageItem'])->middleware('permission:bedah.write');
            Route::put('/visit-package-item/{itemId}',      [BedahController::class, 'updateVisitPackageItem'])->middleware('permission:bedah.write');
            Route::delete('/visit-package-item/{itemId}',   [BedahController::class, 'removeVisitPackageItem'])->middleware('permission:bedah.write');

            Route::get('/record/{scheduleId}',              [BedahController::class, 'showRecord']);
            Route::post('/record',                          [BedahController::class, 'storeRecord'])->middleware('permission:bedah.write');
            Route::put('/record/{id}',                      [BedahController::class, 'updateRecord'])->middleware('permission:bedah.write');
            Route::put('/record/{id}/post-op',              [BedahController::class, 'storePostOp'])->middleware('permission:bedah.write');
            Route::post('/record/{id}/finalize',            [BedahController::class, 'finalizeRecord'])->middleware('permission:bedah.write');
            // Resep obat pasca-bedah (F1/F2): SUBMITTED → otomatis masuk antrean Farmasi via QueueService::nextAfterKasir.
            Route::post('/record/{id}/resep-pasca',         [BedahController::class, 'storePostOpPrescription'])->middleware('permission:bedah.write');
            // Muat resep pasca-bedah aktif + status tagihan → hidrasi & gating "Buka Kembali".
            Route::get('/record/{id}/resep-pasca',          [BedahController::class, 'getPostOpPrescription'])->middleware('permission:bedah.read');

            // Perioperatif (PAB/WHO): checklist keselamatan diisi perawat+dokter (bedah.checklist);
            // laporan operasi & skor pemulihan tetap dokter (bedah.write).
            Route::put('/record/{id}/safety-checklist',     [BedahController::class, 'saveSafetyChecklist'])->middleware('permission:bedah.checklist');
            Route::put('/record/{id}/operation-report',     [BedahController::class, 'saveOperationReport'])->middleware('permission:bedah.write');
            Route::put('/record/{id}/recovery-assessment',  [BedahController::class, 'saveRecoveryAssessment'])->middleware('permission:bedah.write');

            // Anestesi (RM 5.2 + monitoring vital durante). GET = anestesi.read,
            // tulis = anestesi.write (dokter anestesi). Dipakai AnesthesiaReportWizard.
            Route::get('/anesthesiologists',                [BedahController::class, 'anesthesiologists'])->middleware('permission:anestesi.read');
            Route::get('/record/{id}/anesthesia',           [BedahController::class, 'getAnesthesiaReport'])->middleware('permission:anestesi.read');
            Route::post('/record/{id}/anesthesia',          [BedahController::class, 'saveAnesthesiaReport'])->middleware('permission:anestesi.write');
            Route::get('/record/{id}/anesthesia-vitals',    [BedahController::class, 'listAnesthesiaVitals'])->middleware('permission:anestesi.read');
            Route::post('/anesthesia-vital',                [BedahController::class, 'recordAnesthesiaVital'])->middleware('permission:anestesi.write');
            Route::put('/anesthesia-vital/{id}',            [BedahController::class, 'updateAnesthesiaVital'])->middleware('permission:anestesi.write');
            Route::delete('/anesthesia-vital/{id}',         [BedahController::class, 'destroyAnesthesiaVital'])->middleware('permission:anestesi.write');

            Route::get('/iol-usage',                        [BedahController::class, 'indexIolUsage'])->middleware('permission:bedah.read');
            Route::post('/iol-usage',                       [BedahController::class, 'storeIolUsage'])->middleware('permission:bedah.write');
            Route::put('/iol-usage/{id}',                   [BedahController::class, 'updateIolUsage'])->middleware('permission:bedah.write');
            Route::delete('/iol-usage/{id}',                [BedahController::class, 'destroyIolUsage'])->middleware('permission:bedah.write');

            // Master lookup read-only utk form resep & pemilihan IOL pasca-bedah (F1/F2).
            Route::get('/obat',                             [BedahController::class, 'daftarObat']);
            Route::get('/iol',                              [BedahController::class, 'indexIol']);

            // Paket Obat Pasca-Bedah (template resep rutin) — read via group, tulis bedah.write.
            Route::get('/paket-obat',                       [BedahController::class, 'indexPaketObat']);
            Route::post('/paket-obat',                      [BedahController::class, 'storePaketObat'])->middleware('permission:bedah.write');
            Route::put('/paket-obat/{id}',                  [BedahController::class, 'updatePaketObat'])->middleware('permission:bedah.write');
            Route::delete('/paket-obat/{id}',               [BedahController::class, 'destroyPaketObat'])->middleware('permission:bedah.write');
        });

        // -----------------------------------------------------------------
        // RUANG TINDAKAN (Laser YAG / Retina-PRP) — stasiun terpisah dari Bedah,
        // mem-filter surgery_schedules.location_type = RUANG_TINDAKAN.
        // -----------------------------------------------------------------
        Route::prefix('ruang-tindakan')->middleware('permission:ruang_tindakan.read')->group(function () {
            Route::get('/antrian',              [RuangTindakanController::class, 'indexAntrian']);
            Route::put('/antrian/{id}/panggil', [RuangTindakanController::class, 'panggilAntrian'])->middleware('permission:ruang_tindakan.write');
            Route::put('/antrian/{id}/lewati',  [RuangTindakanController::class, 'lewatiAntrian'])->middleware('permission:ruang_tindakan.write');
            Route::put('/jadwal/{id}/mulai',    [RuangTindakanController::class, 'mulaiTindakan'])->middleware('permission:ruang_tindakan.write');
            Route::put('/jadwal/{id}/selesai',  [RuangTindakanController::class, 'selesaiTindakan'])->middleware('permission:ruang_tindakan.write');
            Route::post('/jadwal/{id}/resep',   [RuangTindakanController::class, 'storeResep'])->middleware('permission:ruang_tindakan.write');
            Route::get('/record/{scheduleId}',  [RuangTindakanController::class, 'showRecord']);
            Route::put('/record/{id}/laporan',  [RuangTindakanController::class, 'saveLaporan'])->middleware('permission:ruang_tindakan.write');
            Route::get('/procedures',           [RuangTindakanController::class, 'procedures']);
            Route::get('/jadwal',               [RuangTindakanController::class, 'jadwal']);
            Route::get('/daftar-obat',          [RuangTindakanController::class, 'daftarObat']);
        });

        // -----------------------------------------------------------------
        // RAWAT INAP (RANAP)
        // -----------------------------------------------------------------
        Route::prefix('rawat-inap')->group(function () {
            Route::get('/bed-board',                  [RanapController::class, 'bedBoard'])->middleware('permission:rawat_inap.read');
            Route::get('/menunggu-kamar',             [RanapController::class, 'waitingForBed'])->middleware('permission:rawat_inap.read');
            Route::get('/aktif',                      [RanapController::class, 'activeInpatients'])->middleware('permission:rawat_inap.read');
            // Literal 'history' WAJIB sebelum '/{visitId}' agar tak ditangkap sbg visitId.
            Route::get('/history',                    [RanapController::class, 'dischargedHistory'])->middleware('permission:rawat_inap.read');
            // Literal 'bed/...' WAJIB sebelum '/{visitId}' agar tak tertangkap sbg visitId.
            Route::post('/bed/{bedId}/available',     [RanapController::class, 'markBedAvailable'])->middleware('permission:rawat_inap.write');
            Route::get('/{visitId}',                  [RanapController::class, 'detail'])->middleware('permission:rawat_inap.read');

            Route::get('/{visitId}/tarif-tindakan',   [RanapController::class, 'tarifTindakan'])->middleware('permission:rawat_inap.read');
            Route::get('/{visitId}/daftar-obat',      [RanapController::class, 'daftarObat'])->middleware('permission:rawat_inap.read');
            Route::get('/{visitId}/cppt',             [RanapController::class, 'indexCppt'])->middleware('permission:rawat_inap.read');
            Route::post('/{visitId}/cppt',            [RanapController::class, 'addCppt'])->middleware('permission:rawat_inap.write');
            Route::put('/cppt/{id}',                  [RanapController::class, 'updateCppt'])->middleware('permission:rawat_inap.write');
            Route::post('/cppt/{id}/verify',          [RanapController::class, 'verifyCppt'])->middleware('permission:rawat_inap.write');

            // BPJS SEP (view/update) + SPRI (CRU). spri/{id} aman (mirip cppt/{id}).
            Route::get('/{visitId}/sep',              [RanapController::class, 'getSep'])->middleware('permission:rawat_inap.read');
            Route::put('/{visitId}/sep',              [RanapController::class, 'updateSep'])->middleware('permission:rawat_inap.write');
            Route::put('/{visitId}/sep/tgl-pulang',   [RanapController::class, 'updateTglPulang'])->middleware('permission:rawat_inap.write');
            Route::get('/{visitId}/spri',             [RanapController::class, 'listSpri'])->middleware('permission:rawat_inap.read');
            Route::post('/{visitId}/spri',            [RanapController::class, 'createSpri'])->middleware('permission:rawat_inap.write');
            Route::put('/spri/{spriId}',              [RanapController::class, 'updateSpri'])->middleware('permission:rawat_inap.write');
            Route::delete('/spri/{spriId}',           [RanapController::class, 'deleteSpri'])->middleware('permission:rawat_inap.write');

            Route::post('/{visitId}/admit',           [RanapController::class, 'admit'])->middleware('permission:rawat_inap.write');
            Route::post('/{visitId}/transfer',        [RanapController::class, 'transfer'])->middleware('permission:rawat_inap.write');
            Route::post('/{visitId}/charge',          [RanapController::class, 'addCharge'])->middleware('permission:rawat_inap.write');
            Route::post('/{visitId}/tindakan',        [RanapController::class, 'addTindakan'])->middleware('permission:rawat_inap.write');
            Route::post('/{visitId}/obat',            [RanapController::class, 'addObat'])->middleware('permission:rawat_inap.write');
            Route::delete('/{visitId}/charge/{chargeId}', [RanapController::class, 'deleteCharge'])->middleware('permission:rawat_inap.write');

            // Permintaan obat ke Farmasi (dispensing rawat inap ke ruangan).
            Route::get('/{visitId}/permintaan-obat',  [RanapController::class, 'listPermintaanObat'])->middleware('permission:rawat_inap.read');
            Route::post('/{visitId}/permintaan-obat', [RanapController::class, 'createPermintaanObat'])->middleware('permission:rawat_inap.write');
            Route::delete('/{visitId}/permintaan-obat/{id}', [RanapController::class, 'cancelPermintaanObat'])->middleware('permission:rawat_inap.write');

            // Permintaan BHP ke Farmasi (visit_bhp_usages) — masuk kwitansi setelah verif Farmasi.
            Route::get('/{visitId}/tarif-bhp',        [RanapController::class, 'tarifBhp'])->middleware('permission:rawat_inap.read');
            Route::get('/{visitId}/bhp',              [RanapController::class, 'listBhp'])->middleware('permission:rawat_inap.read');
            Route::post('/{visitId}/bhp',             [RanapController::class, 'addBhp'])->middleware('permission:rawat_inap.write');
            Route::delete('/{visitId}/bhp/{id}',      [RanapController::class, 'deleteBhp'])->middleware('permission:rawat_inap.write');

            // Order penunjang (lab/radiologi/diagnostik) — antrean PENUNJANG + hasil.
            Route::get('/{visitId}/order-penunjang',         [RanapController::class, 'listOrderPenunjang'])->middleware('permission:rawat_inap.read');
            Route::post('/{visitId}/order-penunjang',        [RanapController::class, 'storeOrderPenunjang'])->middleware('permission:rawat_inap.write');
            Route::delete('/{visitId}/order-penunjang/{id}', [RanapController::class, 'cancelOrderPenunjang'])->middleware('permission:rawat_inap.write');

            // eMAR — pemberian obat ke pasien (PKPO 4.3).
            Route::get('/{visitId}/mar',         [RanapController::class, 'marBoard'])->middleware('permission:rawat_inap.read');
            Route::post('/{visitId}/mar',        [RanapController::class, 'recordAdministration'])->middleware('permission:rawat_inap.write');
            Route::delete('/{visitId}/mar/{id}', [RanapController::class, 'deleteAdministration'])->middleware('permission:rawat_inap.write');

            // Balance cairan (intake/output) — STARKES PAP.
            Route::get('/{visitId}/fluid-balance',         [RanapController::class, 'fluidBalance'])->middleware('permission:rawat_inap.read');
            Route::post('/{visitId}/fluid-balance',        [RanapController::class, 'addFluidBalance'])->middleware('permission:rawat_inap.write');
            Route::delete('/{visitId}/fluid-balance/{id}', [RanapController::class, 'deleteFluidBalance'])->middleware('permission:rawat_inap.write');

            Route::post('/{visitId}/kirim-bedah',     [RanapController::class, 'sendToBedah'])->middleware('permission:rawat_inap.write');
            Route::post('/{visitId}/discharge',       [RanapController::class, 'discharge'])->middleware('permission:rawat_inap.write');

            // Fase 8C — dokumen/hasil eksternal (lab/radiologi pihak ke-3 pre-op).
            Route::get('/{visitId}/dokumen',              [RanapController::class, 'indexDocuments'])->middleware('permission:rawat_inap.read');
            Route::post('/{visitId}/dokumen',             [RanapController::class, 'uploadDocument'])->middleware('permission:rawat_inap.write');
            Route::delete('/{visitId}/dokumen/{documentId}', [RanapController::class, 'deleteDocument'])->middleware('permission:rawat_inap.write');
        });

        // -----------------------------------------------------------------
        // IGD — Instalasi Gawat Darurat (1 stasiun gabung, papan triase berlevel)
        // Literal path ('board') WAJIB sebelum '/{visitId}'; 'cppt/{id}' aman.
        // -----------------------------------------------------------------
        Route::prefix('igd')->group(function () {
            Route::get('/board',                          [IgdController::class, 'board'])->middleware('permission:igd.read');
            Route::post('/register',                      [IgdController::class, 'register'])->middleware('permission:igd.write');
            Route::post('/register-baru',                 [IgdController::class, 'registerNew'])->middleware('permission:igd.write');
            // Dokter jaga IGD (dokter umum) untuk picker pendaftaran. WAJIB sebelum /{visitId}.
            Route::get('/dokter-jaga',                     [IgdController::class, 'dokterJaga'])->middleware('permission:igd.read');
            Route::get('/konsultasi-dokter',               [IgdController::class, 'konsultasiDokter'])->middleware('permission:igd.read');
            Route::get('/{visitId}',                      [IgdController::class, 'detail'])->middleware('permission:igd.read');

            Route::post('/{visitId}/triase',              [IgdController::class, 'triase'])->middleware('permission:igd.write');
            Route::get('/{visitId}/tarif-tindakan',       [IgdController::class, 'tarifTindakan'])->middleware('permission:igd.read');
            Route::get('/{visitId}/daftar-obat',          [IgdController::class, 'daftarObat'])->middleware('permission:igd.read');
            Route::post('/{visitId}/tindakan',            [IgdController::class, 'addTindakan'])->middleware('permission:igd.write');
            Route::post('/{visitId}/konsultasi',          [IgdController::class, 'addKonsultasi'])->middleware('permission:igd.write');
            Route::patch('/{visitId}/konsultasi/{chargeId}', [IgdController::class, 'updateKonsultasiDokter'])->middleware('permission:igd.write');
            Route::post('/{visitId}/obat',                [IgdController::class, 'addObat'])->middleware('permission:igd.write');
            Route::delete('/{visitId}/charge/{chargeId}', [IgdController::class, 'deleteCharge'])->middleware('permission:igd.write');
            // Opsi modal disposisi: BEDAH (paket/operator/anestesi) & RAJAL (poli tujuan).
            Route::get('/{visitId}/bedah-options',        [IgdController::class, 'bedahOptions'])->middleware('permission:igd.read');
            Route::get('/{visitId}/rajal-targets',        [IgdController::class, 'rajalTargets'])->middleware('permission:igd.read');
            Route::post('/{visitId}/disposisi',           [IgdController::class, 'disposisi'])->middleware('permission:igd.write');

            // CPPT IGD (delegasi mesin RANAP); cppt/{id} literal sebelum tak perlu (id UUID).
            Route::get('/{visitId}/cppt',                 [IgdController::class, 'indexCppt'])->middleware('permission:igd.read');
            Route::post('/{visitId}/cppt',                [IgdController::class, 'addCppt'])->middleware('permission:igd.write');
            Route::put('/cppt/{id}',                      [IgdController::class, 'updateCppt'])->middleware('permission:igd.write');
            Route::post('/cppt/{id}/verify',              [IgdController::class, 'verifyCppt'])->middleware('permission:igd.write');

            // SEP IGD (BPJS gawat darurat).
            Route::get('/{visitId}/sep',                  [IgdController::class, 'sepInfo'])->middleware('permission:igd.read');
            Route::post('/{visitId}/sep',                 [IgdController::class, 'generateSep'])->middleware('permission:igd.write');

            // RM 3.7 — Asesmen/Pengkajian Gawat Darurat (terstruktur + dokumen ber-TTD).
            Route::get('/{visitId}/assessment',           [IgdController::class, 'getAssessment'])->middleware('permission:igd.read');
            Route::put('/{visitId}/assessment',           [IgdController::class, 'saveAssessment'])->middleware('permission:igd.write');
            Route::post('/{visitId}/assessment/finalize', [IgdController::class, 'finalizeAssessment'])->middleware('permission:igd.write');

            // Self-checkout IGD (hari libur/kasir tidak bertugas) — butuh hak kasir.
            Route::get('/{visitId}/billing-preview',      [IgdController::class, 'billingPreview'])->middleware('permission:kasir.read');
            Route::post('/{visitId}/self-checkout',       [IgdController::class, 'selfCheckout'])->middleware('permission:kasir.write');
        });

        // -----------------------------------------------------------------
        // FARMASI
        // -----------------------------------------------------------------
        Route::prefix('farmasi')->middleware('permission:farmasi.read')->group(function () {
            Route::get('/antrian',                           [FarmasiController::class, 'indexAntrian']);
            Route::put('/antrian/{id}/panggil',              [FarmasiController::class, 'panggilAntrian'])->middleware('permission:farmasi.write');
            Route::put('/antrian/{id}/lewati',               [FarmasiController::class, 'lewatiAntrian'])->middleware('permission:farmasi.write');
            Route::put('/antrian/{id}/selesai',              [FarmasiController::class, 'selesaiAntrian'])->middleware('permission:farmasi.write');

            // Preview harga obat tambahan sesuai penjamin (harga yang ditagih kasir).
            Route::get('/harga-obat',                        [FarmasiController::class, 'previewHargaObat']);

            // Verifikasi Farmasi (gate sebelum tagihan Kasir, alur D→K→F).
            Route::get('/verifikasi',                        [FarmasiController::class, 'indexVerifikasi']);
            Route::put('/resep/{id}/verifikasi',             [FarmasiController::class, 'verifikasiResep'])->middleware('permission:farmasi.write');
            Route::put('/resep/{id}/buka-verifikasi',        [FarmasiController::class, 'bukaVerifikasiResep'])->middleware('permission:farmasi.write');
            // Verifikasi BHP dokter (per kunjungan) + koreksi qty/hapus saat verifikasi.
            Route::put('/visit/{visitId}/bhp/verifikasi',      [FarmasiController::class, 'verifikasiBhp'])->middleware('permission:farmasi.write');
            Route::put('/visit/{visitId}/bhp/buka-verifikasi', [FarmasiController::class, 'bukaVerifikasiBhp'])->middleware('permission:farmasi.write');
            Route::put('/bhp-usage/{id}',                       [FarmasiController::class, 'updateBhpUsage'])->middleware('permission:farmasi.write');
            Route::delete('/bhp-usage/{id}',                   [FarmasiController::class, 'deleteBhpUsage'])->middleware('permission:farmasi.write');

            Route::get('/resep',                             [FarmasiController::class, 'indexResep']);
            Route::get('/resep/{id}',                        [FarmasiController::class, 'showResep']);
            Route::put('/resep/{id}/dispensing',             [FarmasiController::class, 'startDispensing'])->middleware('permission:farmasi.write');
            Route::put('/resep/{id}/selesai',                [FarmasiController::class, 'selesaiDispensing'])->middleware('permission:farmasi.write');
            Route::put('/resep/{id}/cancel',                 [FarmasiController::class, 'cancelResep'])->middleware('permission:farmasi.write');

            // Dispensing rawat inap — permintaan obat pasien dirawat (type RANAP).
            Route::get('/ranap/permintaan',                  [FarmasiController::class, 'indexRanapRequest']);
            Route::put('/ranap/permintaan/{id}/siapkan',     [FarmasiController::class, 'siapkanRanapRequest'])->middleware('permission:farmasi.write');
            Route::put('/ranap/permintaan/{id}/serah',       [FarmasiController::class, 'serahRanapRequest'])->middleware('permission:farmasi.write');
            Route::put('/ranap/permintaan/{id}/tolak',       [FarmasiController::class, 'tolakRanapRequest'])->middleware('permission:farmasi.write');

            // Riwayat pemberian satu obat (laporan "diberikan ke siapa").
            Route::get('/obat/{medicationId}/riwayat-pemberian', [FarmasiController::class, 'riwayatPemberianObat']);
            // Riwayat GLOBAL obat yang diberikan ke pasien (resep + POS) — tab Riwayat Pemberian.
            Route::get('/riwayat-pemberian/export',              [FarmasiController::class, 'exportRiwayatPemberian']);
            Route::get('/riwayat-pemberian',                     [FarmasiController::class, 'indexRiwayatPemberian']);

            Route::post('/resep/{resepId}/item',             [FarmasiController::class, 'storeItemDispensing'])->middleware('permission:farmasi.write');
            Route::put('/resep-item/{id}/kemasan',           [FarmasiController::class, 'setKemasanItem'])->middleware('permission:farmasi.write');
            Route::put('/resep-item/{id}',                   [FarmasiController::class, 'updateItemDispensing'])->middleware('permission:farmasi.write');
            Route::delete('/resep-item/{id}',                [FarmasiController::class, 'deleteItemDispensing'])->middleware('permission:farmasi.write');
            Route::post('/kunjungan/{visitId}/resep-otc',    [FarmasiController::class, 'storeOtcPrescription'])->middleware('permission:farmasi.write');

            // Penjualan obat bebas lepas (POS apotek) — tanpa Visit/RME.
            Route::get('/penjualan',                         [PharmacySaleController::class, 'index']);
            Route::post('/penjualan',                        [PharmacySaleController::class, 'checkout'])->middleware('permission:farmasi.write');
            // Handoff Farmasi → Kasir (obat bebas dibayar di kasir). Definisikan SEBELUM
            // /penjualan/{id} agar 'tagih-kasir' tak tertangkap sbg {id}.
            Route::post('/penjualan/tagih-kasir',            [PharmacySaleController::class, 'toKasir'])->middleware('permission:farmasi.write');
            Route::get('/penjualan/{id}',                    [PharmacySaleController::class, 'show']);
            Route::post('/penjualan/{id}/batal',             [PharmacySaleController::class, 'cancel'])->middleware('permission:farmasi.write');

            Route::get('/surgery-request',                   [FarmasiController::class, 'indexSurgeryRequest']);
            Route::get('/surgery-request/{id}',              [FarmasiController::class, 'showSurgeryRequest']);
            Route::put('/surgery-request/{id}/siapkan',      [FarmasiController::class, 'siapkanSurgeryRequest'])->middleware('permission:farmasi.write');
            Route::post('/surgery-request/{id}/kirim',       [FarmasiController::class, 'kirimSurgeryRequest'])->middleware('permission:farmasi.write');
            Route::post('/surgery-request/{id}/assign-iol',  [FarmasiController::class, 'assignIol'])->middleware('permission:farmasi.write');

            Route::get('/stok/obat',                         [FarmasiController::class, 'indexStokObat']);
            Route::get('/stok/opname/export',                [FarmasiController::class, 'exportOpname']);
            Route::get('/stok/obat/{id}',                    [FarmasiController::class, 'showStokObat']);
            Route::put('/stok/obat/{id}',                    [FarmasiController::class, 'updateStokObat'])->middleware('permission:farmasi.write');

            Route::get('/stok/bhp',                          [FarmasiController::class, 'indexStokBhp']);
            Route::put('/stok/bhp/{id}',                     [FarmasiController::class, 'updateStokBhp'])->middleware('permission:farmasi.write');

            Route::get('/stok/iol',                          [FarmasiController::class, 'indexStokIol']);
            Route::put('/stok/iol/{id}',                     [FarmasiController::class, 'updateStokIol'])->middleware('permission:farmasi.write');
            Route::get('/stok/alert',                        [FarmasiController::class, 'stokAlert']);
        });

        // -----------------------------------------------------------------
        // KASIR
        // -----------------------------------------------------------------
        Route::prefix('kasir')->middleware('permission:kasir.read')->group(function () {
            Route::get('/antrian',                         [KasirController::class, 'indexAntrian']);
            Route::put('/antrian/{id}/panggil',            [KasirController::class, 'panggilAntrian'])->middleware('permission:kasir.write');
            Route::put('/antrian/{id}/lewati',             [KasirController::class, 'lewatiAntrian'])->middleware('permission:kasir.write');
            Route::put('/antrian/{id}/selesai',            [KasirController::class, 'selesaiAntrian'])->middleware('permission:kasir.write');

            Route::get('/invoice',                         [KasirController::class, 'indexInvoice']);
            Route::get('/invoice/{visitId}',               [KasirController::class, 'showInvoice']);
            Route::get('/insurance-warning/{visitId}',     [KasirController::class, 'insuranceWarning']);
            Route::get('/invoice/{id}/coverages',          [KasirController::class, 'invoiceCoverages']);
            Route::post('/invoice/{visitId}/generate',     [KasirController::class, 'generateInvoice'])->middleware('permission:kasir.write');
            Route::put('/invoice/{id}',                    [KasirController::class, 'updateInvoice'])->middleware('permission:kasir.write');
            Route::post('/invoice/{id}/finalize',          [KasirController::class, 'finalizeInvoice'])->middleware('permission:kasir.write');
            Route::post('/invoice/{id}/resync-tarif',      [KasirController::class, 'resyncTarif'])->middleware('permission:kasir.write');
            Route::post('/invoice/{id}/bayar',             [KasirController::class, 'bayarInvoice'])->middleware('permission:kasir.write');
            Route::post('/invoice/{id}/confirm-coverage',  [KasirController::class, 'confirmCoverage'])->middleware('permission:kasir.write');
            // Kasir input manual jumlah ditanggung penjamin (fallback bila pasien tak
            // terjangkau antrean Verifikasi Asuransi — mis. tagihan H+N / visit_date ≠ hari ini).
            Route::post('/invoice/{id}/set-cover',         [KasirController::class, 'setCover'])->middleware('permission:kasir.write');
            Route::post('/invoice/{id}/confirm-bpjs',      [KasirController::class, 'confirmBpjs'])->middleware('permission:kasir.write');
            Route::post('/invoice/{id}/settle-zero',       [KasirController::class, 'settleZero'])->middleware('permission:kasir.write');
            Route::post('/invoice/{id}/cancel',            [KasirController::class, 'cancelInvoice'])->middleware('permission:kasir.write');
            Route::get('/invoice/{id}/cetak',              [KasirController::class, 'cetakInvoice']);
            // Kirim kwitansi PDF ke email pasien (alternatif cetak fisik) — di-queue.
            Route::post('/invoice/{id}/email',             [KasirController::class, 'emailReceipt'])->middleware('permission:kasir.write');

            // Penjualan OBAT BEBAS dibayar di Kasir (handoff dari Farmasi). PharmacySale
            // berdiri sendiri (tanpa Visit) → controller khusus, permission kasir.
            Route::get('/obat-bebas',                      [PharmacySaleController::class, 'pendingIndex']);
            Route::post('/obat-bebas/{id}/bayar',          [PharmacySaleController::class, 'settle'])->middleware('permission:kasir.write');
            Route::get('/obat-bebas/{id}/kwitansi',        [PharmacySaleController::class, 'receipt']);

            // Tab Rawat Inap Kasir (Fase 2) — pasien masih dirawat + running bill + deposit.
            Route::get('/rawat-inap',                      [KasirController::class, 'inpatientList']);
            Route::get('/rawat-inap/{visitId}',            [KasirController::class, 'inpatientDetail']);

            // Uang muka / deposit rawat inap (Fase 1) — diterima Kasir sebelum discharge,
            // dikreditkan ke invoice saat discharge (Fase 4).
            Route::get('/visit/{visitId}/deposit',         [KasirController::class, 'listDeposits']);
            Route::post('/visit/{visitId}/deposit',        [KasirController::class, 'recordDeposit'])->middleware('permission:kasir.write');

            Route::post('/invoice/{invoiceId}/item',       [KasirController::class, 'storeItemInvoice'])->middleware('permission:kasir.write');
            Route::put('/invoice-item/{id}',               [KasirController::class, 'updateItemInvoice'])->middleware('permission:kasir.write');
            Route::delete('/invoice-item/{id}',            [KasirController::class, 'deleteItemInvoice'])->middleware('permission:kasir.write');
            // Toggle "terserap ke paket" baris obat/BHP tambahan (DISKON_PAKET ikut membesar).
            Route::post('/invoice/{visitId}/absorb-item',  [KasirController::class, 'absorbItem'])->middleware('permission:kasir.write');

            // Tarif tindakan per-penjamin (Edit Tagihan — pilih dari master, bukan ketik manual).
            Route::get('/tarif-tindakan',                  [KasirController::class, 'tarifTindakan']);
            // Pencarian buku tarif lintas kategori (tindakan/obat/BHP/IOL/alkes) — Edit Tagihan.
            Route::get('/tarif-buku',                      [KasirController::class, 'tarifBuku']);

            Route::get('/cob/{visitId}',                   [KasirController::class, 'showCob']);
            Route::put('/cob/{visitId}',                   [KasirController::class, 'updateCob'])->middleware('permission:kasir.write');

            Route::put('/watermark',                       [KasirController::class, 'updateWatermark'])->middleware('permission:kasir.write');
            Route::get('/print-settings',                  [KasirController::class, 'getPrintSettings']);
            Route::put('/print-settings',                  [KasirController::class, 'updatePrintSettings'])->middleware('permission:kasir.write');

            Route::get('/laporan',                         [KasirController::class, 'laporanHarian']);
            Route::get('/laporan/rekap',                   [KasirController::class, 'laporanRekap']);
        });

        // -----------------------------------------------------------------
        // KLAIM BPJS
        // -----------------------------------------------------------------
        Route::prefix('klaim')->middleware('permission:bpjs.read')->group(function () {
            Route::get('/',                               [KlaimController::class, 'index']);

            // Route statis (segmen pertama bukan parameter) WAJIB sebelum `/{id}`,
            // kalau tidak `/{id}` menangkap 'vclaim-log' dsb. → diparse sebagai UUID → 500.
            Route::get('/vclaim-log',                     [KlaimController::class, 'vclaimpLog']);
            Route::get('/icare/monitoring',               [KlaimController::class, 'icareMonitoring']);
            Route::get('/grouping-log/{klaimId}',         [KlaimController::class, 'groupingLog']);
            Route::get('/icd-search',                     [KlaimController::class, 'icdSearch']);
            Route::get('/kedaluwarsa',                    [KlaimController::class, 'kedaluwarsa']); // K3 — pengingat batas 6 bln

            // Berkas klaim (Vedika): render dokumen RM / kwitansi → PDF (statis, sblm /{id}).
            Route::get('/dokumen/{docId}/pdf',            [KlaimController::class, 'dokumenPdf']);
            Route::get('/kwitansi/{visitId}/pdf',         [KlaimController::class, 'kwitansiPdf']);

            // Rekap Kunjungan BPJS (screening pra-klaim) — semua kunjungan BPJS per tgl.
            // Statis → WAJIB sebelum `/{id}` agar 'rekap' tak diparse jadi UUID.
            Route::get('/rekap',                          [KlaimController::class, 'rekap']);
            Route::get('/rekap/export',                   [KlaimController::class, 'rekapExport']);
            // Tab History: bundel semua kwitansi / resume+laporan operasi (PDF) → ZIP.
            // Manifest (daftar visit_id, tanpa render) → FE pecah unduhan per-potongan.
            Route::get('/rekap/bundle-manifest',          [KlaimController::class, 'rekapBundleManifest']);
            Route::get('/rekap/zip-kwitansi',             [KlaimController::class, 'rekapZipKwitansi']);
            Route::get('/rekap/zip-resume',               [KlaimController::class, 'rekapZipResume']);
            // Sinkron SEP terbit di portal VClaim → tautkan ke kunjungan (statis, sblm /{id}).
            Route::post('/rekap/sinkron-sep',             [KlaimController::class, 'rekapSinkronSep'])->middleware('permission:bpjs.write');
            // Kirim kunjungan → daftar klaim (KlaimView). Massal (statis) sblm per-visit.
            Route::post('/rekap/kirim-klaim-massal',      [KlaimController::class, 'rekapKirimKlaimMassal'])->middleware('permission:bpjs.write');
            Route::post('/rekap/{visitId}/kirim-klaim',   [KlaimController::class, 'rekapKirimKlaim'])->middleware('permission:bpjs.write');
            Route::post('/rekap/{visitId}/kembalikan',    [KlaimController::class, 'rekapKembalikan'])->middleware('permission:bpjs.write');
            // Berkas pendukung LIVE (dokumen RM + hasil penunjang + lampiran manual).
            Route::get('/rekap/{visitId}/berkas',         [KlaimController::class, 'rekapBerkas']);
            Route::get('/rekap/{visitId}/lampiran',       [KlaimController::class, 'rekapAttachments']);
            Route::post('/rekap/{visitId}/lampiran',      [KlaimController::class, 'rekapUploadAttachment'])->middleware('permission:bpjs.write');
            Route::delete('/rekap/{visitId}/lampiran/{attId}', [KlaimController::class, 'rekapDeleteAttachment'])->middleware('permission:bpjs.write');
            Route::post('/rekap/{visitId}/kelengkapan',   [KlaimController::class, 'rekapSetKelengkapan'])->middleware('permission:bpjs.write');
            Route::post('/rekap/{visitId}/minta-koreksi', [KlaimController::class, 'rekapRequestCorrection'])->middleware('permission:bpjs.write');

            Route::get('/{id}',                           [KlaimController::class, 'show']);

            // Operasi tulis klaim kini butuh bpjs.write (dulu hanya bpjs.read grup →
            // role read-only bisa menulis). Role bpjs R+W (kasir/admisi/verifikator) tetap jalan.
            Route::put('/{id}/diagnosis',                 [KlaimController::class, 'updateDiagnosis'])->middleware('permission:bpjs.write');
            Route::put('/{id}/assign',                    [KlaimController::class, 'assign'])->middleware('permission:bpjs.write');

            Route::post('/{id}/grouping',                 [KlaimController::class, 'runGrouping'])->middleware('permission:bpjs.write');

            Route::put('/{id}/review',                    [KlaimController::class, 'setReview'])->middleware('permission:bpjs.write');
            Route::put('/{id}/verifikasi',                [KlaimController::class, 'setVerifikasi'])->middleware('permission:bpjs.write');
            Route::put('/{id}/reject',                    [KlaimController::class, 'setReject'])->middleware('permission:bpjs.write');
            Route::post('/{id}/resubmit',                 [KlaimController::class, 'resubmitKlaim'])->middleware('permission:bpjs.write');

            Route::post('/{id}/submit',                   [KlaimController::class, 'submitKlaim'])->middleware('permission:bpjs.write');

            Route::post('/{id}/lupis',                    [KlaimController::class, 'generateLupis'])->middleware('permission:bpjs.write');

            // E-Klaim INA-CBG (Web Service ws.php): new -> set-data -> grouper -> final.
            Route::post('/{id}/eklaim/new',               [KlaimController::class, 'eklaimNewClaim'])->middleware('permission:bpjs.write');
            Route::post('/{id}/eklaim/set-data',          [KlaimController::class, 'eklaimSetData'])->middleware('permission:bpjs.write');
            Route::post('/{id}/eklaim/grouper',           [KlaimController::class, 'eklaimGrouper'])->middleware('permission:bpjs.write');
            Route::post('/{id}/eklaim/final',             [KlaimController::class, 'eklaimFinal'])->middleware('permission:bpjs.write');
            Route::get('/{id}/eklaim/status',             [KlaimController::class, 'eklaimStatus']);
            Route::post('/{id}/eklaim/reedit',            [KlaimController::class, 'eklaimReedit'])->middleware('permission:bpjs.write');
            // Kirim Klaim Online (DC Kemenkes/BPJS) + sinkron status DC + cetak berkas.
            Route::post('/{id}/eklaim/kirim-online',      [KlaimController::class, 'eklaimKirimOnline'])->middleware('permission:bpjs.write');
            // K2 — kirim individual (per SEP) + kolektif (rentang tgl) + upload berkas digital ke DC.
            Route::post('/{id}/eklaim/kirim-individual',  [KlaimController::class, 'eklaimKirimIndividual'])->middleware('permission:bpjs.write');
            Route::post('/eklaim/kirim-kolektif',         [KlaimController::class, 'eklaimKirimKolektif'])->middleware('permission:bpjs.write');
            Route::post('/lampiran/{attId}/upload-dc',    [KlaimController::class, 'uploadLampiranDc'])->middleware('permission:bpjs.write');
            // K3 — status verifikasi + dispute/pending + rekonsiliasi pembayaran.
            Route::post('/{id}/verif-status',             [KlaimController::class, 'refreshVerifStatus'])->middleware('permission:bpjs.write');
            Route::put('/{id}/dispute',                   [KlaimController::class, 'setDispute'])->middleware('permission:bpjs.write');
            Route::put('/{id}/pembayaran',                [KlaimController::class, 'setPayment'])->middleware('permission:bpjs.write');
            Route::get('/{id}/eklaim/sync-dc',            [KlaimController::class, 'eklaimSyncDc']);
            Route::get('/{id}/cetak',                     [KlaimController::class, 'cetakKlaim']);

            Route::get('/{id}/audit-log',                 [KlaimController::class, 'auditLog']);

            // Lampiran berkas klaim (upload PDF/gambar: resume RJ, hasil penunjang).
            Route::get('/{id}/lampiran',                  [KlaimController::class, 'attachments']);
            Route::post('/{id}/lampiran',                 [KlaimController::class, 'uploadAttachment'])->middleware('permission:bpjs.write');
            Route::delete('/{id}/lampiran/{attId}',       [KlaimController::class, 'deleteAttachment'])->middleware('permission:bpjs.write');
        });

        // -----------------------------------------------------------------
        // REKAM MEDIS (Protected Data)
        // -----------------------------------------------------------------
        Route::prefix('rekam-medis')->middleware('permission:rekam_medis.read')->group(function () {
            Route::get('/pasien',                          [RekamMedisController::class, 'cariPasien']);
            Route::get('/pasien-tanggal',                  [RekamMedisController::class, 'pasienByTanggal']);
            Route::get('/pasien/{patientId}',              [RekamMedisController::class, 'riwayatPasien']);
            Route::get('/pasien/{patientId}/kunjungan',    [RekamMedisController::class, 'indexKunjungan']);
            Route::get('/pasien/{patientId}/ringkasan',    [RekamMedisController::class, 'ringkasanPasien']);
            Route::get('/pasien/{patientId}/refraksi',     [RekamMedisController::class, 'refraksiPasien']);
            Route::get('/pasien/{patientId}/penunjang',    [RekamMedisController::class, 'penunjangPasien']);
            Route::get('/pasien/{patientId}/cppt',         [RekamMedisController::class, 'cpptPasien']);
            Route::get('/pasien/{patientId}/obat',         [RekamMedisController::class, 'obatPasien']);
            Route::get('/pasien/{patientId}/bedah',        [RekamMedisController::class, 'bedahPasien']);
            Route::get('/pasien/{patientId}/diagnosis',    [RekamMedisController::class, 'diagnosisPasien']);
            Route::get('/pasien/{patientId}/dokumen',      [RekamMedisController::class, 'dokumenPasien']);
            Route::get('/kunjungan/{visitId}/resume-medis', [RekamMedisController::class, 'resumeMedis']);
            Route::get('/kunjungan/{visitId}/kwitansi',     [RekamMedisController::class, 'kwitansiKunjungan']);

            Route::get('/dokumen',                         [RekamMedisController::class, 'indexDokumen']);
            Route::get('/dokumen/{id}',                    [RekamMedisController::class, 'showDokumen']);
            Route::post('/dokumen',                        [RekamMedisController::class, 'storeDokumen'])->middleware('permission:rekam_medis.write');
            Route::put('/dokumen/{id}',                    [RekamMedisController::class, 'updateDokumen'])->middleware('permission:rekam_medis.write');
            Route::post('/dokumen/{id}/submit',            [RekamMedisController::class, 'submitDokumen'])->middleware('permission:rekam_medis.write');
            Route::post('/dokumen/{id}/void',              [RekamMedisController::class, 'voidDokumen'])->middleware('permission:rekam_medis.write');
            Route::get('/dokumen/{id}/cetak',              [RekamMedisController::class, 'cetakDokumen']);
            Route::post('/dokumen/{id}/resend-notif',      [RekamMedisController::class, 'resendNotifDokumen']);

            Route::get('/verifikasi/{token}',              [RekamMedisController::class, 'verifikasiDokumen']);

            Route::get('/medical-record/{visitId}',        [RekamMedisController::class, 'showMedicalRecord']);
            Route::post('/medical-record',                 [RekamMedisController::class, 'storeMedicalRecord'])->middleware('permission:rekam_medis.write');
            Route::put('/medical-record/{id}',             [RekamMedisController::class, 'updateMedicalRecord'])->middleware('permission:rekam_medis.write');
            Route::get('/medical-record/{id}/versions',    [RekamMedisController::class, 'versionsMedicalRecord']);

            Route::get('/notifikasi',                      [RekamMedisController::class, 'indexNotifikasi']);
            Route::put('/notifikasi/{id}/baca',            [RekamMedisController::class, 'bacaNotifikasi']);

            // -----------------------------------------------------------------
            // FORM REGISTRY — Runtime (Fase 1 + 3 + 4)
            // -----------------------------------------------------------------
            Route::get('/forms',                           [RekamMedisController::class, 'indexForms']);
            Route::get('/form/{code}/template',            [RekamMedisController::class, 'formTemplate']);
            Route::get('/form/{code}/render',              [RekamMedisController::class, 'renderForm']);
            Route::get('/form/{code}/prefill',             [RekamMedisController::class, 'prefillForm']);
            Route::post('/form/{code}/submit',             [RekamMedisController::class, 'submitForm']);
            Route::post('/document/{id}/mark-rendered',    [RekamMedisController::class, 'markDocumentRendered']);
            Route::post('/document/{id}/finalize',         [RekamMedisController::class, 'finalizeDocument']);
            Route::get('/document/{id}/render',            [RekamMedisController::class, 'showDocumentSnapshot']);
            Route::put('/document/{id}/draft-content',     [RekamMedisController::class, 'saveDraftContent']);
            // discardDraft dari FormRMRenderer — bagian alur AUTHORING klinis yang dipakai
            // peran RME read-only (perawat/refraksionis mengisi form). Biarkan di grup
            // rekam_medis.read (sejajar submit/finalize/draft-content) agar author bisa
            // mengelola draft sendiri; guard state-machine di service menolak dokumen final.
            Route::delete('/document/{id}',                [RekamMedisController::class, 'deleteDocument']);

            // Fase 4 — Signature flow
            Route::post('/document/{id}/sign',             [RekamMedisController::class, 'signDocument']);
            Route::get('/document/{id}/signatures',        [RekamMedisController::class, 'listDocumentSignatures']);
            // createAddendum juga dipicu dari FormRMRenderer (alur authoring) → biarkan
            // di rekam_medis.read agar author read-only bisa menambah addendum sendiri.
            Route::post('/document/{id}/addendum',         [RekamMedisController::class, 'createAddendum']);
            Route::post('/document/{id}/revisi',           [RekamMedisController::class, 'reviseDocument'])->middleware('permission:rekam_medis.write');
            Route::get('/signature/{signatureId}/verify',  [RekamMedisController::class, 'verifySignature']);
            Route::get('/signature/{signatureId}/audit',   [RekamMedisController::class, 'auditSignature']);
            // Antrean TTD dokter dipisah ke key ttd_dokumen (hanya dokter lihat menu).
            // Cumulative dgn grup rekam_medis.read → butuh KEDUANYA (dokter punya keduanya).
            // TTD consent pasien/saksi via /document/{id}/sign TETAP di rekam_medis.read.
            Route::get('/ttd-queue',                       [RekamMedisController::class, 'ttdQueue'])->middleware('permission:ttd_dokumen.read');
            Route::post('/ttd-bulk-sign',                  [RekamMedisController::class, 'ttdBulkSign'])->middleware('permission:ttd_dokumen.write');
            Route::get('/ttd-count',                       [RekamMedisController::class, 'ttdCount'])->middleware('permission:ttd_dokumen.read');
            Route::get('/ttd-signed-today',                [RekamMedisController::class, 'ttdSignedToday'])->middleware('permission:ttd_dokumen.read');

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
            Route::get('/pendapatan',                       [DashboardController::class, 'pendapatan'])->middleware('permission:keuangan.read|marketing.read');
            Route::get('/kunjungan-chart',                  [DashboardController::class, 'getVisitChart']);
            Route::get('/pendapatan-chart',                 [DashboardController::class, 'getRevenueChart'])->middleware('permission:keuangan.read|marketing.read');
            Route::get('/diagnosis-stats',                  [DashboardController::class, 'getDiagnosisStats']);
            Route::get('/distribusi-penjamin',              [DashboardController::class, 'distribusiPenjamin'])->middleware('permission:keuangan.read|marketing.read');
            Route::get('/jam-tersibuk',                     [DashboardController::class, 'jamTersibuk']);

            Route::get('/follow-up/hari-ini',               [DashboardController::class, 'followUpHariIni']);
            Route::get('/follow-up/minggu-ini',             [DashboardController::class, 'followUpMingguIni']);
            Route::get('/follow-up/statistik',              [DashboardController::class, 'followUpStatistik']);

            Route::get('/stok-alert',                       [DashboardController::class, 'stokAlert']);
            Route::get('/bpjs-expired',                     [DashboardController::class, 'bpjsExpiredAlert']);
            Route::get('/satusehat-status',                 [DashboardController::class, 'satusehatStatus']);

            Route::get('/laporan/kunjungan',                [DashboardController::class, 'laporanKunjungan'])->middleware('permission:keuangan.read|marketing.read');
            Route::get('/laporan/pendapatan',               [DashboardController::class, 'laporanPendapatan'])->middleware('permission:keuangan.read|marketing.read');
            Route::get('/laporan/klaim',                    [DashboardController::class, 'laporanKlaim'])->middleware('permission:keuangan.read|marketing.read');
        });

        // -----------------------------------------------------------------
        // LAPORAN MARKETING (read-only daftar pasien untuk campaign)
        // -----------------------------------------------------------------
        Route::prefix('laporan-marketing')->middleware('permission:marketing.read')->group(function () {
            Route::get('/notifications',         [MarketingReportController::class, 'notifications']);
            Route::get('/kwitansi/{invoiceId}',  [MarketingReportController::class, 'kwitansi']);
            Route::get('/export-csv',            [MarketingReportController::class, 'export']);

            // Dashboard & analitik (read)
            Route::get('/dashboard-penjamin',        [MarketingReportController::class, 'dashboardPenjamin']);
            Route::get('/dashboard-penjamin/export', [MarketingReportController::class, 'dashboardPenjaminExport']);
            Route::get('/top-wilayah',               [MarketingReportController::class, 'topWilayah']);

            // Survei Kepuasan (read + konfigurasi URL + sync manual)
            Route::get('/survei',                [MarketingReportController::class, 'survei']);
            Route::put('/survei/config',         [MarketingReportController::class, 'surveiConfig'])->middleware('permission:marketing.write');
            Route::post('/survei/sync',          [MarketingReportController::class, 'syncSurvei'])->middleware('permission:marketing.write');

            // Monitoring Kerjasama (CRUD)
            Route::get('/kerjasama',             [MarketingReportController::class, 'kerjasamaIndex']);
            Route::post('/kerjasama',            [MarketingReportController::class, 'kerjasamaStore'])->middleware('permission:marketing.write');
            Route::put('/kerjasama/{id}',        [MarketingReportController::class, 'kerjasamaUpdate'])->middleware('permission:marketing.write');
            Route::delete('/kerjasama/{id}',     [MarketingReportController::class, 'kerjasamaDestroy'])->middleware('permission:marketing.write');

            // Program & Event (CRUD + peserta dari Google Sheet)
            Route::get('/events',                    [MarketingReportController::class, 'eventIndex']);
            Route::post('/events',                   [MarketingReportController::class, 'eventStore'])->middleware('permission:marketing.write');
            Route::put('/events/{id}',               [MarketingReportController::class, 'eventUpdate'])->middleware('permission:marketing.write');
            Route::delete('/events/{id}',            [MarketingReportController::class, 'eventDestroy'])->middleware('permission:marketing.write');
            Route::get('/events/{id}/participants',  [MarketingReportController::class, 'eventParticipants']);
            Route::post('/events/{id}/sync',         [MarketingReportController::class, 'eventSync'])->middleware('permission:marketing.write');

            Route::get('/',                      [MarketingReportController::class, 'index']);
        });

        // -----------------------------------------------------------------
        // KEUANGAN — Rekap honor dokter (jasa medis) + aturan honor
        // -----------------------------------------------------------------
        Route::prefix('keuangan')->group(function () {
            Route::get('/honor-recap',        [KeuanganController::class, 'recap'])->middleware('permission:keuangan.read');
            Route::get('/honor-recap/export', [KeuanganController::class, 'export'])->middleware('permission:keuangan.read');
            Route::get('/laporan-obat',        [KeuanganController::class, 'medicationReport'])->middleware('permission:keuangan.read');
            Route::get('/laporan-obat/export', [KeuanganController::class, 'medicationReportExport'])->middleware('permission:keuangan.read');
            Route::get('/fee-rules/options',  [KeuanganController::class, 'options'])->middleware('permission:keuangan.read');
            Route::get('/fee-rules',          [KeuanganController::class, 'indexRules'])->middleware('permission:keuangan.read');
            Route::post('/fee-rules',         [KeuanganController::class, 'storeRule'])->middleware('permission:keuangan.write');
            Route::put('/fee-rules/{id}',     [KeuanganController::class, 'updateRule'])->middleware('permission:keuangan.write');
            Route::delete('/fee-rules/{id}',  [KeuanganController::class, 'destroyRule'])->middleware('permission:keuangan.delete');
        });

        // -----------------------------------------------------------------
        // MASTER DATA
        // -----------------------------------------------------------------
        Route::prefix('master')->group(function () {
            Route::get('/profil-klinik',                    [MasterDataController::class, 'showProfilKlinik']);
            Route::put('/profil-klinik',                    [MasterDataController::class, 'updateProfilKlinik'])->middleware('permission:master_data.write');
            Route::post('/profil-klinik/logo',              [MasterDataController::class, 'uploadProfilKlinikLogo'])->middleware('permission:master_data.write');
            Route::delete('/profil-klinik/logo',            [MasterDataController::class, 'deleteProfilKlinikLogo'])->middleware('permission:master_data.write');

            // Master Room & Bed — dikelola dari Profil Klinik (Pengaturan) MAUPUN
            // Fasilitas & Ruang (Rawat Inap). Permission OR agar kedua UI bisa akses.
            Route::get('/room',                             [RoomController::class, 'index'])->middleware('permission:master_data.read|rawat_inap.read');
            Route::post('/room',                            [RoomController::class, 'store'])->middleware('permission:master_data.write|rawat_inap.write');
            Route::put('/room/{id}',                        [RoomController::class, 'update'])->middleware('permission:master_data.write|rawat_inap.write');
            Route::delete('/room/{id}',                     [RoomController::class, 'destroy'])->middleware('permission:master_data.write|rawat_inap.write');
            Route::post('/room/{roomId}/bed',               [RoomController::class, 'storeBed'])->middleware('permission:master_data.write|rawat_inap.write');
            Route::put('/bed/{id}',                         [RoomController::class, 'updateBed'])->middleware('permission:master_data.write|rawat_inap.write');
            Route::delete('/bed/{id}',                      [RoomController::class, 'destroyBed'])->middleware('permission:master_data.write|rawat_inap.write');

            // Master OPSI REFRAKSI (combobox RefraksionisView) — GET utk admin,
            // write digate master_data.write. Read opsi siap-pakai ada di /refraksi/opsi.
            Route::get('/refraksi-opsi',                    [RefractionOptionController::class, 'index'])->middleware('permission:master_data.read');
            Route::post('/refraksi-opsi',                   [RefractionOptionController::class, 'store'])->middleware('permission:master_data.write');
            Route::put('/refraksi-opsi/{id}',               [RefractionOptionController::class, 'update'])->middleware('permission:master_data.write');
            Route::delete('/refraksi-opsi/{id}',            [RefractionOptionController::class, 'destroy'])->middleware('permission:master_data.write');

            // Tarif kamar per kelas per insurer (Rawat Inap).
            Route::get('/room-tariff',                      [RoomController::class, 'indexTariff'])->middleware('permission:tarif_paket.read|rawat_inap.read');
            Route::post('/room-tariff',                     [RoomController::class, 'storeTariff'])->middleware('permission:tarif_paket.write|rawat_inap.write');
            Route::delete('/room-tariff/{id}',              [RoomController::class, 'destroyTariff'])->middleware('permission:tarif_paket.delete|rawat_inap.write');

            Route::get('/nomor-dokumen',                    [MasterDataController::class, 'indexNomorDokumen'])->middleware('permission:master_data.read');
            Route::post('/nomor-dokumen',                   [MasterDataController::class, 'storeNomorDokumen'])->middleware('permission:master_data.write');
            Route::put('/nomor-dokumen/{id}',               [MasterDataController::class, 'updateNomorDokumen'])->middleware('permission:master_data.write');
            Route::delete('/nomor-dokumen/{id}',            [MasterDataController::class, 'destroyNomorDokumen'])->middleware('permission:master_data.write');

            Route::get('/roles',                            [MasterDataController::class, 'indexRoles'])->middleware('permission:role_akses.read');
            Route::post('/roles',                           [MasterDataController::class, 'storeRole'])->middleware('permission:role_akses.write');
            Route::put('/roles/{id}',                       [MasterDataController::class, 'updateRole'])->middleware('permission:role_akses.write');
            Route::delete('/roles/{id}',                    [MasterDataController::class, 'deleteRole'])->middleware('permission:role_akses.delete');

            Route::get('/pegawai',                          [MasterDataController::class, 'indexPegawai'])->middleware('permission:role_akses.read');
            Route::get('/pegawai/{id}',                     [MasterDataController::class, 'showPegawai'])->middleware('permission:role_akses.read');
            Route::post('/pegawai',                         [MasterDataController::class, 'storePegawai'])->middleware('permission:role_akses.write');
            Route::put('/pegawai/{id}',                     [MasterDataController::class, 'updatePegawai'])->middleware('permission:role_akses.write');
            Route::delete('/pegawai/{id}',                  [MasterDataController::class, 'deletePegawai'])->middleware('permission:role_akses.delete');
            Route::put('/pegawai/{id}/reset-password',      [MasterDataController::class, 'resetPasswordPegawai'])->middleware('permission:role_akses.write');

            // CSV/Excel penjamin — WAJIB didaftar SEBELUM route generic /penjamin/{id}
            // Penjamin = "Metode Bayar", UI-nya di modul Tarif & Paket → tulis digate
            // tarif_paket.write (BUKAN master_data) agar role keuangan/tarif bisa kelola.
            // GET dibiarkan auth-only (admisi/kasir baca penjamin saat daftar/tagih).
            Route::get('/penjamin/template-csv',            [MasterDataController::class, 'templatePenjaminCsv']);
            Route::get('/penjamin/export-csv',              [MasterDataController::class, 'exportPenjaminCsv']);
            Route::post('/penjamin/import-csv',             [MasterDataController::class, 'importPenjaminCsv'])->middleware('permission:tarif_paket.write');
            // TPA membership (kelola anggota dari sisi TPA) — WAJIB sebelum /penjamin/{id} generic
            Route::get('/penjamin/{tpaId}/member-candidates', [MasterDataController::class, 'candidateMembers']);
            Route::post('/penjamin/{tpaId}/members',          [MasterDataController::class, 'addPenjaminMember'])->middleware('permission:tarif_paket.write');
            Route::delete('/penjamin/{tpaId}/members/{memberId}', [MasterDataController::class, 'removePenjaminMember'])->middleware('permission:tarif_paket.write');
            Route::get('/penjamin',                         [MasterDataController::class, 'indexPenjamin']);
            Route::post('/penjamin',                        [MasterDataController::class, 'storePenjamin'])->middleware('permission:tarif_paket.write');
            Route::put('/penjamin/{id}',                    [MasterDataController::class, 'updatePenjamin'])->middleware('permission:tarif_paket.write');
            Route::delete('/penjamin/{id}',                 [MasterDataController::class, 'deletePenjamin'])->middleware('permission:tarif_paket.write');

            // Billing Categories — kategori grouping rincian tagihan Kasir
            Route::get('/kategori-tagihan',                 [MasterDataController::class, 'indexBillingCategory']);
            // Kategori Tagihan masuk ke tarif_paket (GET tetap publik utk render kwitansi Kasir).
            Route::post('/kategori-tagihan',                [MasterDataController::class, 'storeBillingCategory'])->middleware('permission:tarif_paket.write');
            Route::put('/kategori-tagihan/reorder',         [MasterDataController::class, 'reorderBillingCategory'])->middleware('permission:tarif_paket.write');
            Route::put('/kategori-tagihan/{id}',            [MasterDataController::class, 'updateBillingCategory'])->middleware('permission:tarif_paket.write');
            Route::delete('/kategori-tagihan/{id}',         [MasterDataController::class, 'deleteBillingCategory'])->middleware('permission:tarif_paket.delete');

            Route::get('/tindakan/template-csv',            [MasterDataController::class, 'templateCsv'])->defaults('type', 'tindakan');
            Route::get('/tindakan/export-csv',              [MasterDataController::class, 'exportCsv'])->defaults('type', 'tindakan');
            Route::post('/tindakan/import-csv',             [MasterDataController::class, 'importCsv'])->defaults('type', 'tindakan')->middleware('permission:tarif_paket.write');
            Route::get('/tindakan/kategori-list',           [MasterDataController::class, 'kategoriListTindakan']);
            // CSV/Excel kategori — WAJIB sebelum route /tindakan/kategori/{id}
            Route::get('/tindakan/kategori/template-csv',   [MasterDataController::class, 'templateKategoriCsv']);
            Route::get('/tindakan/kategori/export-csv',     [MasterDataController::class, 'exportKategoriCsv']);
            Route::post('/tindakan/kategori/import-csv',    [MasterDataController::class, 'importKategoriCsv'])->middleware('permission:tarif_paket.write');
            Route::get('/tindakan/kategori',                [MasterDataController::class, 'indexProcedureCategories']);
            Route::post('/tindakan/kategori',               [MasterDataController::class, 'storeProcedureCategory'])->middleware('permission:tarif_paket.write');
            Route::put('/tindakan/kategori/{id}',           [MasterDataController::class, 'updateProcedureCategory'])->middleware('permission:tarif_paket.write');
            Route::delete('/tindakan/kategori/{id}',        [MasterDataController::class, 'deleteProcedureCategory'])->middleware('permission:tarif_paket.delete');
            Route::get('/tindakan',                         [MasterDataController::class, 'indexTindakan']);
            Route::post('/tindakan',                        [MasterDataController::class, 'storeTindakan'])->middleware('permission:tarif_paket.write');
            Route::put('/tindakan/{id}',                    [MasterDataController::class, 'updateTindakan'])->middleware('permission:tarif_paket.write');
            Route::delete('/tindakan/{id}',                 [MasterDataController::class, 'deleteTindakan'])->middleware('permission:tarif_paket.delete');

            // CSV (template/export/import) — WAJIB didaftar SEBELUM route /{id}
            Route::get('/icd10/template-csv',               [MasterDataController::class, 'templateCsv'])->defaults('type', 'icd10')->middleware('permission:master_data.read');
            Route::get('/icd10/export-csv',                 [MasterDataController::class, 'exportCsv'])->defaults('type', 'icd10')->middleware('permission:master_data.read');
            Route::post('/icd10/import-csv',                [MasterDataController::class, 'importCsv'])->defaults('type', 'icd10')->middleware('permission:master_data.write');
            // Carve-out: pencarian ICD utk diagnosis dipakai stasiun klinis (IGD/dokter/
            // perawat) → gate OR, BUKAN master_data. Maintenance (write/delete) tetap master_data.
            Route::get('/icd10',                            [MasterDataController::class, 'indexIcd10'])->middleware('permission:master_data.read|rme_dokter.read|perawat.read|igd.read');
            Route::post('/icd10',                           [MasterDataController::class, 'storeIcd10'])->middleware('permission:master_data.write');
            Route::put('/icd10/{id}',                       [MasterDataController::class, 'updateIcd10'])->middleware('permission:master_data.write');
            Route::delete('/icd10/{id}',                    [MasterDataController::class, 'deleteIcd10'])->middleware('permission:master_data.delete');

            Route::get('/icd9/template-csv',                [MasterDataController::class, 'templateCsv'])->defaults('type', 'icd9')->middleware('permission:master_data.read');
            Route::get('/icd9/export-csv',                  [MasterDataController::class, 'exportCsv'])->defaults('type', 'icd9')->middleware('permission:master_data.read');
            Route::post('/icd9/import-csv',                 [MasterDataController::class, 'importCsv'])->defaults('type', 'icd9')->middleware('permission:master_data.write');
            Route::get('/icd9',                             [MasterDataController::class, 'indexIcd9'])->middleware('permission:master_data.read|rme_dokter.read|perawat.read|igd.read');
            Route::post('/icd9',                            [MasterDataController::class, 'storeIcd9'])->middleware('permission:master_data.write');
            Route::put('/icd9/{id}',                        [MasterDataController::class, 'updateIcd9'])->middleware('permission:master_data.write');
            Route::delete('/icd9/{id}',                     [MasterDataController::class, 'deleteIcd9'])->middleware('permission:master_data.delete');

            // Jenis Penunjang (diagnostic_test_types) — master dikelola di modul Penunjang
            Route::get('/diagnostic-test-type',             [MasterDataController::class, 'indexDiagnosticTestType']);
            Route::post('/diagnostic-test-type',            [MasterDataController::class, 'storeDiagnosticTestType'])->middleware('permission:penunjang.write');
            Route::put('/diagnostic-test-type/{id}',        [MasterDataController::class, 'updateDiagnosticTestType'])->middleware('permission:penunjang.write');
            Route::delete('/diagnostic-test-type/{id}',     [MasterDataController::class, 'deleteDiagnosticTestType'])->middleware('permission:penunjang.write');

            Route::get('/obat/template-csv',                [MasterDataController::class, 'templateCsv'])->defaults('type', 'obat')->middleware('permission:inventori_farmasi.read');
            Route::get('/obat/export-csv',                  [MasterDataController::class, 'exportCsv'])->defaults('type', 'obat')->middleware('permission:inventori_farmasi.read');
            Route::post('/obat/import-csv',                 [MasterDataController::class, 'importCsv'])->defaults('type', 'obat')->middleware('permission:inventori_farmasi.write');
            Route::get('/obat',                             [MasterDataController::class, 'indexObat'])->middleware('permission:inventori_farmasi.read');
            Route::post('/obat',                            [MasterDataController::class, 'storeObat'])->middleware('permission:inventori_farmasi.write');
            Route::put('/obat/{id}',                        [MasterDataController::class, 'updateObat'])->middleware('permission:inventori_farmasi.write');
            Route::delete('/obat/{id}',                     [MasterDataController::class, 'deleteObat'])->middleware('permission:inventori_farmasi.delete');

            Route::get('/bhp/template-csv',                 [MasterDataController::class, 'templateCsv'])->defaults('type', 'bhp')->middleware('permission:inventori_farmasi.read');
            Route::get('/bhp/export-csv',                   [MasterDataController::class, 'exportCsv'])->defaults('type', 'bhp')->middleware('permission:inventori_farmasi.read');
            Route::post('/bhp/import-csv',                  [MasterDataController::class, 'importCsv'])->defaults('type', 'bhp')->middleware('permission:inventori_farmasi.write');
            Route::get('/bhp',                              [MasterDataController::class, 'indexBhp'])->middleware('permission:inventori_farmasi.read');
            Route::post('/bhp',                             [MasterDataController::class, 'storeBhp'])->middleware('permission:inventori_farmasi.write');
            Route::put('/bhp/{id}',                         [MasterDataController::class, 'updateBhp'])->middleware('permission:inventori_farmasi.write');
            Route::delete('/bhp/{id}',                      [MasterDataController::class, 'deleteBhp'])->middleware('permission:inventori_farmasi.delete');

            Route::get('/iol/template-csv',                 [MasterDataController::class, 'templateCsv'])->defaults('type', 'iol')->middleware('permission:inventori_farmasi.read');
            Route::get('/iol/export-csv',                   [MasterDataController::class, 'exportCsv'])->defaults('type', 'iol')->middleware('permission:inventori_farmasi.read');
            Route::post('/iol/import-csv',                  [MasterDataController::class, 'importCsv'])->defaults('type', 'iol')->middleware('permission:inventori_farmasi.write');
            Route::get('/iol',                              [MasterDataController::class, 'indexIol'])->middleware('permission:inventori_farmasi.read');
            Route::post('/iol/scan',                        [MasterDataController::class, 'scanIol'])->middleware('permission:inventori_farmasi.read|bedah.read'); // parse UDI + lookup (dipakai Penerimaan & Bedah). master_iol→inventori_farmasi (carve-out OR bedah).
            Route::post('/iol',                             [MasterDataController::class, 'storeIol'])->middleware('permission:inventori_farmasi.write');
            Route::put('/iol/{id}',                         [MasterDataController::class, 'updateIol'])->middleware('permission:inventori_farmasi.write');
            Route::delete('/iol/{id}',                      [MasterDataController::class, 'deleteIol'])->middleware('permission:inventori_farmasi.delete');

            // Alat Medis CSV (view fisik di /inventori-farmasi/alat-medis, CRUD pakai MedicalEquipmentController;
            // CSV pakai generic MasterDataController karena frontend csv client hit /master/{type}/*-csv)
            Route::get('/alat-medis/template-csv',          [MasterDataController::class, 'templateCsv'])->defaults('type', 'alat-medis')->middleware('permission:inventori_farmasi.read');
            Route::get('/alat-medis/export-csv',            [MasterDataController::class, 'exportCsv'])->defaults('type', 'alat-medis')->middleware('permission:inventori_farmasi.read');
            Route::post('/alat-medis/import-csv',           [MasterDataController::class, 'importCsv'])->defaults('type', 'alat-medis')->middleware('permission:inventori_farmasi.write');

            // Supplier CSV/Excel (view fisik di /inventori-farmasi/supplier, CRUD pakai SupplierController;
            // CSV pakai generic MasterDataController karena frontend csv client hit /master/{type}/*-csv)
            Route::get('/supplier/template-csv',            [MasterDataController::class, 'templateCsv'])->defaults('type', 'supplier')->middleware('permission:inventori_farmasi.read');
            Route::get('/supplier/export-csv',              [MasterDataController::class, 'exportCsv'])->defaults('type', 'supplier')->middleware('permission:inventori_farmasi.read');
            Route::post('/supplier/import-csv',             [MasterDataController::class, 'importCsv'])->defaults('type', 'supplier')->middleware('permission:inventori_farmasi.write');

            // Carve-out: dokter/perawat pilih paket saat planning bedah & rawat inap (read-only).
            Route::get('/paket-bedah',                      [MasterDataController::class, 'indexPaketBedah'])->middleware('permission:tarif_paket.read|bedah.read|rme_dokter.read|rawat_inap.read');
            Route::post('/paket-bedah',                     [MasterDataController::class, 'storePaketBedah'])->middleware('permission:tarif_paket.write');
            Route::put('/paket-bedah/{id}',                 [MasterDataController::class, 'updatePaketBedah'])->middleware('permission:tarif_paket.write');
            Route::delete('/paket-bedah/{id}',              [MasterDataController::class, 'deletePaketBedah'])->middleware('permission:tarif_paket.delete');

            // Buku Tarif terpadu (Tindakan+Obat+BHP+IOL satu daftar berkategori).
            Route::get('/buku-tarif',                       [MasterDataController::class, 'indexBukuTarif']);
            Route::put('/buku-tarif/harga',                 [MasterDataController::class, 'setBukuTarifPrice'])->middleware('permission:tarif_paket.write');
            // CSV/Excel terpadu — 1 file lintas-tipe (kolom `tipe`), roundtrip harga UMUM.
            Route::get('/buku-tarif/template-csv',          [MasterDataController::class, 'templateBukuTarifCsv']);
            Route::get('/buku-tarif/export-csv',            [MasterDataController::class, 'exportBukuTarifCsv']);
            Route::post('/buku-tarif/import-csv',           [MasterDataController::class, 'importBukuTarifCsv'])->middleware('permission:tarif_paket.write');

            Route::get('/tarif/tindakan',                   [MasterDataController::class, 'indexTarifTindakan']);
            Route::post('/tarif/tindakan',                  [MasterDataController::class, 'storeTarifTindakan'])->middleware('permission:tarif_paket.write');
            Route::put('/tarif/tindakan/{id}',              [MasterDataController::class, 'updateTarifTindakan'])->middleware('permission:tarif_paket.write');
            Route::delete('/tarif/tindakan/{id}',           [MasterDataController::class, 'deleteTarifTindakan'])->middleware('permission:tarif_paket.delete');
            Route::get('/tarif/tindakan/export-csv',        [MasterDataController::class, 'exportTarifCsv'])->defaults('type', 'tindakan');
            Route::post('/tarif/tindakan/import-csv',       [MasterDataController::class, 'importTarifCsv'])->defaults('type', 'tindakan')->middleware('permission:tarif_paket.write');

            Route::get('/tarif/obat',                       [MasterDataController::class, 'indexTarifObat']);
            Route::post('/tarif/obat',                      [MasterDataController::class, 'storeTarifObat'])->middleware('permission:tarif_paket.write');
            Route::put('/tarif/obat/{id}',                  [MasterDataController::class, 'updateTarifObat'])->middleware('permission:tarif_paket.write');
            Route::delete('/tarif/obat/{id}',               [MasterDataController::class, 'deleteTarifObat'])->middleware('permission:tarif_paket.delete');
            Route::get('/tarif/obat/export-csv',            [MasterDataController::class, 'exportTarifCsv'])->defaults('type', 'obat');
            Route::post('/tarif/obat/import-csv',           [MasterDataController::class, 'importTarifCsv'])->defaults('type', 'obat')->middleware('permission:tarif_paket.write');

            Route::get('/tarif/bhp',                        [MasterDataController::class, 'indexTarifBhp']);
            Route::post('/tarif/bhp',                       [MasterDataController::class, 'storeTarifBhp'])->middleware('permission:tarif_paket.write');
            Route::put('/tarif/bhp/{id}',                   [MasterDataController::class, 'updateTarifBhp'])->middleware('permission:tarif_paket.write');
            Route::delete('/tarif/bhp/{id}',                [MasterDataController::class, 'deleteTarifBhp'])->middleware('permission:tarif_paket.delete');
            Route::get('/tarif/bhp/export-csv',             [MasterDataController::class, 'exportTarifCsv'])->defaults('type', 'bhp');
            Route::post('/tarif/bhp/import-csv',            [MasterDataController::class, 'importTarifCsv'])->defaults('type', 'bhp')->middleware('permission:tarif_paket.write');

            Route::get('/tarif/iol',                        [MasterDataController::class, 'indexTarifIol']);
            Route::post('/tarif/iol',                       [MasterDataController::class, 'storeTarifIol'])->middleware('permission:tarif_paket.write');
            Route::put('/tarif/iol/{id}',                   [MasterDataController::class, 'updateTarifIol'])->middleware('permission:tarif_paket.write');
            Route::delete('/tarif/iol/{id}',                [MasterDataController::class, 'deleteTarifIol'])->middleware('permission:tarif_paket.delete');
            Route::get('/tarif/iol/export-csv',             [MasterDataController::class, 'exportTarifCsv'])->defaults('type', 'iol');
            Route::post('/tarif/iol/import-csv',            [MasterDataController::class, 'importTarifCsv'])->defaults('type', 'iol')->middleware('permission:tarif_paket.write');

            Route::get('/jenis-dokumen',                    [MasterDataController::class, 'indexJenisDokumen']);
            Route::post('/jenis-dokumen',                   [MasterDataController::class, 'storeJenisDokumen'])->middleware('permission:master_data.write');
            Route::put('/jenis-dokumen/{id}',               [MasterDataController::class, 'updateJenisDokumen'])->middleware('permission:master_data.write');
            Route::delete('/jenis-dokumen/{id}',            [MasterDataController::class, 'deleteJenisDokumen'])->middleware('permission:master_data.write');

            Route::get('/template-dokumen',                 [MasterDataController::class, 'indexTemplateDokumen']);
            Route::get('/template-dokumen/{id}',            [MasterDataController::class, 'showTemplateDokumen']);
            Route::post('/template-dokumen',                [MasterDataController::class, 'storeTemplateDokumen'])->middleware('permission:master_data.write');
            Route::put('/template-dokumen/{id}',            [MasterDataController::class, 'updateTemplateDokumen'])->middleware('permission:master_data.write');
            Route::delete('/template-dokumen/{id}',         [MasterDataController::class, 'deleteTemplateDokumen'])->middleware('permission:master_data.write');

            Route::get('/stasiun-dokumen',                  [MasterDataController::class, 'indexStasiunDokumen']);
            Route::put('/stasiun-dokumen/{id}',             [MasterDataController::class, 'updateStasiunDokumen'])->middleware('permission:master_data.write');

            // -----------------------------------------------------------------
            // FORM REGISTRY — Master Form Template (Fase 1 + Fase 2)
            // RBAC: master_data.{read|write}; superadmin auto-bypass. (eks form_template, admin-only)
            // -----------------------------------------------------------------
            Route::get('/field-registry',                              [MasterDataController::class, 'fieldRegistry'])->middleware('permission:master_data.read');
            Route::get('/station-sections',                            [MasterDataController::class, 'stationSections'])->middleware('permission:master_data.read');
            Route::get('/document-types',                              [MasterDataController::class, 'indexDocumentTypes'])->middleware('permission:master_data.read');
            Route::post('/document-type',                              [MasterDataController::class, 'storeDocumentType'])->middleware('permission:master_data.write');
            Route::put('/document-type/{id}',                          [MasterDataController::class, 'updateDocumentType'])->middleware('permission:master_data.write');
            Route::delete('/document-type/{id}',                       [MasterDataController::class, 'destroyDocumentType'])->middleware('permission:master_data.write');

            // Parser (Fase 2) — WAJIB didaftar SEBELUM /form-template/{id}
            Route::post('/form-template/upload',                       [MasterDataController::class, 'uploadFormTemplate'])->middleware('permission:master_data.write');
            Route::get('/form-template/parse-result/{parseId}',        [MasterDataController::class, 'parseResultFormTemplate'])->middleware('permission:master_data.write');

            Route::get('/form-template',                               [MasterDataController::class, 'indexFormTemplate'])->middleware('permission:master_data.read');
            Route::post('/form-template',                              [MasterDataController::class, 'storeFormTemplate'])->middleware('permission:master_data.write');
            Route::get('/form-template/{id}',                          [MasterDataController::class, 'showFormTemplate'])->middleware('permission:master_data.read');
            Route::put('/form-template/{id}',                          [MasterDataController::class, 'updateFormTemplate'])->middleware('permission:master_data.write');
            Route::post('/form-template/{id}/activate',                [MasterDataController::class, 'activateFormTemplate'])->middleware('permission:master_data.write');
            Route::post('/form-template/{id}/deactivate',              [MasterDataController::class, 'deactivateFormTemplate'])->middleware('permission:master_data.write');

            // CATATAN: Master Room / Bed / Tarif Kamar (Fasilitas & Ruang RANAP)
            // didaftarkan SEKALI di atas (lihat /room & /room-tariff) dengan
            // permission OR pengaturan|rawat_inap|tarif_paket. Blok duplikat lama
            // (gate rawat_inap saja) DIHAPUS — Laravel hanya pakai deklarasi pertama.
        });

        // -----------------------------------------------------------------
        // INVENTORI FARMASI — Master Supplier
        // -----------------------------------------------------------------
        Route::prefix('inventori-farmasi/supplier')->group(function () {
            Route::get('/',         [SupplierController::class, 'index'])->middleware('permission:inventori_farmasi.read');
            Route::post('/',        [SupplierController::class, 'store'])->middleware('permission:inventori_farmasi.write');
            Route::put('/{id}',     [SupplierController::class, 'update'])->middleware('permission:inventori_farmasi.write');
            Route::delete('/{id}',  [SupplierController::class, 'destroy'])->middleware('permission:inventori_farmasi.delete');
        });

        // -----------------------------------------------------------------
        // INVENTORI FARMASI — Pembelian (Purchase Order)
        // -----------------------------------------------------------------
        Route::prefix('inventori-farmasi/pembelian')->group(function () {
            Route::get('/',              [PurchaseOrderController::class, 'index'])->middleware('permission:inventori_farmasi.read');
            Route::post('/',             [PurchaseOrderController::class, 'store'])->middleware('permission:inventori_farmasi.write');
            Route::get('/{id}',          [PurchaseOrderController::class, 'show'])->middleware('permission:inventori_farmasi.read');
            Route::put('/{id}',          [PurchaseOrderController::class, 'update'])->middleware('permission:inventori_farmasi.write');
            Route::delete('/{id}',       [PurchaseOrderController::class, 'destroy'])->middleware('permission:inventori_farmasi.delete');
            Route::post('/{id}/cancel',  [PurchaseOrderController::class, 'cancel'])->middleware('permission:inventori_farmasi.write');
        });

        // -----------------------------------------------------------------
        // INVENTORI FARMASI — Penerimaan Barang (Goods Receipt / GRN)
        // -----------------------------------------------------------------
        Route::prefix('inventori-farmasi/penerimaan')->group(function () {
            Route::get('/from-po/{poId}',  [GoodsReceiptController::class, 'prepareFromPo'])->middleware('permission:inventori_farmasi.write');
            Route::get('/',                [GoodsReceiptController::class, 'index'])->middleware('permission:inventori_farmasi.read');
            Route::post('/',               [GoodsReceiptController::class, 'store'])->middleware('permission:inventori_farmasi.write');
            Route::get('/{id}',            [GoodsReceiptController::class, 'show'])->middleware('permission:inventori_farmasi.read');
            Route::delete('/{id}',         [GoodsReceiptController::class, 'destroy'])->middleware('permission:inventori_farmasi.delete');
        });

        // -----------------------------------------------------------------
        // INVENTORI FARMASI — Laporan (pemesanan/retur + tracking konsumsi)
        // Taruh SEBELUM route wildcard `inventori-farmasi/stock/{type}` agar tak bentrok.
        // -----------------------------------------------------------------
        Route::prefix('inventori-farmasi/laporan')->middleware('permission:inventori_farmasi.read')->group(function () {
            Route::get('/summary',          [InventoriReportController::class, 'summary']);
            Route::get('/pemesanan',        [InventoriReportController::class, 'pemesanan']);
            Route::get('/pemesanan/export', [InventoriReportController::class, 'pemesananExport']);
            Route::get('/retur',            [InventoriReportController::class, 'retur']);
            Route::get('/retur/export',     [InventoriReportController::class, 'returExport']);
            Route::get('/selisih',          [InventoriReportController::class, 'selisih']);
            Route::get('/selisih/export',   [InventoriReportController::class, 'selisihExport']);
        });

        // -----------------------------------------------------------------
        // INVENTORI FARMASI — Sesi Stock Opname (Berita Acara) + detail per item.
        // Layer perekaman di atas stock/opname; mutasi stok tak berubah.
        // Taruh SEBELUM wildcard `inventori-farmasi/stock/{type}` (aman: prefix beda).
        // -----------------------------------------------------------------
        Route::prefix('inventori-farmasi/opname-session')->group(function () {
            Route::get('/',     [StockOpnameSessionController::class, 'index'])->middleware('permission:inventori_farmasi.read');
            Route::get('/{id}', [StockOpnameSessionController::class, 'show'])->middleware('permission:inventori_farmasi.read');
            Route::post('/',    [StockOpnameSessionController::class, 'store'])->middleware('permission:inventori_farmasi.write');
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
        // Usage (dipakai dari BedahView / DokterView) — gate OR agar kedua stasiun bisa.
        Route::get('/alat-medis/visit/{visitId}/usages',     [MedicalEquipmentController::class, 'usagesByVisit'])->middleware('permission:bedah.read|rme_dokter.read');
        Route::post('/alat-medis/usage',                     [MedicalEquipmentController::class, 'recordUsage'])->middleware('permission:bedah.write|rme_dokter.write');
        Route::delete('/alat-medis/usage/{id}',              [MedicalEquipmentController::class, 'deleteUsage'])->middleware('permission:bedah.write|rme_dokter.write');

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

        // Penentuan Harga (inventory_prices) DIHAPUS — harga jual obat/BHP/IOL kini
        // dikelola di Buku Tarif (medication_tariffs/bhp_tariffs/iol_tariffs baris UMUM).

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

            // --- KEMASAN JUAL OBAT (varian per Strip/Box, harga independen) ---
            Route::get('/obat/{medicationId}/kemasan',      [MasterDataController::class, 'indexKemasanObat'])->middleware('permission:tarif_paket.read');
            Route::post('/obat/{medicationId}/kemasan',     [MasterDataController::class, 'storeKemasanObat'])->middleware('permission:tarif_paket.write');
            Route::put('/kemasan-obat/{id}',                [MasterDataController::class, 'updateKemasanObat'])->middleware('permission:tarif_paket.write');
            Route::delete('/kemasan-obat/{id}',             [MasterDataController::class, 'deleteKemasanObat'])->middleware('permission:tarif_paket.delete');

            // --- HELPER: harga master live per item (untuk auto-fill saat tambah tarif) ---
            Route::get('/master-price/{type}/{itemId}',     [TarifPaketController::class, 'masterPrice'])->middleware('permission:tarif_paket.read');

            // --- PAKET BEDAH — CSV (WAJIB didaftar SEBELUM /paket-bedah/{id}) ---
            Route::get('/paket-bedah/template-csv',         [TarifPaketController::class, 'templatePaketCsv'])->middleware('permission:tarif_paket.read');
            Route::get('/paket-bedah/export-csv',           [TarifPaketController::class, 'exportPaketCsv'])->middleware('permission:tarif_paket.read');
            Route::post('/paket-bedah/import-csv',          [TarifPaketController::class, 'importPaketCsv'])->middleware('permission:tarif_paket.write');

            // --- PAKET BEDAH (CRUD utama) ---
            // Carve-out read: BedahView "Tambah Paket" & RawatInap "Kirim ke Bedah".
            Route::get('/paket-bedah',                      [TarifPaketController::class, 'indexPaket'])->middleware('permission:tarif_paket.read|bedah.read|rme_dokter.read|rawat_inap.read');
            Route::post('/paket-bedah',                     [TarifPaketController::class, 'storePaket'])->middleware('permission:tarif_paket.write');
            Route::get('/paket-bedah/{id}',                 [TarifPaketController::class, 'showPaket'])->middleware('permission:tarif_paket.read');
            Route::put('/paket-bedah/{id}',                 [TarifPaketController::class, 'updatePaket'])->middleware('permission:tarif_paket.write');
            Route::delete('/paket-bedah/{id}',              [TarifPaketController::class, 'deletePaket'])->middleware('permission:tarif_paket.delete');

            // --- PAKET BEDAH — CSV per-paket (template/export komposisi 1 paket) ---
            Route::get('/paket-bedah/{id}/template-csv',    [TarifPaketController::class, 'templatePaketCsvForPackage'])->middleware('permission:tarif_paket.read');
            Route::get('/paket-bedah/{id}/export-csv',      [TarifPaketController::class, 'exportPaketCsvForPackage'])->middleware('permission:tarif_paket.read');

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
        Route::prefix('integrasi')->middleware('permission:integrasi.read')->group(function () {
            Route::get('/status',                           [IntegrasiController::class, 'statusSemua']);
            Route::post('/test/{system}',                   [IntegrasiController::class, 'testKoneksi']);
            Route::get('/config',                           [IntegrasiController::class, 'indexConfig']);
            // SETUP/CONFIG (ubah konfigurasi & kredensial bridging) → butuh integrasi.write.
            Route::put('/config/{id}',                      [IntegrasiController::class, 'updateConfig'])->middleware('permission:integrasi.write');

            Route::get('/bpjs/vclaim-log',                  [IntegrasiController::class, 'vclaimpLog']);
            Route::get('/bpjs/vclaim-log/{id}',             [IntegrasiController::class, 'showVclaimpLog']);
            Route::get('/bpjs/antrean-log',                 [IntegrasiController::class, 'antreanLog']);
            Route::get('/bpjs/icare-log',                   [IntegrasiController::class, 'icareLog']);
            Route::get('/bpjs/rm-dashboard',                [IntegrasiController::class, 'rekamMedisDashboard']);
            Route::get('/bpjs/rm-log',                      [IntegrasiController::class, 'rekamMedisLog']);
            // Kirim RM ke BPJS dari UI (batch + retry per-kunjungan) → integrasi.write.
            Route::post('/bpjs/rm-send-batch',              [IntegrasiController::class, 'rekamMedisSendBatch'])->middleware('permission:integrasi.write');
            Route::post('/bpjs/rm-resend/{visitId}',        [IntegrasiController::class, 'rekamMedisResend'])->middleware('permission:integrasi.write');
            Route::get('/bpjs/inacbgs-log',                 [IntegrasiController::class, 'inacbgsLog']);

            Route::get('/bpjs/rujukan-masuk',               [IntegrasiController::class, 'indexRujukanMasuk']);
            Route::get('/bpjs/rujukan-masuk/{id}',          [IntegrasiController::class, 'showRujukanMasuk']);
            Route::get('/bpjs/rujukan-keluar',              [IntegrasiController::class, 'indexRujukanKeluar']);
            Route::get('/bpjs/rujukan-keluar/{id}',         [IntegrasiController::class, 'showRujukanKeluar']);

            Route::get('/bpjs/surat-kontrol',               [IntegrasiController::class, 'indexSuratKontrol']);
            Route::get('/bpjs/surat-kontrol/{id}',          [IntegrasiController::class, 'showSuratKontrol']);
            Route::post('/bpjs/surat-kontrol/{id}/submit',  [IntegrasiController::class, 'submitSuratKontrol']);

            Route::get('/satusehat/dashboard',              [IntegrasiController::class, 'satusehatDashboard']);
            Route::get('/satusehat/kfa-search',             [IntegrasiController::class, 'satusehatKfaSearch']);
            // SETUP master dokter Satu Sehat (set NIK Practitioner) → integrasi.write.
            Route::put('/satusehat/dokter/{employeeId}/nik',[IntegrasiController::class, 'setEmployeeNik'])->middleware('permission:integrasi.write');
            Route::get('/satusehat/location',               [IntegrasiController::class, 'satusehatListLocations']);
            // SETUP lokasi Satu Sehat (daftar/aktif/ubah/hapus) → integrasi.write.
            Route::post('/satusehat/location',              [IntegrasiController::class, 'satusehatRegisterLocation'])->middleware('permission:integrasi.write');
            Route::put('/satusehat/location/{id}/active',   [IntegrasiController::class, 'satusehatSetActiveLocation'])->middleware('permission:integrasi.write');
            Route::put('/satusehat/location/{id}',          [IntegrasiController::class, 'satusehatUpdateLocation'])->middleware('permission:integrasi.write');
            Route::delete('/satusehat/location/{id}',       [IntegrasiController::class, 'satusehatDeactivateLocation'])->middleware('permission:integrasi.write');
            Route::get('/satusehat/sync-log',               [IntegrasiController::class, 'satusehatSyncLog']);
            Route::get('/satusehat/sync-log/{id}',          [IntegrasiController::class, 'showSatusehatSyncLog']);
            Route::get('/satusehat/resource-log',           [IntegrasiController::class, 'satusehatResourceLog']);
            // SYNC/BACKFILL push data ke Satu Sehat → integrasi.write (preview tetap read).
            Route::post('/satusehat/sync-manual',           [IntegrasiController::class, 'satusehatSyncManual'])->middleware('permission:integrasi.write');
            Route::post('/satusehat/retry/{logId}',         [IntegrasiController::class, 'satusehatRetry'])->middleware('permission:integrasi.write');
            Route::get('/satusehat/backfill/preview',       [IntegrasiController::class, 'satusehatBackfillPreview']);
            Route::post('/satusehat/backfill',              [IntegrasiController::class, 'satusehatBackfill'])->middleware('permission:integrasi.write');
            // Resolve IHS pasien massal (Kesiapan Data → tombol Resolve) → integrasi.write.
            Route::post('/satusehat/resolve-ihs',           [IntegrasiController::class, 'satusehatResolveIhs'])->middleware('permission:integrasi.write');

            // ---- VCLAIM live calls ----
            Route::post('/vclaim/cek-peserta',              [IntegrasiController::class, 'vclaimCekPeserta']);
            Route::post('/vclaim/cek-rujukan',              [IntegrasiController::class, 'vclaimCekRujukan']);
            Route::get('/vclaim/rujukan-peserta/{noKartu}', [IntegrasiController::class, 'vclaimRujukanByKartu']);
            Route::post('/vclaim/sep',                      [IntegrasiController::class, 'vclaimGenerateSep']);
            Route::put('/vclaim/sep',                       [IntegrasiController::class, 'vclaimUpdateSep']);
            Route::delete('/vclaim/sep',                    [IntegrasiController::class, 'vclaimCancelSep']);
            Route::post('/vclaim/lpk',                      [IntegrasiController::class, 'vclaimInsertLpk']);
            Route::get('/vclaim/monitoring/{jenis}',        [IntegrasiController::class, 'vclaimMonitoring']);
            Route::get('/vclaim/referensi/{jenis}',         [IntegrasiController::class, 'vclaimReferensi']);

            // ---- ANTREAN live calls ----
            Route::post('/antrean/add',                     [IntegrasiController::class, 'antreanAdd']);
            Route::post('/antrean/updatewaktu',             [IntegrasiController::class, 'antreanUpdateWaktu']);
            Route::post('/antrean/batal',                   [IntegrasiController::class, 'antreanBatal']);
            Route::get('/antrean/dashboard/{jenis}',        [IntegrasiController::class, 'antreanDashboard']);
            Route::post('/antrean/validate-booking',        [IntegrasiController::class, 'antreanValidateBooking']);
            Route::post('/antrean/by-booking',              [IntegrasiController::class, 'antreanByBooking']);
            Route::get('/antrean/ref-poli',                 [IntegrasiController::class, 'antreanRefPoli']);
            Route::get('/antrean/ref-dokter',               [IntegrasiController::class, 'antreanRefDokter']);
            Route::get('/antrean/jadwal-hfis/{kodepoli}/{tanggal}', [IntegrasiController::class, 'antreanJadwalHfis']);
            Route::get('/antrean/ref-pasien-fp/{jenis}/{noidentitas}', [IntegrasiController::class, 'antreanRefPasienFp']);

            // ---- Mapping Poli/DPJP BPJS (sinkron Jadwal Dokter) ----
            Route::get('/bpjs/poli-mapping',                [IntegrasiController::class, 'indexPoliMapping']);
            Route::get('/bpjs/poli-mapping/status',         [IntegrasiController::class, 'poliMappingStatus']);
            Route::post('/bpjs/poli-mapping',               [IntegrasiController::class, 'upsertPoliMapping']);
            Route::delete('/bpjs/poli-mapping/{id}',        [IntegrasiController::class, 'deletePoliMapping']);
            Route::put('/bpjs/dokter/{employeeId}/dpjp',    [IntegrasiController::class, 'setDpjpCode']);
            Route::post('/bpjs/sync-jadwal-dokter',         [IntegrasiController::class, 'syncJadwalDokter']);

            // Sinkron master ICD-10/ICD-9 dari referensi VClaim (cakupan oftalmologi)
            Route::post('/bpjs/sync-icd',                   [IntegrasiController::class, 'syncIcdFromVclaim'])->middleware('permission:integrasi.write');

            // APLICARE — ketersediaan tempat tidur (read = ref/read/log; write = sync push).
            Route::get('/bpjs/aplicare/ref-kelas',          [IntegrasiController::class, 'aplicareRefKelas']);
            Route::get('/bpjs/aplicare/read',               [IntegrasiController::class, 'aplicareRead']);
            Route::get('/bpjs/aplicare-log',                [IntegrasiController::class, 'aplicareLog']);
            Route::post('/bpjs/aplicare/sync',              [IntegrasiController::class, 'aplicareSync'])->middleware('permission:integrasi.write');

            // APOTEK ONLINE (fase 0) — referensi DPHO + monitoring daftar resep.
            Route::get('/bpjs/apotek/ref-dpho',             [IntegrasiController::class, 'apotekRefDpho']);
            Route::post('/bpjs/apotek/daftar-resep',        [IntegrasiController::class, 'apotekDaftarResep']);
        });

        // -----------------------------------------------------------------
        // ASURANSI / TPA Non-BPJS — verifikasi eligibility + klaim workflow
        // Tidak menyentuh BPJS (KlaimController). Permission: kasir.* (billing).
        // Spec: Docs/ARUMED_INSURANCE_TPA_MODULE.md
        // -----------------------------------------------------------------
        // Asuransi/TPA = key sendiri (dipisah dari kasir). Default tetap diberikan ke kasir.
        Route::prefix('asuransi')->group(function () {
            // Verifikasi eligibility (input manual hasil cek portal TPA)
            Route::get('/verifikasi/pending',     [AsuransiController::class, 'pendingVerifications'])->middleware('permission:asuransi.read');
            Route::get('/verifikasi/in-service',  [AsuransiController::class, 'inServiceVerifications'])->middleware('permission:asuransi.read');
            // COB: daftar verifikasi per insurer + basis selisih penjamin-2 (WAJIB sebelum /verifikasi/{visitId} generik).
            Route::get('/verifikasi-all/{visitId}', [AsuransiController::class, 'showVerifikasiAll'])->middleware('permission:asuransi.read');
            Route::get('/cob-basis/{visitId}',      [AsuransiController::class, 'cobBasis'])->middleware('permission:asuransi.read');
            Route::get('/verifikasi/{visitId}',   [AsuransiController::class, 'showVerifikasi'])->middleware('permission:asuransi.read');
            Route::post('/verifikasi',            [AsuransiController::class, 'storeVerifikasi'])->middleware('permission:asuransi.write');
            Route::put('/verifikasi/{id}',        [AsuransiController::class, 'updateVerifikasi'])->middleware('permission:asuransi.write');
            Route::get('/billing/{visitId}',      [AsuransiController::class, 'showBilling'])->middleware('permission:asuransi.read');

            // Klaim
            Route::get('/klaim',                  [AsuransiController::class, 'indexKlaim'])->middleware('permission:asuransi.read');
            Route::get('/klaim/{id}',             [AsuransiController::class, 'showKlaim'])->middleware('permission:asuransi.read');
            Route::post('/klaim',                 [AsuransiController::class, 'storeKlaim'])->middleware('permission:asuransi.write');
            Route::put('/klaim/{id}',             [AsuransiController::class, 'updateKlaim'])->middleware('permission:asuransi.write');
            Route::post('/klaim/{id}/submit',     [AsuransiController::class, 'submitKlaim'])->middleware('permission:asuransi.write');
            Route::put('/klaim/{id}/status',      [AsuransiController::class, 'updateStatusKlaim'])->middleware('permission:asuransi.write');
            Route::post('/klaim/{id}/resubmit',   [AsuransiController::class, 'resubmitKlaim'])->middleware('permission:asuransi.write');
            Route::get('/klaim/{id}/logs',        [AsuransiController::class, 'logsKlaim'])->middleware('permission:asuransi.read');

            // Laporan
            Route::get('/aging',                  [AsuransiController::class, 'agingReport'])->middleware('permission:asuransi.read');
            Route::get('/outstanding',            [AsuransiController::class, 'outstandingReport'])->middleware('permission:asuransi.read');
            Route::get('/summary',                [AsuransiController::class, 'dashboardSummary'])->middleware('permission:asuransi.read');

            // Master — Document Requirements per TPA
            Route::get('/insurer/{insurerId}/dokumen-requirement',  [AsuransiController::class, 'indexDocRequirement'])->middleware('permission:asuransi.read');
            Route::post('/insurer/{insurerId}/dokumen-requirement', [AsuransiController::class, 'storeDocRequirement'])->middleware('permission:asuransi.write');
            Route::put('/dokumen-requirement/{id}',                 [AsuransiController::class, 'updateDocRequirement'])->middleware('permission:asuransi.write');
            Route::delete('/dokumen-requirement/{id}',              [AsuransiController::class, 'deleteDocRequirement'])->middleware('permission:asuransi.delete');
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

            // Audit Log (read-only system_logs) — tab Audit Log di DataPenggunaView
            Route::get('/audit-logs',           [AuditLogController::class, 'index']);

            // Roles
            // CSV/Excel (definisikan sebelum /roles/{id} agar tidak ketangkap wildcard)
            Route::get('/roles/csv-template',   [RoleController::class, 'csvTemplate']);
            Route::get('/roles/export',         [RoleController::class, 'exportCsv']);
            Route::post('/roles/import',        [RoleController::class, 'importCsv']);

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