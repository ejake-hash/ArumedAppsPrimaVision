<?php

namespace App\Services\Bpjs;

use App\Models\IntegrationConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LZCompressor\LZString;

/**
 * BpjsClient — pondasi auth + HTTP + decrypt untuk SEMUA layanan BPJS
 * (VClaim, Antrean RS, iCare). DRY: signature/headers/decrypt ditulis sekali.
 *
 * Dipakai bersama oleh BpjsVClaimService & BpjsAntreanService — keduanya
 * cukup membentuk path + parse hasil, tidak mengulang logika auth.
 *
 * Spec auth (Docs/BRIDGING VCLAIM.md):
 *   Header: X-cons-id, X-timestamp (epoch UTC), X-signature (HMAC-SHA256),
 *           user_key. Signature = base64(hmac_sha256("{consId}&{timestamp}", secretKey)).
 *   Response VClaim: AES-256-CBC (key = sha256(consId.consSecret.timestamp)) lalu
 *           LZString::decompressFromEncodedURIComponent. Antrean RS: JSON polos.
 *
 * Prinsip: tidak pernah crash flow non-BPJS. Jika config belum aktif / credential
 * kosong → lempar \RuntimeException(503) yang jelas, caller yang menangani.
 */
class BpjsClient
{
    private ?IntegrationConfig $config = null;

    /** Timestamp request terakhir — dipakai membentuk key decrypt. */
    private string $lastTimestamp = '';

    /**
     * @param  string  $systemName  VCLAIM | ANTREAN | ICARE
     */
    public function __construct(private readonly string $systemName)
    {
        $this->config = IntegrationConfig::where('system_name', strtoupper($systemName))->first();
    }

    public static function for(string $systemName): self
    {
        return new self($systemName);
    }

    public function isEnabled(): bool
    {
        return ($this->config?->is_enabled ?? false) && ! empty($this->config?->credentials);
    }

    // =========================================================================
    // CREDENTIAL ACCESSORS
    // =========================================================================

    public function consId(): string
    {
        return (string) ($this->config->credentials['cons_id'] ?? '');
    }

    public function secretKey(): string
    {
        return (string) ($this->config->credentials['secret_key'] ?? $this->config->credentials['consumer_secret'] ?? '');
    }

    public function userKey(): string
    {
        return (string) ($this->config->credentials['user_key'] ?? '');
    }

    /**
     * Base URL + service name (path prefix layanan).
     *   VClaim   : https://.../vclaim-rest-dev
     *   Antrean  : https://.../antreanrs_dev
     * Service name disimpan di configuration.service_name (boleh kosong).
     */
    public function baseUrl(): string
    {
        $base    = rtrim((string) ($this->config->base_url ?? ''), '/');
        $service = trim((string) ($this->config->configuration['service_name'] ?? ''), '/');

        return $service ? "{$base}/{$service}" : $base;
    }

    /** Kode faskes (PPK) klinik — dipakai SEP/Antrean. */
    public function kodeFaskes(): string
    {
        return (string) ($this->config->configuration['kode_faskes'] ?? $this->config->credentials['kode_faskes'] ?? '');
    }

    // =========================================================================
    // SIGNATURE & HEADERS
    // =========================================================================

    /**
     * Generate signature HMAC-SHA256 sesuai spec BPJS.
     * value = "{consId}&{timestamp}" ; key = secretKey ; output = base64(raw hmac).
     */
    public function signature(string $timestamp): string
    {
        $value = $this->consId() . '&' . $timestamp;

        return base64_encode(hash_hmac('sha256', $value, $this->secretKey(), true));
    }

    /** Epoch UTC detik (time() di PHP sudah UTC-based). */
    public function timestamp(): string
    {
        return (string) time();
    }

    public function headers(string $timestamp): array
    {
        return [
            'X-cons-id'    => $this->consId(),
            'X-timestamp'  => $timestamp,
            'X-signature'  => $this->signature($timestamp),
            'user_key'     => $this->userKey(),
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept'       => 'application/json',
        ];
    }

    // =========================================================================
    // REQUEST
    // =========================================================================

    /**
     * Kirim request ke BPJS lalu kembalikan array hasil yang sudah dinormalisasi:
     *   [ 'metaData' => [...], 'response' => <decoded>, 'http_status' => int,
     *     'is_success' => bool, 'raw' => string ]
     *
     * @param  string  $method     GET|POST|PUT|DELETE
     * @param  string  $path        path relatif (diawali "/") setelah baseUrl()
     * @param  array|null  $body     body untuk POST/PUT/DELETE
     * @param  bool  $encrypted     true → response VClaim (AES + LZString); false → JSON polos (Antrean)
     */
    public function request(string $method, string $path, ?array $body = null, bool $encrypted = true): array
    {
        $this->assertEnabled();

        $timestamp           = $this->timestamp();
        $this->lastTimestamp = $timestamp;

        $url = $this->baseUrl() . '/' . ltrim($path, '/');

        $http = Http::withHeaders($this->headers($timestamp))
            ->timeout((int) ($this->config->configuration['timeout'] ?? 30));

        $method = strtoupper($method);

        try {
            $resp = match ($method) {
                'GET'    => $http->get($url),
                'POST'   => $http->withBody(json_encode($body ?? []), 'application/json')->post($url),
                'PUT'    => $http->withBody(json_encode($body ?? []), 'application/json')->put($url),
                'DELETE' => $http->withBody(json_encode($body ?? []), 'application/json')->delete($url),
                default  => throw new \InvalidArgumentException("Method tidak didukung: {$method}"),
            };
        } catch (\Throwable $e) {
            return [
                'metaData'    => ['code' => '0', 'message' => 'Koneksi BPJS gagal: ' . $e->getMessage()],
                'response'    => null,
                'http_status' => 0,
                'is_success'  => false,
                'raw'         => '',
            ];
        }

        return $this->parse($resp, $timestamp, $encrypted);
    }

    /**
     * Parse response BPJS: pisahkan metaData & response, decrypt jika perlu.
     * BPJS membungkus payload di { metaData, response }. response bisa berupa
     * string terenkripsi (VClaim) atau objek/array (Antrean).
     */
    private function parse(Response $resp, string $timestamp, bool $encrypted): array
    {
        $raw    = $resp->body();
        $status = $resp->status();
        $json   = json_decode($raw, true);

        // Antrean / response yang non-JSON-envelope: kembalikan apa adanya.
        if (! is_array($json)) {
            return [
                'metaData'    => ['code' => (string) $status, 'message' => $raw],
                'response'    => null,
                'http_status' => $status,
                'is_success'  => $resp->successful(),
                'raw'         => $raw,
            ];
        }

        $meta     = $json['metaData'] ?? ['code' => (string) $status, 'message' => $resp->reason()];
        $response = $json['response'] ?? null;
        $metaCode = (string) ($meta['code'] ?? $status);

        // VClaim: response berupa string terenkripsi → decrypt + dekompres.
        if ($encrypted && is_string($response) && $response !== '' && $metaCode === '200') {
            $decoded  = $this->decrypt($response, $timestamp);
            $response = $decoded !== null ? (json_decode($decoded, true) ?? $decoded) : $response;
        }

        return [
            'metaData'    => $meta,
            'response'    => $response,
            'http_status' => $status,
            'is_success'  => $metaCode === '200',
            'raw'         => $raw,
        ];
    }

    // =========================================================================
    // DECRYPT (AES-256-CBC + LZ-String) — sesuai contoh spec BPJS
    // =========================================================================

    /**
     * Decrypt response VClaim.
     *   key   = consId + consSecret + timestamp (concatenate)
     *   step1 = AES-256-CBC decrypt (key_hash = sha256(key) hex2bin, iv = 16 byte pertama)
     *   step2 = LZString::decompressFromEncodedURIComponent
     */
    public function decrypt(string $payload, ?string $timestamp = null): ?string
    {
        $timestamp ??= $this->lastTimestamp;
        $key = $this->consId() . $this->secretKey() . $timestamp;

        $keyHash = hex2bin(hash('sha256', $key));
        $iv      = substr($keyHash, 0, 16);

        $decrypted = openssl_decrypt(base64_decode($payload), 'AES-256-CBC', $keyHash, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            return null;
        }

        return LZString::decompressFromEncodedURIComponent($decrypted);
    }

    // =========================================================================
    // GUARD
    // =========================================================================

    private function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new \RuntimeException(
                "Integrasi {$this->systemName} belum diaktifkan. Lengkapi credentials lalu aktifkan di menu Integrasi.",
                503
            );
        }
    }
}
