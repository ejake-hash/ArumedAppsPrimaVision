<?php

namespace App\Services\Bpjs;

/**
 * BpjsRmCrypto — enkripsi field `dataMR` untuk WS Rekam Medis BPJS
 * (eclaim/rekammedis/insert).
 *
 * Per dokumen TrustMark "Insert Rekam Medis":
 *   1. FHIR Bundle (JSON string)
 *   2. compress gZip
 *   3. encrypt AES dengan key = consId + secretKey + koders (kode RS / PPK)
 *   4. base64 encode
 *
 * PENTING — key BERBEDA dari dekripsi response v2 di {@see BpjsClient::decrypt}
 * (yang memakai consId+secret+TIMESTAMP). Di sini koders = kode faskes (statis),
 * BUKAN timestamp.
 *
 * Catatan UAT: dokumen hanya menyebut "gZip" + "encrypt" tanpa rincian mode AES.
 * Mengikuti konvensi v2 BPJS: AES-256-CBC, keyHash = hex2bin(sha256(key)),
 * IV = 16 byte pertama keyHash. Bila BPJS menolak, coba varian gzcompress/gzdeflate
 * via konstanta GZIP_MODE.
 */
class BpjsRmCrypto
{
    /** 'gzencode' (gzip header, default) | 'gzcompress' (zlib) | 'gzdeflate' (raw). */
    private const GZIP_MODE = 'gzencode';

    /**
     * Hasilkan string dataMR siap kirim (base64).
     */
    public static function encryptDataMr(array $bundle, string $consId, string $secretKey, string $koders): string
    {
        $json = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $compressed = match (self::GZIP_MODE) {
            'gzcompress' => gzcompress($json, 9),
            'gzdeflate'  => gzdeflate($json, 9),
            default      => gzencode($json, 9),
        };

        $key     = $consId . $secretKey . $koders;
        $keyHash = hex2bin(hash('sha256', $key));
        $iv      = substr($keyHash, 0, 16);

        $encrypted = openssl_encrypt($compressed, 'AES-256-CBC', $keyHash, OPENSSL_RAW_DATA, $iv);

        return base64_encode($encrypted);
    }
}
