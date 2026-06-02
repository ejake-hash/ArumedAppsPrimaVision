<?php

namespace App\Services;

use App\Models\InacbgsGroupingLog;
use App\Models\IntegrationConfig;
use App\Services\Eklaim\EklaimCrypto;
use Illuminate\Support\Facades\Http;

/**
 * InaCbgsService — klien Web Service E-Klaim INA-CBG (build 5.10.x).
 *
 * Memanggil ws.php aplikasi E-Klaim lokal lewat enkripsi AES-256-CBC + HMAC
 * (lihat EklaimCrypto). Alur fungsional klaim:
 *   new_claim -> set_claim_data -> grouper -> claim_final
 * dengan get_claim_status / get_claim_data untuk sinkron balik dan
 * reedit_claim / delete_claim untuk koreksi.
 *
 * Sumber kredensial (diutamakan): IntegrationConfig system_name='INACBGS'
 * yang diisi lewat UI Bridging. Fallback: config/eklaim.php (.env).
 *
 * Setiap call dicatat ke inacbgs_grouping_logs (method + request/response
 * terdekripsi + response_code + message) untuk audit.
 *
 * CATATAN: nama method WS & struktur payload mengikuti bentuk umum WS
 * E-Klaim. Detail field build 5.10.x final difinalkan dari Manual WS resmi
 * (lihat builder di KlaimService::buildEklaimPayload — Fase 4).
 */
class InaCbgsService
{
    private ?IntegrationConfig $config = null;

    public function boot(): IntegrationConfig
    {
        if (! $this->config) {
            $this->config = IntegrationConfig::where('system_name', 'INACBGS')->first();
        }

        return $this->config ?? new IntegrationConfig(['system_name' => 'INACBGS']);
    }

    // =========================================================================
    // METHOD WS E-KLAIM
    // =========================================================================

    /** Buat klaim baru (registrasi nomor SEP/RM ke E-Klaim). */
    public function newClaim(array $data, ?string $claimId = null, ?string $visitId = null): array
    {
        return $this->callWs('new_claim', $data, $claimId, $visitId);
    }

    /** Kirim data lengkap klaim (diagnosa, prosedur, tarif, dll). */
    public function setClaimData(array $data, ?string $claimId = null, ?string $visitId = null): array
    {
        return $this->callWs('set_claim_data', $data, $claimId, $visitId);
    }

    /** Jalankan grouper INA-CBG -> kode CBG + tarif. */
    public function grouper(string $nomorSep, int $stage = 1, ?string $claimId = null, ?string $visitId = null): array
    {
        return $this->callWs('grouper', ['nomor_sep' => $nomorSep, 'stage' => $stage], $claimId, $visitId);
    }

    /** Finalisasi klaim (praktis irreversible — hanya dibuka via reedit_claim). */
    public function claimFinal(string $nomorSep, ?string $claimId = null, ?string $visitId = null): array
    {
        return $this->callWs('claim_final', ['nomor_sep' => $nomorSep], $claimId, $visitId);
    }

    /** Status klaim di E-Klaim (mis. cek apakah sudah final/grouped). */
    public function getClaimStatus(string $nomorSep, ?string $claimId = null, ?string $visitId = null): array
    {
        return $this->callWs('get_claim_status', ['nomor_sep' => $nomorSep], $claimId, $visitId);
    }

    /** Ambil data klaim utuh dari E-Klaim (sinkron balik). */
    public function getClaimData(string $nomorSep, ?string $claimId = null, ?string $visitId = null): array
    {
        return $this->callWs('get_claim_data', ['nomor_sep' => $nomorSep], $claimId, $visitId);
    }

    /** Buka kembali klaim yang sudah final untuk koreksi. */
    public function reeditClaim(string $nomorSep, ?string $claimId = null, ?string $visitId = null): array
    {
        return $this->callWs('reedit_claim', ['nomor_sep' => $nomorSep], $claimId, $visitId);
    }

    /** Hapus klaim di E-Klaim. */
    public function deleteClaim(string $nomorSep, ?string $claimId = null, ?string $visitId = null): array
    {
        return $this->callWs('delete_claim', ['nomor_sep' => $nomorSep], $claimId, $visitId);
    }

    // =========================================================================
    // TEST KONEKSI (dipanggil IntegrasiService::testKoneksi)
    // =========================================================================

    /**
     * Uji konektivitas + enkripsi ke ws.php.
     * Sukses = ws.php membalas dan respons bisa di-decrypt (key & layout benar).
     * Memakai metode baca get_claim_status dengan SEP dummy (tidak mengubah data).
     */
    public function testConnection(): array
    {
        $cfg = $this->boot();
        $url = $this->resolveUrl($cfg);

        try {
            EklaimCrypto::fromConfig($cfg->exists ? $cfg : null); // validasi key tersedia & valid
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'system'  => 'INACBGS',
                'message' => 'Konfigurasi enkripsi belum lengkap: ' . $e->getMessage(),
            ];
        }

        try {
            $res = $this->callWs('get_claim_status', ['nomor_sep' => '0000000000000000']);

            // ws.php membalas & terdekripsi -> koneksi + enkripsi OK, apa pun isi datanya
            // (umumnya "klaim tidak ditemukan" untuk SEP dummy).
            return [
                'success'  => true,
                'system'   => 'INACBGS',
                'ws_url'   => $url,
                'response_code' => $res['code'] ?? null,
                'message'  => 'Terhubung ke WS E-Klaim & respons berhasil didekripsi. '
                    . ($res['message'] ? "Pesan server: {$res['message']}" : ''),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'system'  => 'INACBGS',
                'ws_url'  => $url,
                'message' => $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // INTI — kirim ke ws.php (enkripsi -> POST -> dekripsi -> log)
    // =========================================================================

    /**
     * @return array{success:bool, code:?string, message:?string, data:mixed, raw:array}
     * @throws \RuntimeException bila jaringan/enkripsi/dekripsi gagal.
     */
    public function callWs(string $method, array $data, ?string $claimId = null, ?string $visitId = null): array
    {
        $cfg    = $this->boot();
        $url    = $this->resolveUrl($cfg);
        $crypto = EklaimCrypto::fromConfig($cfg->exists ? $cfg : null);

        // Struktur request WS E-Klaim: { metadata:{method}, data:{...} }.
        $requestArr = [
            'metadata' => ['method' => $method],
            'data'     => $data,
        ];
        $plaintext = json_encode($requestArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $log = [
            'method'        => $method,
            'request'       => $requestArr,
            'bpjs_claim_id' => $claimId,
            'visit_id'      => $visitId,
            'engine_type'   => 'WS',
        ];

        try {
            // Request dikirim TANPA pembungkus ----BEGIN/END---- (raw base64);
            // ws.php E-Klaim hanya mau men-decode payload tanpa marker.
            // Respons server justru BERmarker -> di-strip saat decrypt.
            $encrypted = $crypto->encrypt($plaintext, false);

            $http = Http::timeout($this->resolveTimeout($cfg))
                ->withOptions(['verify' => $this->resolveVerifySsl($cfg)])
                ->withHeaders(['Content-Type' => 'text/plain'])
                ->withBody($encrypted, 'text/plain')
                ->post($url);

            if ($http->failed()) {
                throw new \RuntimeException("WS E-Klaim HTTP {$http->status()} dari {$url}.");
            }

            $body = $http->body();
            if ($body === '') {
                throw new \RuntimeException('WS E-Klaim membalas kosong (cek method & format request).');
            }

            $decrypted = $crypto->decrypt($body);
            $parsed    = json_decode($decrypted, true);

            if (! is_array($parsed)) {
                throw new \RuntimeException('Respons WS E-Klaim bukan JSON valid setelah dekripsi.');
            }

            $code    = $parsed['metadata']['code'] ?? null;
            $message = $parsed['metadata']['message'] ?? null;
            // E-Klaim: code '1' = sukses, '0'/lainnya = gagal (tergantung method).
            $success = (string) $code === '1' || (string) $code === '200';

            $this->writeLog(array_merge($log, [
                'response'      => $parsed,
                'response_code' => $code !== null ? (string) $code : null,
                'message'       => $message,
                'status'        => $success ? 'SUCCESS' : 'FAILED',
            ]));

            return [
                'success' => $success,
                'code'    => $code !== null ? (string) $code : null,
                'message' => $message,
                'data'    => $parsed['response'] ?? $parsed['data'] ?? null,
                'raw'     => $parsed,
            ];
        } catch (\Throwable $e) {
            $this->writeLog(array_merge($log, [
                'status'        => 'FAILED',
                'error_message' => $e->getMessage(),
            ]));
            throw $e;
        }
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function writeLog(array $row): void
    {
        try {
            InacbgsGroupingLog::create($row);
        } catch (\Throwable $e) {
            // Audit log tidak boleh menjatuhkan alur klaim.
            report($e);
        }
    }

    private function resolveUrl(IntegrationConfig $cfg): string
    {
        return $cfg->base_url ?: (string) config('eklaim.ws_url');
    }

    private function resolveTimeout(IntegrationConfig $cfg): int
    {
        return (int) ($cfg->configuration['timeout'] ?? config('eklaim.timeout', 30));
    }

    private function resolveVerifySsl(IntegrationConfig $cfg): bool
    {
        return (bool) ($cfg->configuration['verify_ssl'] ?? config('eklaim.verify_ssl', false));
    }
}
