<?php

namespace App\Services;

use App\Models\IntegrationConfig;

/**
 * Token untuk WS Antrean sisi RS (dipanggil Mobile JKN / server BPJS).
 *
 * Spec Antrol.md:682-701 — BPJS hit endpoint Token RS dengan header x-username +
 * x-password → RS balas { response: { token } }. Endpoint lain dipanggil dengan
 * x-token + x-username.
 *
 * Implementasi STATELESS (tanpa tabel): token = base64url(payload) . "." . sig,
 *   payload = { u: username, exp: epoch }, sig = hmac_sha256(payload, secret).
 * Secret & kredensial diambil dari IntegrationConfig ANTREAN.credentials
 * (terenkripsi at-rest). Tidak ada call keluar — murni lokal.
 */
class AntrolTokenService
{
    /** Masa berlaku token (detik). BPJS umumnya minta token sekali pakai per sesi singkat. */
    private const TTL_SECONDS = 3600;

    private ?IntegrationConfig $config;

    public function __construct()
    {
        $this->config = IntegrationConfig::where('system_name', 'ANTREAN')->first();
    }

    private function creds(): array
    {
        return $this->config?->credentials ?? [];
    }

    /** Username yang diberikan ke BPJS untuk mengakses WS RS. */
    public function username(): string
    {
        return (string) ($this->creds()['mobilejkn_username'] ?? '');
    }

    public function password(): string
    {
        return (string) ($this->creds()['mobilejkn_password'] ?? '');
    }

    /**
     * Secret penanda-tangan token. Pakai secret khusus bila diisi, jika tidak
     * fallback ke APP_KEY (selalu ada) supaya tetap aman walau belum dikonfigurasi.
     */
    private function secret(): string
    {
        $s = (string) ($this->creds()['mobilejkn_token_secret'] ?? '');

        return $s !== '' ? $s : (string) config('app.key');
    }

    /** Apakah kredensial Mobile JKN sudah lengkap (username & password terisi). */
    public function isConfigured(): bool
    {
        return $this->username() !== '' && $this->password() !== '';
    }

    /**
     * Verifikasi kredensial dari header Token (x-username + x-password).
     * Pakai hash_equals untuk konstanta waktu (anti timing attack).
     */
    public function verifyCredentials(?string $username, ?string $password): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        return hash_equals($this->username(), (string) $username)
            && hash_equals($this->password(), (string) $password);
    }

    /** Terbitkan token untuk username yang valid. */
    public function issue(string $username, ?int $now = null): string
    {
        $now     = $now ?? time();
        $payload = $this->b64url(json_encode(['u' => $username, 'exp' => $now + self::TTL_SECONDS]));
        $sig     = $this->sign($payload);

        return "{$payload}.{$sig}";
    }

    /**
     * Validasi token (x-token) terhadap username (x-username).
     * Cek: format, signature cocok, belum kedaluwarsa, username sama.
     */
    public function validate(?string $token, ?string $username, ?int $now = null): bool
    {
        $now = $now ?? time();

        if (! $token || ! str_contains($token, '.')) {
            return false;
        }

        [$payload, $sig] = explode('.', $token, 2);

        if (! hash_equals($this->sign($payload), (string) $sig)) {
            return false;
        }

        $data = json_decode($this->b64urlDecode($payload), true);
        if (! is_array($data) || empty($data['u']) || empty($data['exp'])) {
            return false;
        }

        if ((int) $data['exp'] < $now) {
            return false; // kedaluwarsa
        }

        return hash_equals((string) $data['u'], (string) $username);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function sign(string $payload): string
    {
        return $this->b64url(hash_hmac('sha256', $payload, $this->secret(), true));
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
