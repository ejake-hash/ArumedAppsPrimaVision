<?php

namespace App\Support;

/**
 * Netralisir CSV/XLSX FORMULA INJECTION (CWE-1236). Sel yang diawali karakter
 * = + - @ (atau TAB/CR) dapat dieksekusi Excel/Google Sheets sebagai FORMULA saat
 * file dibuka — mis. nama pasien "=cmd|'/c calc'!A1" yang di-input lewat pendaftaran
 * lalu diekspor ke laporan. Prefiks tanda kutip tunggal → dipaksa jadi teks literal.
 *
 * Pakai untuk SEMUA nilai teks berasal-user pada ekspor CSV/XLSX (nama pasien/dokter,
 * penjamin, diagnosa, alamat, dsb). Nilai numerik/tanggal yang dibangun sistem aman.
 */
final class CsvGuard
{
    public static function cell(mixed $value): string
    {
        $s = (string) ($value ?? '');
        // Butuh operator DI AWAL + konten sesudahnya agar jadi formula. Karakter operator
        // tunggal (mis. placeholder null '-') aman & TIDAK di-prefix (hindari garbling
        // '- jadi "'-" di ekspor). String kosong juga apa adanya.
        if (strlen($s) > 1 && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        return $s;
    }
}
