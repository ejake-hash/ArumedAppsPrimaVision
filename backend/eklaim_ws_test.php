<?php
/**
 * ============================================================================
 * Uji mandiri Web Service E-Klaim INA-CBG (tanpa Laravel)
 * ============================================================================
 *
 *     php eklaim_ws_test.php
 *
 * Skema enkripsi RESMI E-Klaim (sudah TERVERIFIKASI terhadap server ini):
 *   payload = base64( SIGNATURE[10] . IV[16] . CIPHERTEXT )
 *   SIGNATURE = 10 byte pertama HMAC-SHA256(ciphertext, key)
 *   AES-256-CBC, key = hex2bin(64 hex).
 *   REQUEST dikirim TANPA pembungkus ----BEGIN/END----.
 *   RESPONS server BERmarker -> di-strip sebelum base64_decode.
 *
 * Edit $KEY_HEX, lalu jalankan. Hapus file ini setelah selesai (berisi key).
 */

// ====== EDIT DI SINI ========================================================
$KEY_HEX = 'GANTI_DENGAN_ENCRYPTION_KEY_64_HEX';
$WS_URL  = 'http://192.168.100.19/E-Klaim/ws.php';
// ============================================================================

const CIPHER = 'aes-256-cbc';
const SIG_LEN = 10;
const IV_LEN  = 16;

function loadKey(string $hex): string
{
    $hex = trim($hex);
    if (! ctype_xdigit($hex) || strlen($hex) !== 64) {
        fwrite(STDERR, "FATAL: KEY_HEX harus 64 hex (32 byte). Sekarang: " . strlen($hex) . " char.\n");
        exit(1);
    }
    return hex2bin($hex);
}

function enc(string $plaintext, string $key): string
{
    $iv  = random_bytes(IV_LEN);
    $ct  = openssl_encrypt($plaintext, CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    $sig = substr(hash_hmac('sha256', $ct, $key, true), 0, SIG_LEN);
    return base64_encode($sig . $iv . $ct); // TANPA marker (request)
}

function dec(string $payload, string $key): ?string
{
    $clean = preg_replace('/[^A-Za-z0-9+\/=]/', '',
        str_replace(['----BEGIN ENCRYPTED DATA----', '----END ENCRYPTED DATA----'], '', $payload));
    $raw = base64_decode($clean, true);
    if ($raw === false || strlen($raw) < SIG_LEN + IV_LEN + 1) return null;

    $sig = substr($raw, 0, SIG_LEN);
    $iv  = substr($raw, SIG_LEN, IV_LEN);
    $ct  = substr($raw, SIG_LEN + IV_LEN);

    $calc = substr(hash_hmac('sha256', $ct, $key, true), 0, SIG_LEN);
    if (! hash_equals($calc, $sig)) return null;

    $pt = openssl_decrypt($ct, CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    return $pt === false ? null : $pt;
}

$key = loadKey($KEY_HEX);

// Self-test lokal
$sample = '{"ping":1}';
echo "Self-test crypto lokal: " . (dec(enc($sample, $key), $key) === $sample ? "OK\n" : "GAGAL\n");

// Kirim get_claim_status (SEP dummy) — request TANPA marker
$request = json_encode([
    'metadata' => ['method' => 'get_claim_status'],
    'data'     => ['nomor_sep' => '0000000000000000'],
]);

$ch = curl_init($WS_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => enc($request, $key),
    CURLOPT_TIMEOUT        => 15,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($err) { echo "cURL error: $err\n"; exit(1); }

$plain = dec($resp, $key);
if ($plain !== null) {
    echo "✅ BERHASIL terhubung & dekripsi.\nRespons server:\n$plain\n";
    echo "\n(Jika pesan 'SEP kosong'/'tidak ditemukan' = NORMAL untuk SEP dummy.\n";
    echo " Yang penting respons terdekripsi -> enkripsi & transport BENAR.)\n";
} else {
    echo "❌ Gagal dekripsi respons. Cek Encryption Key benar (64 hex).\n";
    echo "Respons mentah (200 byte): " . substr((string) $resp, 0, 200) . "\n";
}
