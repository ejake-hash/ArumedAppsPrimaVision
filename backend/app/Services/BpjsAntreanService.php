<?php

namespace App\Services;

use App\Services\Bpjs\BpjsClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BPJS Antrean RS (antreanrs_dev / antreanrs).
 *
 * Signature SAMA dengan VClaim, namun mayoritas response Antrean = JSON POLOS
 * (tidak terenkripsi) → semua call pakai BpjsClient::request(..., encrypted: false).
 *
 * Cakupan wajib lapor (penilaian keaktifan faskes oleh BPJS):
 *   - referensi (poli/dokter/jadwal/poli-fingerprint)
 *   - add antrean, updatewaktu (per tahap), batal antrean
 *   - sisa antrean, dashboard (per tgl/bulan), list waktu
 *   - sinkron jadwal dokter (add/update/delete)
 *
 * Prinsip wiring: dipanggil dari QueueService secara non-blocking — gagal lapor
 * BPJS TIDAK boleh memblok flow antrean lokal; cukup tercatat di bpjs_antrean_logs.
 */
class BpjsAntreanService
{
    private BpjsClient $client;

    public function __construct(?BpjsClient $client = null)
    {
        $this->client = $client ?? BpjsClient::for('ANTREAN');
    }

    public function boot(): void
    {
        $this->client = BpjsClient::for('ANTREAN');
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    // =========================================================================
    // REFERENSI ANTREAN
    // =========================================================================

    // Referensi HFIS-Antrean balasannya TERENKRIPSI (AES + LZString) — sama seperti
    // VClaim. Pakai getEnc() agar BpjsClient men-decrypt response ke array.
    /** GET /ref/poli — daftar poli terdaftar di HFIS Antrean. */
    public function refPoli(): array       { return $this->getEnc('/ref/poli'); }
    /** GET /ref/dokter — daftar dokter terdaftar di HFIS Antrean (berikut kodedokter/DPJP). */
    public function refDokter(): array     { return $this->getEnc('/ref/dokter'); }
    /** GET /jadwaldokter/kodepoli/{poli}/tanggal/{tgl} — jadwal HFIS (response terenkripsi). */
    public function refJadwalDokter(string $kodePoli, string $tanggal): array { return $this->getEnc("/jadwaldokter/kodepoli/{$kodePoli}/tanggal/{$tanggal}"); }
    /** GET /ref/poli/fp — daftar poli yang wajib fingerprint (HFIS). */
    public function refPoliFingerprint(): array { return $this->getEnc('/ref/poli/fp'); }

    /**
     * GET /ref/pasien/fp/identitas/{jenisidentitas}/noidentitas/{noidentitas}
     * Cek apakah peserta termasuk kategori yang wajib perekaman/validasi sidik jari
     * (Referensi Pasien Fingerprint — katalog Antrean 2.0).
     *   $jenisIdentitas : 'nik' | 'noka'
     *   $noIdentitas    : NIK (16) atau no kartu BPJS (13)
     */
    public function refPasienFingerprint(string $jenisIdentitas, string $noIdentitas): array
    {
        return $this->getEnc("/ref/pasien/fp/identitas/{$jenisIdentitas}/noidentitas/{$noIdentitas}");
    }

    // =========================================================================
    // ANTREAN — ADD / UPDATE WAKTU / BATAL
    // =========================================================================

    /**
     * POST /antrean/add — daftarkan antrean ke BPJS.
     * $data = payload lengkap (kodebooking, jenispasien, nomorkartu, nik, nohp,
     * kodepoli, namapoli, pasienbaru, norm, tanggalperiksa, kodedokter, jampraktek,
     * jeniskunjungan, nomorreferensi, nomorantrean, angkaantrean, estimasidilayani,
     * sisakuotajkn, kuotajkn, sisakuotanonjkn, kuotanonjkn, keterangan).
     */
    public function addAntrean(array $data, ?string $visitId = null): array
    {
        $result = $this->post('/antrean/add', $data);
        $this->log('ADD_ANTREAN', $data, $result, $visitId);

        return $result;
    }

    /**
     * POST /antrean/updatewaktu — lapor waktu tiap tahap (WAJIB lapor BPJS).
     * $data = { kodebooking, taskid, waktu (epoch ms) }.
     *   taskid (Docs/Antrol.md:340-347 — tiap task = SATU titik "akhir X / mulai Y"):
     *     1 mulai tunggu admisi · 2 mulai layan admisi · 3 selesai layan admisi/mulai tunggu poli
     *     4 mulai layan poli (dipanggil) · 5 selesai layan poli/mulai tunggu farmasi
     *     6 mulai buat obat · 7 obat selesai dibuat · 99 batal
     */
    public function updateWaktuAntrean(array $data, ?string $visitId = null): array
    {
        $result = $this->post('/antrean/updatewaktu', $data);
        $this->log('UPDATE_WAKTU', $data, $result, $visitId);

        return $result;
    }

    /**
     * POST /antrean/farmasi/add — daftarkan antrean farmasi RS (spec Antrol.md:297).
     * $data = { kodebooking, jenisresep (racikan|non racikan), nomorantrean, keterangan }.
     * Wajib bagi RS yang sudah mengimplementasi antrean farmasi.
     */
    public function addAntreanFarmasi(array $data, ?string $visitId = null): array
    {
        $result = $this->post('/antrean/farmasi/add', $data);
        $this->log('ADD_ANTREAN_FARMASI', $data, $result, $visitId);

        return $result;
    }

    /** POST /antrean/batal — { kodebooking, keterangan }. */
    public function batalAntrean(string $kodeBooking, string $keterangan, ?string $visitId = null): array
    {
        $data   = ['kodebooking' => $kodeBooking, 'keterangan' => $keterangan];
        $result = $this->post('/antrean/batal', $data);
        $this->log('BATAL_ANTREAN', $data, $result, $visitId);

        return $result;
    }

    /** POST /antrean/panggil — { kodebooking } (panggil pasien, opsional). */
    public function panggilAntrean(array $data, ?string $visitId = null): array
    {
        $result = $this->post('/antrean/panggil', $data);
        $this->log('PANGGIL_ANTREAN', $data, $result, $visitId);

        return $result;
    }

    // =========================================================================
    // SISA ANTREAN / DASHBOARD / LIST WAKTU (monitoring wajib)
    // =========================================================================

    /** POST /antrean/getlistantrean — { kodepoli, kodedokter, hari, jampraktek, tanggalperiksa }. */
    public function getListAntrean(array $data): array { return $this->post('/antrean/getlistantrean', $data); }

    /** POST /antrean/sisa — sisa antrean per poli/dokter. */
    public function getSisaAntrean(array $data): array { return $this->post('/antrean/sisa', $data); }

    /** POST /antrean/getlisttask — { kodebooking } daftar task waktu suatu booking. */
    public function getListWaktu(string $kodeBooking): array { return $this->post('/antrean/getlisttask', ['kodebooking' => $kodeBooking]); }

    /**
     * Antrean Per Kode Booking (monitoring) — detail antrean & alur task satu booking.
     * Antrean RS BPJS tidak punya endpoint "by-kodebooking" tersendiri; getlisttask
     * adalah sumber resmi data per-kodebooking (dipakai juga untuk validasi booking).
     */
    public function getAntreanByKodebooking(string $kodeBooking): array { return $this->getListWaktu($kodeBooking); }

    /** GET /dashboard/waktutunggu/tanggal/{tgl}/waktu/{rs|server} — dashboard wajib lapor harian. */
    public function dashboardWaktuTunggu(string $tanggal, string $waktu = 'rs'): array { return $this->get("/dashboard/waktutunggu/tanggal/{$tanggal}/waktu/{$waktu}"); }

    /** GET /dashboard/waktutunggu/bulan/{b}/tahun/{t}/waktu/{rs|server} — dashboard bulanan. */
    public function dashboardWaktuTungguBulan(string $bulan, string $tahun, string $waktu = 'rs'): array { return $this->get("/dashboard/waktutunggu/bulan/{$bulan}/tahun/{$tahun}/waktu/{$waktu}"); }

    // =========================================================================
    // JADWAL DOKTER — sinkron ke BPJS (wajib utk JKN Mobile / Aplicares)
    // =========================================================================

    /** POST /jadwaldokter/updatejadwaldokter — { kodepoli, kodesubspesialis, kodedokter, jadwal:[...] }. */
    public function updateJadwalDokter(array $data, ?string $visitId = null): array
    {
        $result = $this->post('/jadwaldokter/updatejadwaldokter', $data);
        $this->log('UPDATE_JADWAL_DOKTER', $data, $result, $visitId);

        return $result;
    }

    // =========================================================================
    // BOOKING JKN MOBILE  (backward-compat IntegrasiService)
    // =========================================================================

    /**
     * Validasi kode booking dari JKN Mobile (cari di list task antrean BPJS).
     * Antrean BPJS tidak punya endpoint "validate" baku; pakai getlisttask
     * untuk memastikan kodebooking valid & ambil detailnya.
     */
    public function validateBookingCode(string $bookingCode, string $tglPeriksa = ''): array
    {
        $result = $this->getListWaktu($bookingCode);
        $this->log('VALIDATE_BOOKING', compact('bookingCode', 'tglPeriksa'), $result);

        return $result;
    }

    /** Backward-compat: kuota per poli/tgl via referensi jadwal dokter. */
    public function checkQuota(string $kodePoli, string $tglPeriksa): array
    {
        return $this->refJadwalDokter($kodePoli, $tglPeriksa);
    }

    /** Backward-compat: konfirmasi kehadiran = updatewaktu taskid mulai layan. */
    public function confirmBooking(string $bookingCode, string $visitId): array
    {
        return $this->updateWaktuAntrean([
            'kodebooking' => $bookingCode,
            'taskid'      => 3,
            'waktu'       => (int) (microtime(true) * 1000),
        ], $visitId);
    }

    // =========================================================================
    // TEST
    // =========================================================================

    public function testConnection(): array
    {
        try {
            $result = $this->get('/ref/poli');
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'system' => 'ANTREAN'];
        }

        return [
            'success' => $result['is_success'] ?? false,
            'message' => $result['metaData']['message'] ?? 'Tidak ada respon',
            'system'  => 'ANTREAN',
            'code'    => $result['metaData']['code'] ?? null,
        ];
    }

    // =========================================================================
    // PRIVATE — call helpers (Antrean = JSON polos, encrypted: false)
    // =========================================================================

    // Antrean RS BPJS membalas metaData.code "1" untuk sukses (BUKAN "200" seperti
    // VClaim). Lewatkan ['1','200'] agar is_success benar di production — kalau tidak,
    // semua respons sukses Antrean (dashboard, validate booking, add/updatewaktu/batal)
    // salah-tandai gagal & ditampilkan sebagai error di UI.
    private const SUCCESS_CODES = ['1', '200'];

    private function get(string $path): array
    {
        return $this->client->request('GET', $path, null, encrypted: false, successCodes: self::SUCCESS_CODES);
    }

    /** GET untuk endpoint referensi HFIS yang balasannya TERENKRIPSI (AES + LZString). */
    private function getEnc(string $path): array
    {
        return $this->client->request('GET', $path, null, encrypted: true, successCodes: self::SUCCESS_CODES);
    }

    private function post(string $path, array $body): array
    {
        return $this->client->request('POST', $path, $body, encrypted: false, successCodes: self::SUCCESS_CODES);
    }

    private function log(string $action, array $request, array $result, ?string $visitId = null): void
    {
        DB::table('bpjs_antrean_logs')->insert([
            'id'               => (string) Str::uuid(),
            'visit_id'         => $visitId,
            'action'           => $action,
            'request_payload'  => json_encode($request),
            'response_payload' => json_encode($result['response'] ?? $result),
            'http_status'      => $result['http_status'] ?? 0,
            'is_success'       => $result['is_success'] ?? false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}
