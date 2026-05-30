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

    /** GET /ref/poli */
    public function refPoli(): array       { return $this->get('/ref/poli'); }
    /** GET /ref/dokter */
    public function refDokter(): array     { return $this->get('/ref/dokter'); }
    /** GET /ref/jadwaldokter/kodepoli/{poli}/tanggal/{tgl} */
    public function refJadwalDokter(string $kodePoli, string $tanggal): array { return $this->get("/ref/jadwaldokter/kodepoli/{$kodePoli}/tanggal/{$tanggal}"); }
    /** GET /ref/poligigi */
    public function refPoliFingerprint(): array { return $this->get('/ref/poli/fp'); }

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
     *   taskid: 1 mulai daftar, 2 selesai daftar, 3 mulai layan poli, 4 selesai layan,
     *           5 mulai farmasi, 6 selesai farmasi, 7 mulai/selesai (sesuai mapping BPJS).
     */
    public function updateWaktuAntrean(array $data, ?string $visitId = null): array
    {
        $result = $this->post('/antrean/updatewaktu', $data);
        $this->log('UPDATE_WAKTU', $data, $result, $visitId);

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

    private function get(string $path): array
    {
        return $this->client->request('GET', $path, null, encrypted: false);
    }

    private function post(string $path, array $body): array
    {
        return $this->client->request('POST', $path, $body, encrypted: false);
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
