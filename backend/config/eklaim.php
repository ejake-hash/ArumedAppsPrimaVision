<?php

/*
|--------------------------------------------------------------------------
| Integrasi Web Service E-Klaim INA-CBG (build 5.10.x)
|--------------------------------------------------------------------------
|
| Konfigurasi klien Web Service ke aplikasi E-Klaim INA-CBG lokal
| (ws.php) lewat enkripsi AES-256-CBC + HMAC-SHA256.
|
| CATATAN PENTING tentang sumber kredensial:
|   Nilai di sini = FALLBACK dari .env. Sumber utama saat runtime adalah
|   IntegrationConfig (system_name = 'INACBGS') yang diisi lewat UI Bridging:
|     - base_url           -> URL ws.php
|     - credentials.encryption_key -> Encryption Key (64 hex)
|     - configuration.{kode_tarif, key_encoding, verify_ssl, timeout}
|   InaCbgsService mengutamakan IntegrationConfig, jatuh ke nilai .env ini
|   bila kosong. Jadi EKLAIM_KEY di .env bersifat opsional.
|
| Encryption Key JANGAN di-commit. Isi lewat UI Bridging (tersimpan
| terenkripsi di kolom credentials) atau .env yang tidak ditrack git.
|
*/

return [

    // URL endpoint Web Service E-Klaim (ws.php).
    'ws_url' => env('EKLAIM_WS_URL', 'http://192.168.100.19/E-Klaim/ws.php'),

    // Encryption Key dari menu E-Klaim: Setup -> Integrasi SIMRS.
    // 64 karakter hex = 32 byte (AES-256). Fallback bila UI belum diisi.
    'key' => env('EKLAIM_KEY', ''),

    // Encoding key: 'hex' (default, sesuai E-Klaim) atau 'base64' / 'raw'.
    'key_encoding' => env('EKLAIM_KEY_ENCODING', 'hex'),

    // Kode tarif INA-CBG faskes (Setup Institusi). Prima Vision = 'CS'.
    'kode_tarif' => env('EKLAIM_KODE_TARIF', 'CS'),

    // Penjamin & koder default untuk set_claim_data (terverifikasi dari
    // get_claim_data: payor_id '3'=JKN, coder_nik faskes).
    'payor_id'  => env('EKLAIM_PAYOR_ID', '3'),
    'payor_cd'  => env('EKLAIM_PAYOR_CD', 'JKN'),
    'coder_nik' => env('EKLAIM_CODER_NIK', '00001'),

    // Nama method WS untuk "Kirim Klaim Online" (dorong klaim final ke Pusat
    // Data Kemenkes/BPJS). BELUM diverifikasi dari WS live (operasi tulis ke
    // produksi tak boleh ditebak-fuzz). Konfirmasi dari Manual WS / 1x uji
    // terkontrol, lalu set EKLAIM_SEND_METHOD bila berbeda.
    'send_method' => env('EKLAIM_SEND_METHOD', 'send_claim'),

    // Timeout HTTP (detik).
    'timeout' => (int) env('EKLAIM_TIMEOUT', 30),

    // Verifikasi SSL. Default false: WS diakses via HTTP LAN biasa.
    'verify_ssl' => filter_var(env('EKLAIM_VERIFY_SSL', false), FILTER_VALIDATE_BOOLEAN),

    // Catatan: skema enkripsi E-Klaim TETAP (SIG[10].IV[16].CIPHERTEXT, lihat
    // EklaimCrypto) — tidak ada opsi urutan byte. Request dikirim TANPA marker,
    // respons server BERmarker (di-strip otomatis).
];
