<?php

namespace App\Services;

use App\Services\Bpjs\BpjsClient;

/**
 * BPJS Apotek Online — klaim obat PRB / Obat Kronis Belum Stabil / Obat
 * Kemoterapi yang ditagih TERPISAH dari paket INA-CBG, mereferensi SEP.
 *
 * STATUS: FASE 0 (siap-pakai). Hanya referensi + test koneksi yang aktif —
 * infrastruktur "ready" tanpa alur klinis penuh. Alur submit resep/pelayanan
 * obat (insert resep, obat racikan/non-racikan, mapping DPHO, monitoring klaim)
 * menyusul saat ada kebutuhan nyata (lihat Docs/PLAN_Bridging_Aplicare_Apotek.md
 * Bagian B fase 1–2). Untuk klinik mata volume PRB/kronis/kemo kecil.
 *
 * Signature SAMA dengan VClaim. Response referensi = JSON polos (metaData.code
 * "200" string) → encrypted: false, successCodes ['200'].
 * CATATAN: endpoint insert (v3) memakai Content-Type x-www-form-urlencoded —
 * BpjsClient sudah memakai itu untuk POST/PUT/DELETE (lihat BpjsClient::request).
 */
class BpjsApotekOnlineService
{
    private BpjsClient $client;

    public function __construct(?BpjsClient $client = null)
    {
        $this->client = $client ?? BpjsClient::for('APOTEK_ONLINE');
    }

    public function boot(): void
    {
        $this->client = BpjsClient::for('APOTEK_ONLINE');
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    private const SUCCESS_CODES = ['200'];

    // =========================================================================
    // REFERENSI (FASE 0 — aktif)
    // =========================================================================

    /** GET /referensi/dpho — Daftar Obat DPHO (master kode obat BPJS Apotek). */
    public function refDpho(): array
    {
        return $this->get('/referensi/dpho');
    }

    /** GET /referensi/obat/{jenis}/{tglResep}/{filter} — pencarian obat per jenis. */
    public function refObat(string $jenisObat, string $tglResep, string $filter): array
    {
        return $this->get("/referensi/obat/{$jenisObat}/{$tglResep}/" . rawurlencode($filter));
    }

    /** GET /referensi/poli/{q} — daftar poli (kode/nama). */
    public function refPoli(string $q): array
    {
        return $this->get('/referensi/poli/' . rawurlencode($q));
    }

    /** GET /referensi/spesialistik — daftar spesialistik. */
    public function refSpesialistik(): array
    {
        return $this->get('/referensi/spesialistik');
    }

    /** GET /referensi/settingppk/read/{kodeApotek} — setting apotek (apoteker/verifikator). */
    public function refSettingApotek(string $kodeApotek): array
    {
        return $this->get('/referensi/settingppk/read/' . rawurlencode($kodeApotek));
    }

    /**
     * POST /daftarresep — daftar resep pada rentang tanggal (monitoring).
     * Body: { kdppk, KdJnsObat, JnsTgl(TGLPELSJP|TGLRSP), TglMulai, TglAkhir }.
     */
    public function daftarResep(array $body): array
    {
        return $this->post('/daftarresep', $body);
    }

    /** GET /sep/{noSep} — data No Kunjungan/SEP (19 digit) untuk dasar resep apotek. */
    public function cariSep(string $noSep): array
    {
        return $this->get('/sep/' . rawurlencode($noSep));
    }

    // =========================================================================
    // PELAYANAN OBAT / RESEP (FASE 1 — placeholder, belum di-wire ke UI)
    // Disediakan sebagai seam; aktifkan saat membangun alur submit klaim apotek.
    // =========================================================================

    /** POST /sjpresep/v3/insert — Simpan Resep (menghasilkan NOSJP apotek). */
    public function insertResep(array $body): array
    {
        return $this->post('/sjpresep/v3/insert', $body);
    }

    /** POST /obatnonracikan/v3/insert — Simpan Obat Non Racikan. */
    public function insertObatNonRacikan(array $body): array
    {
        return $this->post('/obatnonracikan/v3/insert', $body);
    }

    /** POST /obatracikan/v3/insert — Simpan Obat Racikan. */
    public function insertObatRacikan(array $body): array
    {
        return $this->post('/obatracikan/v3/insert', $body);
    }

    /**
     * GET /monitoring/klaim/{bln}/{thn}/{jnsObat}/{status} — monitoring klaim apotek.
     * jnsObat: 0 semua, 1 PRB, 2 Kronis, 3 Kemoterapi. status: 1 belum, 2 sudah verif.
     */
    public function monitoringKlaim(string $bulan, string $tahun, string $jnsObat = '0', string $status = '1'): array
    {
        return $this->get("/monitoring/klaim/{$bulan}/{$tahun}/{$jnsObat}/{$status}");
    }

    // =========================================================================
    // TEST
    // =========================================================================

    public function testConnection(): array
    {
        try {
            // Referensi spesialistik = endpoint GET ringan tanpa parameter → cocok untuk ping.
            $result = $this->refSpesialistik();
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'system' => 'APOTEK_ONLINE'];
        }

        return [
            'success' => $result['is_success'] ?? false,
            'message' => $result['metaData']['message'] ?? 'Tidak ada respon',
            'system'  => 'APOTEK_ONLINE',
            'code'    => $result['metaData']['code'] ?? null,
        ];
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function get(string $path): array
    {
        return $this->client->request('GET', $path, null, encrypted: false, successCodes: self::SUCCESS_CODES);
    }

    private function post(string $path, array $body): array
    {
        return $this->client->request('POST', $path, $body, encrypted: false, successCodes: self::SUCCESS_CODES);
    }
}
