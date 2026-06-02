<?php

namespace App\Services\Eklaim;

/**
 * EklaimCrypto — enkripsi/dekripsi payload Web Service E-Klaim INA-CBG.
 *
 * Skema RESMI E-Klaim (inacbg_encrypt / inacbg_decrypt), terverifikasi dari
 * dokumentasi WS INA-CBG:
 *   - AES-256-CBC, kunci 32 byte (Encryption Key 64 hex -> hex2bin).
 *   - IV acak 16 byte.
 *   - Signature = 10 BYTE PERTAMA dari HMAC-SHA256(ciphertext, key).
 *   - Output = chunk_split( base64( SIGNATURE[10] . IV[16] . CIPHERTEXT ) ).
 *   - Respons dari ws.php dibungkus penanda ----BEGIN/END ENCRYPTED DATA----
 *     yang HARUS dibuang sebelum base64_decode.
 *
 * CATATAN: urutan byte di sini TETAP (sesuai spesifikasi resmi) — bukan lagi
 * configurable. Key WAJIB hex 64 karakter.
 */
class EklaimCrypto
{
    private const CIPHER  = 'aes-256-cbc';
    private const IV_LEN  = 16;
    private const SIG_LEN = 10; // 10 byte pertama HMAC-SHA256 (spesifikasi E-Klaim)

    private const MARK_BEGIN = '----BEGIN ENCRYPTED DATA----';
    private const MARK_END   = '----END ENCRYPTED DATA----';

    private string $key; // 32 byte raw

    /**
     * @param  string  $rawKey    Encryption Key (64 hex).
     * @param  string  $encoding  'hex' (default, sesuai E-Klaim) | 'base64' | 'raw'.
     */
    public function __construct(string $rawKey, string $encoding = 'hex')
    {
        $this->key = $this->decodeKey($rawKey, $encoding);

        if (strlen($this->key) !== 32) {
            throw new \InvalidArgumentException(
                'Encryption Key E-Klaim harus 32 byte (AES-256). Dapat ' . strlen($this->key) . ' byte — cek nilai & encoding (hex/base64).'
            );
        }
    }

    /**
     * Buat instance dari IntegrationConfig (UI) dengan fallback ke config/.env.
     */
    public static function fromConfig(?\App\Models\IntegrationConfig $cfg = null): self
    {
        $key = $cfg?->credentials['encryption_key'] ?? null;
        $key = $key ?: (string) config('eklaim.key');

        if ($key === '') {
            throw new \RuntimeException('Encryption Key E-Klaim belum diisi (UI Bridging atau EKLAIM_KEY di .env).');
        }

        $encoding = $cfg?->configuration['key_encoding'] ?? config('eklaim.key_encoding', 'hex');

        return new self($key, $encoding);
    }

    /**
     * Enkripsi plaintext (JSON) -> payload base64 berbungkus penanda, siap kirim
     * ke ws.php. Format: base64( SIG[10] . IV[16] . CIPHERTEXT ).
     *
     * @param  bool  $wrap  Bungkus dengan ----BEGIN/END ENCRYPTED DATA---- (default true).
     */
    public function encrypt(string $plaintext, bool $wrap = true): string
    {
        $iv = random_bytes(self::IV_LEN);

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('Enkripsi E-Klaim gagal: ' . openssl_error_string());
        }

        $signature = substr(hash_hmac('sha256', $ciphertext, $this->key, true), 0, self::SIG_LEN);

        $b64 = base64_encode($signature . $iv . $ciphertext);

        if (! $wrap) {
            return $b64;
        }

        return self::MARK_BEGIN . "\n" . chunk_split($b64) . self::MARK_END;
    }

    /**
     * Dekripsi payload dari ws.php -> plaintext. Verifikasi signature dulu.
     */
    public function decrypt(string $payload): string
    {
        $decoded = base64_decode($this->stripMarkers($payload), true);
        if ($decoded === false) {
            throw new \RuntimeException('Payload E-Klaim bukan base64 valid.');
        }

        if (strlen($decoded) < self::SIG_LEN + self::IV_LEN + 1) {
            throw new \RuntimeException('Payload E-Klaim terlalu pendek (signature/IV/ciphertext tak lengkap).');
        }

        $signature  = substr($decoded, 0, self::SIG_LEN);
        $iv         = substr($decoded, self::SIG_LEN, self::IV_LEN);
        $ciphertext = substr($decoded, self::SIG_LEN + self::IV_LEN);

        $calc = substr(hash_hmac('sha256', $ciphertext, $this->key, true), 0, self::SIG_LEN);
        if (! hash_equals($calc, $signature)) {
            throw new \RuntimeException('Signature E-Klaim tidak cocok — Encryption Key salah.');
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new \RuntimeException('Dekripsi E-Klaim gagal: ' . openssl_error_string());
        }

        return $plaintext;
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function decodeKey(string $raw, string $encoding): string
    {
        $raw = trim($raw);

        return match (strtolower($encoding)) {
            'hex'    => $this->fromHex($raw),
            'base64' => (string) base64_decode($raw, true),
            default  => $raw, // 'raw'
        };
    }

    private function fromHex(string $hex): string
    {
        if (! ctype_xdigit($hex) || strlen($hex) % 2 !== 0) {
            throw new \InvalidArgumentException('Encryption Key bukan hex valid (harus 64 karakter 0-9a-f).');
        }

        return hex2bin($hex);
    }

    /**
     * Buang penanda ----BEGIN/END ENCRYPTED DATA---- + whitespace dari payload
     * ws.php sehingga tersisa base64 murni. Toleran bila penanda tak ada.
     */
    private function stripMarkers(string $payload): string
    {
        $payload = str_replace([self::MARK_BEGIN, self::MARK_END], '', $payload);

        return preg_replace('/[^A-Za-z0-9+\/=]/', '', $payload);
    }
}
