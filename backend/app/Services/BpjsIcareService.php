<?php

namespace App\Services;

use App\Models\BpjsIcareLog;
use App\Models\Visit;
use App\Services\Bpjs\BpjsClient;

/**
 * BpjsIcareService — i-Care JKN (Indonesian Case Base Groups / riwayat pelayanan).
 *
 * i-Care FKRTL hanya 1 endpoint: POST {base}/api/rs/validate dengan body
 * { param: noKartu|NIK, kodedokter: int }. Respons (setelah didekripsi
 * envelope v2 oleh {@see BpjsClient}) BUKAN riwayat terstruktur, melainkan
 * { "url": "...ihs/history?token=..." } — sebuah link viewer HTML BPJS yang
 * dibuka di iframe/tab. Token bersifat sekali pakai → selalu generate on-demand.
 *
 * Faskes ini = RS Khusus Mata (FKRTL) → memakai jalur /api/rs/validate.
 * Transport (HMAC, AES-256-CBC, LZString) di-reuse penuh dari BpjsClient.
 */
class BpjsIcareService
{
    private BpjsClient $client;

    public function __construct(?BpjsClient $client = null)
    {
        $this->client = $client ?? BpjsClient::for('ICARE');
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    /**
     * Panggilan mentah i-Care FKRTL. Mengembalikan hasil BpjsClient apa adanya
     * + tambahan key `url` (diambil dari response yang sudah didekripsi).
     *
     * @return array{url:?string, metaData:array, response:mixed, http_status:int, is_success:bool, raw:string}
     */
    public function riwayatFkrtl(string $param, int $kodeDokter, ?string $visitId = null, array $auditMeta = []): array
    {
        $result = $this->client->request('POST', '/api/rs/validate', [
            'param'      => $param,
            'kodedokter' => $kodeDokter,
        ], encrypted: true);

        // BpjsClient sudah json_decode response saat sukses → array { url: ... }.
        $result['url'] = is_array($result['response'] ?? null)
            ? ($result['response']['url'] ?? null)
            : null;

        // Jejak audit akses PHI + bukti informed consent ikut tercatat di request_payload.
        $this->log($visitId, 'GET_RIWAYAT', array_merge(['param' => $param, 'kodedokter' => $kodeDokter], $auditMeta), $result);

        return $result;
    }

    /**
     * Resolve noKartu pasien + kodeDokter DPJP dari kunjungan, lalu ambil URL
     * viewer i-Care. Lempar exception 422 dengan pesan jelas bila data kurang.
     *
     * @return array{url:string, metaData:array}
     */
    public function riwayatForVisit(string $visitId, array $auditMeta = []): array
    {
        $visit = Visit::with(['patient', 'doctorSchedule.employee'])->findOrFail($visitId);

        // param i-Care = No. Kartu BPJS (13 digit) atau NIK (16 digit). Validasi panjang
        // di sisi aplikasi (UAT i-Care #1.2.5–1.2.8) agar identitas ngawur ditolak sebelum
        // menghit BPJS. Utamakan No. Kartu; fallback NIK.
        $noKartu = trim((string) ($visit->patient?->bpjs_number ?? ''));
        $nik     = trim((string) ($visit->patient?->nik ?? ''));
        if ($noKartu !== '') {
            if (! preg_match('/^\d{13}$/', $noKartu)) {
                throw new \Exception('Nomor Kartu BPJS pasien harus 13 digit angka (untuk i-Care).', 422);
            }
            $param = $noKartu;
        } elseif ($nik !== '') {
            if (! preg_match('/^\d{16}$/', $nik)) {
                throw new \Exception('NIK pasien harus 16 digit angka (untuk i-Care).', 422);
            }
            $param = $nik;
        } else {
            throw new \Exception('Pasien tidak memiliki nomor kartu BPJS maupun NIK.', 422);
        }

        $kodeDokter = $visit->doctorSchedule?->employee?->bpjs_dpjp_code;
        if (! $kodeDokter) {
            throw new \Exception(
                'Dokter belum memiliki Kode DPJP BPJS. Atur di Jadwal Dokter → Pemetaan BPJS.',
                422
            );
        }

        $result = $this->riwayatFkrtl($param, (int) $kodeDokter, $visitId, $auditMeta);

        if (! ($result['is_success'] ?? false) || empty($result['url'])) {
            throw new \Exception(
                $result['metaData']['message'] ?? 'Gagal mengambil riwayat i-Care dari BPJS.',
                422
            );
        }

        return ['url' => $result['url'], 'metaData' => $result['metaData'] ?? []];
    }

    private function log(?string $visitId, string $action, array $request, array $result): void
    {
        BpjsIcareLog::create([
            'visit_id'         => $visitId,
            'action'           => $action,
            'request_payload'  => $request,
            'response_payload' => is_array($result['response'] ?? null)
                ? $result['response']
                : ['raw' => $result['raw'] ?? null],
            'http_status'      => $result['http_status'] ?? 0,
            'is_success'       => $result['is_success'] ?? false,
            'error_message'    => ($result['is_success'] ?? false)
                ? null
                : ($result['metaData']['message'] ?? null),
        ]);
    }
}
