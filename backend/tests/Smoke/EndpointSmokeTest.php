<?php

namespace Tests\Smoke;

use Tests\TestCase;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * SMOKE TEST — sweep endpoint kunci, pastikan TIDAK ada 5xx.
 *
 * Tujuan: menangkap kelas bug "nama kolom/route/method salah" yang lolos ke
 * produksi karena tidak ada test (4 dari 6 bug audit pra-go-live 2026-05-29
 * sekelas ini: total_amount→total, route ordering, whereNull(deleted_at) di
 * tabel tanpa kolom, method controller hilang).
 *
 * BERBEDA dari Feature suite:
 *  - Jalan terhadap DB DEV (Postgres) yang SUDAH ter-migrate + seed.
 *    Env user tidak punya pdo_sqlite, dan bug-nya Postgres-specific → sqlite
 *    :memory: tidak akan mereproduksi. Karena itu TIDAK pakai RefreshDatabase.
 *  - READ-ONLY: hanya GET. Aman dijalankan berulang, tidak mengubah data.
 *
 * Jalankan: php artisan test --testsuite=Smoke
 * Prasyarat: DB dev hidup + sudah di-seed (user superadmin ada).
 */
class EndpointSmokeTest extends TestCase
{
    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Paksa koneksi pgsql dev (phpunit.xml men-set sqlite + DB_DATABASE=:memory:,
        // tapi env ini hanya punya pdo_pgsql + bug-nya Postgres-specific). Baca
        // kredensial asli dari .env supaya tidak ketiban override :memory:.
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql tidak tersedia — smoke test butuh DB Postgres dev.');
        }
        // phpunit.xml hanya override DB_CONNECTION/DB_DATABASE/DB_URL ke sqlite+:memory:.
        // host/port/username/password tetap dari .env. Yang perlu di-set ulang: nama DB
        // (env() masih kembalikan ':memory:') + kosongkan url.
        $dbName = getenv('SMOKE_DB_DATABASE') ?: 'arumed_apps';
        config([
            'database.default'                    => 'pgsql',
            'database.connections.pgsql.url'      => null,
            'database.connections.pgsql.database' => $dbName,
        ]);
        \DB::purge('pgsql'); // buang koneksi lama yang masih nyangkut :memory:

        // Login superadmin seeded. Kalau DB belum di-seed → skip dengan pesan jelas.
        $admin = User::where('username', 'superadmin')->first();
        if (!$admin) {
            $this->markTestSkipped('User superadmin tidak ditemukan — jalankan `php artisan migrate --seed` dulu.');
        }
        // fromUser() hanya menerbitkan token TANPA mengubah state auth global,
        // supaya test_unauthenticated benar-benar tanpa user aktif.
        $this->token = JWTAuth::fromUser($admin);
    }

    /** GET satu endpoint dengan bearer token, kembalikan status code. */
    private function getStatus(string $path): int
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept'        => 'application/json',
        ])->getJson($path)->getStatusCode();
    }

    /** Endpoint yang TADI 500 di audit — regression guard utama. */
    #[DataProvider('previouslyBrokenEndpoints')]
    public function test_previously_broken_endpoints_no_longer_5xx(string $path): void
    {
        $code = $this->getStatus($path);
        $this->assertLessThan(
            500,
            $code,
            "Regression: {$path} mengembalikan {$code} (5xx). Bug kolom/route/method kembali muncul."
        );
    }

    public static function previouslyBrokenEndpoints(): array
    {
        return [
            'asuransi klaim (bug #1 total_amount)'        => ['/api/v1/asuransi/klaim'],
            'dashboard satusehat (bug #5 deleted_at)'     => ['/api/v1/dashboard/satusehat-status'],
            'klaim vclaim-log (bug #4 route+deleted_at)'  => ['/api/v1/klaim/vclaim-log'],
        ];
    }

    /** Sweep luas READ-endpoint semua modul inti — pastikan tidak ada 5xx baru. */
    #[DataProvider('coreReadEndpoints')]
    public function test_core_read_endpoints_no_5xx(string $path): void
    {
        $code = $this->getStatus($path);
        $this->assertLessThan(500, $code, "Endpoint {$path} mengembalikan {$code} (5xx).");
    }

    public static function coreReadEndpoints(): array
    {
        return [
            // Antrian per-stasiun
            ['/api/v1/kasir/antrian'],
            ['/api/v1/kasir/invoice'],
            ['/api/v1/kasir/laporan'],
            ['/api/v1/farmasi/antrian'],
            ['/api/v1/farmasi/resep'],
            ['/api/v1/farmasi/surgery-request'],
            ['/api/v1/bedah/antrian'],
            ['/api/v1/bedah/jadwal'],
            ['/api/v1/bedah/request'],
            ['/api/v1/penunjang/antrian'],
            ['/api/v1/penunjang/order'],
            ['/api/v1/dokter/antrian'],
            ['/api/v1/dokter/notifikasi'],
            ['/api/v1/perawat/antrian'],
            ['/api/v1/refraksi/antrian'],
            ['/api/v1/admisi/dashboard'],
            ['/api/v1/admisi/kunjungan'],
            // Klaim & Asuransi
            ['/api/v1/klaim/'],
            ['/api/v1/asuransi/aging'],
            // Dashboard
            ['/api/v1/dashboard/statistik'],
            ['/api/v1/dashboard/antrian-aktif'],
            ['/api/v1/dashboard/stok-alert'],
            // Master & Tarif
            ['/api/v1/master/profil-klinik'],
            ['/api/v1/jadwal-dokter/aktif-hari-ini'],
            // Inventori
            ['/api/v1/inventori-farmasi/supplier'],
            ['/api/v1/inventori-farmasi/pembelian'],
            ['/api/v1/inventori-farmasi/penerimaan'],
            ['/api/v1/inventori-farmasi/harga/settings'],
            // Rekam medis
            ['/api/v1/rekam-medis/dokumen'],
            // Integrasi
            ['/api/v1/integrasi/status'],
        ];
    }

    /** Auth guard: tanpa token → 401, bukan 5xx. */
    public function test_unauthenticated_returns_401_not_5xx(): void
    {
        $code = $this->getJson('/api/v1/auth/me')->getStatusCode();
        $this->assertSame(401, $code, "Akses tanpa token harus 401, dapat {$code}.");
    }

    /** RBAC: route yatim /dokter/jadwal-bedah sudah dihapus (bug #3) → 404, bukan 500. */
    public function test_removed_orphan_route_is_404(): void
    {
        $code = $this->getStatus('/api/v1/dokter/jadwal-bedah');
        $this->assertSame(404, $code, "Route yatim /dokter/jadwal-bedah harus 404 (dihapus), dapat {$code}.");
    }
}
