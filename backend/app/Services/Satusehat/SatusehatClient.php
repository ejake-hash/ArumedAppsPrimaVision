<?php

namespace App\Services\Satusehat;

use App\Models\IntegrationConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * SatusehatClient — pondasi auth (OAuth2 client_credentials) + HTTP FHIR R4
 * untuk integrasi Satu Sehat (Kemenkes). DRY: token caching + base URL per-env
 * + Bearer injection ditulis sekali, dipakai SatusehatService.
 *
 * Pola dirancang menyerupai App\Services\Bpjs\BpjsClient:
 *   - credentials & configuration dibaca dari row IntegrationConfig 'SATUSEHAT'.
 *   - isEnabled() = is_enabled && client_id/secret non-empty.
 *   - belum aktif / credential kosong → \RuntimeException(503) yang jelas.
 *
 * Auth (Docs/PLAN-BRIDGING-SATUSEHAT-ARUMED.md §1):
 *   POST {oauth}/accesstoken?grant_type=client_credentials
 *   body form-urlencoded: client_id, client_secret → { access_token, expires_in }.
 *   Semua FHIR call: header Authorization: Bearer <token>.
 *
 * Kredensial TIDAK di-hardcode/seed — admin isi via UI Konfigurasi Bridging.
 */
class SatusehatClient
{
    private ?IntegrationConfig $config = null;

    public function __construct()
    {
        $this->config = IntegrationConfig::where('system_name', 'SATUSEHAT')->first();
    }

    public static function make(): self
    {
        return new self();
    }

    public function isEnabled(): bool
    {
        return ($this->config?->is_enabled ?? false) && $this->hasCredentials();
    }

    /** Kredensial minimal terisi (tanpa cek toggle is_enabled) — dipakai Test Koneksi. */
    public function hasCredentials(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    // =========================================================================
    // CREDENTIAL / CONFIG ACCESSORS
    // =========================================================================

    public function clientId(): string
    {
        return (string) ($this->config->credentials['client_id'] ?? '');
    }

    public function clientSecret(): string
    {
        return (string) ($this->config->credentials['client_secret'] ?? '');
    }

    /** Organization ID — boleh di credentials atau configuration. */
    public function organizationId(): string
    {
        return (string) (
            $this->config->credentials['organization_id']
            ?? $this->config->configuration['organization_id']
            ?? ''
        );
    }

    /** Location ID (ruang/poli dari dashboard Satu Sehat) — opsional. */
    public function locationId(): string
    {
        return (string) (
            $this->config->credentials['location_id']
            ?? $this->config->configuration['location_id']
            ?? ''
        );
    }

    /**
     * sandbox | production — menentukan host resmi Kemenkes di host().
     * Default 'sandbox' SENGAJA: hanya kena bila config belum pernah disimpan;
     * instance yang belum dikonfigurasi tidak boleh diam-diam menembak endpoint
     * produksi. UI Konfigurasi menulis 'production' saat admin menyimpan.
     */
    public function env(): string
    {
        return strtolower((string) ($this->config->configuration['env'] ?? 'sandbox'));
    }

    private function timeout(): int
    {
        return (int) ($this->config->configuration['timeout'] ?? 30);
    }

    // =========================================================================
    // BASE URLS (per-env)
    // =========================================================================

    /** Host resmi Kemenkes per-env. */
    private const HOST_PRODUCTION = 'https://api-satusehat.kemkes.go.id';
    private const HOST_SANDBOX    = 'https://api-satusehat-stg.dto.kemkes.go.id';

    /**
     * base_url di IntegrationConfig adalah HOST (tanpa /oauth2 atau /fhir-r4).
     * oauthBase()/fhirBase() menempelkan suffix versi resmi Kemenkes.
     *
     * RESOLUSI: env() menentukan host resmi. base_url tersimpan HANYA dipakai
     * bila benar-benar custom (mis. proxy RS) — bukan salah satu host Kemenkes
     * baku. Ini mencegah base_url lama yang ter-seed ke staging "menang" atas
     * env=production (regresi: UI memaksa production tapi call tetap ke staging).
     */
    private function host(): string
    {
        $envHost = $this->env() === 'production' ? self::HOST_PRODUCTION : self::HOST_SANDBOX;

        $base = trim((string) ($this->config->base_url ?? ''), '/');
        if ($base === '') {
            return $envHost;
        }

        // base_url yang sama dengan salah satu host Kemenkes baku → abaikan,
        // biarkan env() yang menentukan. Hanya host non-Kemenkes (proxy) yang dihormati.
        $known = [self::HOST_PRODUCTION, self::HOST_SANDBOX];
        if (in_array($base, $known, true)) {
            return $envHost;
        }

        return $base;
    }

    public function oauthBase(): string
    {
        return $this->host() . '/oauth2/v1';
    }

    public function fhirBase(): string
    {
        return $this->host() . '/fhir-r4/v1';
    }

    // =========================================================================
    // OAUTH TOKEN
    // =========================================================================

    /**
     * Ambil access token (cached). TTL = expires_in − 60s untuk aman dari clock skew.
     * Cache key di-scope per-clientId agar ganti kredensial tidak pakai token lama.
     */
    public function getAccessToken(): string
    {
        // Token cukup butuh kredensial (client_credentials) — toggle is_enabled
        // menggate SYNC data, bukan penerbitan token. Memungkinkan Test Koneksi &
        // Cari KFA sebelum integrasi diaktifkan. FHIR sync tetap digate via
        // assertEnabled() di fhir().
        $this->assertHasCredentials();

        $cacheKey = 'satusehat:token:' . substr(sha1($this->clientId() . '|' . $this->env()), 0, 16);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $info  = $this->fetchTokenInfo();
        $token = (string) ($info['json']['access_token'] ?? '');

        if ($token === '') {
            $msg = $info['json']['error_description'] ?? $info['json']['error'] ?? $info['raw'];
            throw new \RuntimeException('Gagal ambil token Satu Sehat: ' . $msg, 502);
        }

        $ttl = max(60, ((int) ($info['json']['expires_in'] ?? 3599)) - 60);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /**
     * Test koneksi: ambil token + cek status approved (Kemenkes mengembalikan
     * status:"approved" pada response accesstoken). Tidak dicache agar selalu live.
     */
    public function fetchTokenInfo(): array
    {
        // Hanya butuh kredensial — agar admin bisa Test SEBELUM Aktifkan (toggle off).
        $this->assertHasCredentials();

        $resp = Http::asForm()
            ->timeout($this->timeout())
            ->post($this->oauthBase() . '/accesstoken?grant_type=client_credentials', [
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
            ]);

        return [
            'http_status' => $resp->status(),
            'json'        => is_array($resp->json()) ? $resp->json() : [],
            'raw'         => $resp->body(),
            'successful'  => $resp->successful(),
        ];
    }

    // =========================================================================
    // KFA (Kamus Farmasi & Alkes) — master obat nasional, di luar /fhir-r4
    // =========================================================================

    /**
     * Cari produk KFA. Endpoint ada di HOST (bukan /fhir-r4): {host}/kfa-v2/products/all.
     * Return: [ 'is_success' => bool, 'http_status' => int, 'items' => array<{name,kfa_code,...}> ].
     *
     * @param  string  $keyword  kata kunci nama obat / nama dagang
     */
    public function kfaSearch(string $keyword, int $size = 15): array
    {
        // Cari KFA = prasyarat isi kfa_code obat SEBELUM sync → cukup kredensial,
        // tidak perlu toggle is_enabled (konsisten dgn fetchTokenInfo).
        $this->assertHasCredentials();

        try {
            $resp = Http::withToken($this->getAccessToken())
                ->timeout($this->timeout())
                ->acceptJson()
                ->get($this->host() . '/kfa-v2/products/all', [
                    'product_type' => 'farmasi',
                    'keyword'      => $keyword,
                    'page'         => 1,
                    'size'         => $size,
                ]);
        } catch (\Throwable $e) {
            return ['is_success' => false, 'http_status' => 0, 'items' => [], 'error' => $e->getMessage()];
        }

        $json  = $resp->json();
        $items = $json['items']['data'] ?? [];

        return [
            'is_success'  => $resp->successful(),
            'http_status' => $resp->status(),
            'items'       => is_array($items) ? $items : [],
        ];
    }

    // =========================================================================
    // FHIR REQUEST
    // =========================================================================

    public function get(string $path, array $query = []): array
    {
        return $this->fhir('GET', $path, null, $query);
    }

    public function post(string $path, array $payload): array
    {
        return $this->fhir('POST', $path, $payload);
    }

    public function put(string $path, array $payload): array
    {
        return $this->fhir('PUT', $path, $payload);
    }

    /**
     * Kirim FHIR request → kembalikan array ternormalisasi:
     *   [ 'http_status' => int, 'is_success' => bool, 'response' => <decoded>, 'raw' => string ]
     */
    public function fhir(string $method, string $path, ?array $payload = null, array $query = []): array
    {
        $this->assertEnabled();

        $url  = $this->fhirBase() . '/' . ltrim($path, '/');
        $http = Http::withToken($this->getAccessToken())
            ->timeout($this->timeout())
            ->acceptJson();

        try {
            $resp = match (strtoupper($method)) {
                'GET'  => $http->get($url, $query),
                'POST' => $http->post($url, $payload ?? []),
                'PUT'  => $http->put($url, $payload ?? []),
                default => throw new \InvalidArgumentException("Method tidak didukung: {$method}"),
            };
        } catch (\Throwable $e) {
            return [
                'http_status' => 0,
                'is_success'  => false,
                'response'    => null,
                'raw'         => '',
                'error'       => 'Koneksi Satu Sehat gagal: ' . $e->getMessage(),
            ];
        }

        return $this->parse($resp);
    }

    private function parse(Response $resp): array
    {
        $json = $resp->json();

        return [
            'http_status' => $resp->status(),
            'is_success'  => $resp->successful(),
            'response'    => is_array($json) ? $json : null,
            'raw'         => $resp->body(),
        ];
    }

    // =========================================================================
    // GUARD
    // =========================================================================

    private function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new \RuntimeException(
                'Integrasi SATUSEHAT belum diaktifkan. Lengkapi Client ID & Client Secret lalu aktifkan di menu Bridging → Konfigurasi.',
                503
            );
        }
    }

    /** Guard ringan untuk Test Koneksi: kredensial wajib, toggle is_enabled tidak. */
    private function assertHasCredentials(): void
    {
        if (! $this->hasCredentials()) {
            throw new \RuntimeException(
                'Client ID & Client Secret Satu Sehat belum diisi. Lengkapi di menu Bridging → Konfigurasi lalu klik Test.',
                503
            );
        }
    }
}
