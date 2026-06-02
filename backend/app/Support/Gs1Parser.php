<?php

namespace App\Support;

/**
 * Parser GS1 Application Identifier (AI) untuk barcode UDI alat medis (IOL).
 *
 * Dipakai untuk membaca DataMatrix GS1/UDI pada label lensa intraokular
 * (mis. Alcon AcrySof). Hasil scan kamera (zxing) berupa string mentah yang
 * mengandung beberapa AI berturut-turut; helper ini mengekstraknya menjadi
 * {gtin, lot_number, serial_number, expiry_date}.
 *
 * AI yang didukung (4 wajib untuk implan):
 *   (01) GTIN-14            — fixed 14 digit
 *   (17) Expiry YYMMDD      — fixed 6 digit
 *   (10) Lot/Batch          — variable, diakhiri FNC1
 *   (21) Serial Number      — variable, diakhiri FNC1
 *
 * FNC1 = ASCII 29 (\x1d) — pemisah untuk AI variable-length. Banyak scanner
 * mengirimkannya; sebagian tidak. Parser ini toleran: untuk AI variable-length,
 * baca sampai FNC1 ATAU sampai prefix AI berikutnya yang dikenal ATAU akhir string.
 *
 * Catatan: parser ditulis sendiri (zero-dependency) karena hanya butuh 4 AI;
 * tidak menarik library GS1 penuh. Lihat plan IOL barcode scan.
 */
class Gs1Parser
{
    public const FNC1 = "\x1d";

    /** AI dengan panjang data tetap (di luar 2-digit prefix). */
    private const FIXED_AI = [
        '01' => 14, // GTIN-14
        '17' => 6,  // Expiry YYMMDD
        '11' => 6,  // Production date YYMMDD (jaga-jaga, agar tak salah baca sbg variable)
        '15' => 6,  // Best-before YYMMDD
    ];

    /** AI variable-length yang kita pedulikan. */
    private const VAR_AI = ['10', '21', '240', '30'];

    /** Semua prefix AI yang dikenali untuk deteksi batas AI variable-length. */
    private const KNOWN_AI = ['01', '17', '11', '15', '10', '21', '240', '30'];

    /**
     * Parse string GS1 mentah → array hasil.
     *
     * @return array{gtin: ?string, lot_number: ?string, serial_number: ?string,
     *               expiry_date: ?string, raw: string, valid: bool, errors: array<string>}
     */
    public static function parse(string $raw): array
    {
        $errors = [];
        $out = [
            'gtin'          => null,
            'lot_number'    => null,
            'serial_number' => null,
            'expiry_date'   => null,
            'raw'           => $raw,
            'valid'         => false,
            'errors'        => [],
        ];

        // Normalisasi: buang prefix simbologi ]d2/]C1 bila ada, normalisasi
        // bentuk human-readable berkurung "(01)..." menjadi "01...".
        $s = self::normalize($raw);
        if ($s === '') {
            $out['errors'] = ['Barcode kosong.'];

            return $out;
        }

        $len = strlen($s);
        $i = 0;

        while ($i < $len) {
            // Lewati FNC1 yang nyasar di awal segmen.
            if ($s[$i] === self::FNC1) {
                $i++;
                continue;
            }

            $ai = self::readAiPrefix($s, $i);
            if ($ai === null) {
                // Tidak mengenali prefix AI di posisi ini — hentikan, simpan sisa sbg error.
                $errors[] = 'AI tak dikenal pada posisi ' . $i . ' (sisa: ' . substr($s, $i, 8) . '…).';
                break;
            }

            $i += strlen($ai);

            if (isset(self::FIXED_AI[$ai])) {
                $dataLen = self::FIXED_AI[$ai];
                $value = substr($s, $i, $dataLen);
                $i += $dataLen;
                self::assignFixed($out, $ai, $value, $errors);
            } else {
                // Variable-length: baca sampai FNC1 / prefix AI berikut / akhir.
                [$value, $next] = self::readVariable($s, $i);
                $i = $next;
                self::assignVariable($out, $ai, $value);
            }
        }

        // Validasi GTIN (wajib ada & 14 digit numerik).
        if ($out['gtin'] === null) {
            $errors[] = 'GTIN (AI 01) tidak ditemukan.';
        } elseif (! preg_match('/^\d{14}$/', $out['gtin'])) {
            $errors[] = 'GTIN bukan 14 digit: ' . $out['gtin'];
            $out['gtin'] = null;
        }

        $out['errors'] = $errors;
        $out['valid'] = $out['gtin'] !== null && count($errors) === 0;

        return $out;
    }

    /** Bersihkan prefix simbologi & ubah "(01)" → "01". */
    private static function normalize(string $raw): string
    {
        $s = trim($raw);

        // Buang prefix simbologi AIM umum: ]d2 (DataMatrix GS1), ]C1, ]e0, ]Q3.
        $s = preg_replace('/^\][A-Za-z]\d/', '', $s) ?? $s;

        // Bentuk human-readable berkurung: "(01)00380652555821(17)290213(10)ABC(21)123"
        // → buang kurung, sisipkan FNC1 sebelum tiap AI agar batas variable jelas.
        if (str_contains($s, '(')) {
            $s = preg_replace_callback('/\((\d{2,3})\)/', function ($m) {
                return self::FNC1 . $m[1];
            }, $s) ?? $s;
            // FNC1 di paling depan akan dilewati loop.
        }

        return $s;
    }

    /** Coba baca prefix AI (2 atau 3 digit) yang dikenali di posisi $i. */
    private static function readAiPrefix(string $s, int $i): ?string
    {
        $two = substr($s, $i, 2);
        if (in_array($two, self::KNOWN_AI, true)) {
            return $two;
        }
        $three = substr($s, $i, 3);
        if (in_array($three, self::KNOWN_AI, true)) {
            return $three;
        }

        return null;
    }

    /** Baca data variable-length mulai $i sampai FNC1 / prefix AI dikenal / akhir. */
    private static function readVariable(string $s, int $i): array
    {
        $len = strlen($s);
        $start = $i;

        while ($i < $len) {
            if ($s[$i] === self::FNC1) {
                $value = substr($s, $start, $i - $start);

                return [$value, $i + 1]; // lewati FNC1
            }
            // Jika tepat di sini ada prefix AI lain yang dikenal (FNC1 hilang),
            // anggap batas segmen. Hanya berlaku bila SISA cukup & prefix valid.
            if ($i > $start && self::looksLikeNextAi($s, $i)) {
                return [substr($s, $start, $i - $start), $i];
            }
            $i++;
        }

        return [substr($s, $start), $len];
    }

    /**
     * Heuristik: apakah posisi $i tampak seperti awal AI berikutnya saat FNC1 hilang?
     * Hanya percaya pada AI fixed-length yang formatnya pasti (01 + 14 digit, 17 + 6 digit),
     * supaya tidak salah memenggal lot/serial yang kebetulan diawali "10"/"21".
     */
    private static function looksLikeNextAi(string $s, int $i): bool
    {
        $two = substr($s, $i, 2);
        if ($two === '01' && preg_match('/^01\d{14}/', substr($s, $i, 16))) {
            return true;
        }
        if ($two === '17' && preg_match('/^17\d{6}/', substr($s, $i, 8))) {
            return true;
        }

        return false;
    }

    private static function assignFixed(array &$out, string $ai, string $value, array &$errors): void
    {
        if ($ai === '01') {
            $out['gtin'] = $value;
        } elseif ($ai === '17') {
            $date = self::yymmddToDate($value);
            if ($date === null) {
                $errors[] = 'Expiry (AI 17) tak valid: ' . $value;
            } else {
                $out['expiry_date'] = $date;
            }
        }
        // 11/15 diabaikan (production/best-before) — hanya dibaca agar tak salah parse.
    }

    private static function assignVariable(array &$out, string $ai, string $value): void
    {
        if ($ai === '10') {
            $out['lot_number'] = $value !== '' ? $value : null;
        } elseif ($ai === '21') {
            $out['serial_number'] = $value !== '' ? $value : null;
        }
        // 240/30 diabaikan.
    }

    /**
     * Konversi YYMMDD GS1 → 'YYYY-MM-DD'.
     *  - Abad: yy <= 50 → 20yy, yy > 50 → 19yy (aturan GS1).
     *  - DD = 00 berarti "hari terakhir bulan".
     */
    public static function yymmddToDate(string $yymmdd): ?string
    {
        if (! preg_match('/^\d{6}$/', $yymmdd)) {
            return null;
        }

        $yy = (int) substr($yymmdd, 0, 2);
        $mm = (int) substr($yymmdd, 2, 2);
        $dd = (int) substr($yymmdd, 4, 2);

        if ($mm < 1 || $mm > 12) {
            return null;
        }

        $year = $yy <= 50 ? 2000 + $yy : 1900 + $yy;

        if ($dd === 0) {
            // Hari terakhir bulan.
            $dd = (int) date('t', mktime(0, 0, 0, $mm, 1, $year));
        }

        if ($dd < 1 || $dd > 31) {
            return null;
        }

        // checkdate menolak 31 utk bulan 30-hari, dst.
        if (! checkdate($mm, $dd, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $mm, $dd);
    }
}
