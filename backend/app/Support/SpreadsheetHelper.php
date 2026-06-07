<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Adapter format CSV ↔ XLSX.
 *
 * Tujuan: SELURUH logika bisnis tarif (build rows, lookup item, upsert) tetap
 * berbasis CSV di MasterDataService. Helper ini hanya menjembatani format file
 * di batas controller — XLSX di-konversi ke/dari CSV string lalu jalur CSV yang
 * SUDAH teruji dipakai apa adanya.
 *
 * Catatan deploy: phpoffice/phpspreadsheet menuntut ext-gd, tapi kita TIDAK pakai
 * fitur gambar — composer.json memalsukan platform.ext-gd agar `composer install`
 * lolos tanpa ekstensi gd terpasang. Jangan panggil API gambar PhpSpreadsheet.
 */
class SpreadsheetHelper
{
    /**
     * Konversi CSV string (delimiter koma, baris diawali "#" = komentar) ke
     * file XLSX biner. Baris komentar TIDAK ikut ditulis ke xlsx (Excel tak punya
     * konsep komentar baris yang aman) — hanya header + data.
     */
    public static function csvToXlsx(string $csv, string $sheetTitle = 'Tarif', bool $keepComments = false): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($sheetTitle, 0, 31));

        $rowNum = 1;
        $maxCols = 0;
        $headerRow = null; // baris header = baris data pertama (non-komentar) → utk bold
        $lines = explode("\n", str_replace("\r", '', trim($csv)));
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue; // lewati baris kosong
            }
            $isComment = str_starts_with($trimmed, '#');
            if ($isComment && ! $keepComments) {
                continue; // perilaku lama: komentar petunjuk tak ikut ke xlsx
            }

            // Komentar (petunjuk) ditulis utuh di kolom A sebagai teks; data diparse normal.
            $cells = $isComment ? [$trimmed] : str_getcsv($line, ',', '"', '\\');
            $colNum = 1;
            foreach ($cells as $cell) {
                $sheet->setCellValueExplicit(
                    [$colNum, $rowNum],
                    $cell,
                    (! $isComment && self::isNumericCell($cell, $rowNum))
                        ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                        : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
                $colNum++;
            }
            if (! $isComment && $headerRow === null) {
                $headerRow = $rowNum; // baris header pertama (non-komentar)
            }
            $maxCols = max($maxCols, count($cells));
            $rowNum++;
        }

        // Bold baris header (baris data pertama) + auto width supaya rapi dibuka di Excel.
        if ($headerRow !== null && $maxCols > 0) {
            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($maxCols);
            $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFont()->setBold(true);
            for ($c = 1; $c <= $maxCols; $c++) {
                $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
            }
        }

        $writer = new XlsxWriter($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $binary = ob_get_clean();

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $binary;
    }

    /**
     * Konversi file upload (CSV / TXT / XLSX / XLS / ODS) → CSV string ternormalisasi
     * (UTF-8 tanpa BOM, delimiter koma) yang siap dikonsumsi importer CSV existing.
     *
     * SUMBER TUNGGAL pembacaan file import di seluruh modul — menangani:
     *   - Excel biner (.xlsx/.xls/.ods) lewat PhpSpreadsheet.
     *   - CSV/TXT: buang BOM UTF-8 (Excel "Save as CSV" hampir selalu menambah BOM →
     *     header kolom pertama jadi "﻿code" ≠ "code" dan import gagal).
     *   - CSV ber-delimiter ';' (umum di Excel locale Indonesia) → dikonversi ke koma
     *     supaya parser str_getcsv(',') existing tetap benar.
     */
    public static function fileToCsv(UploadedFile $file): string
    {
        $ext = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));
        $path = $file->getRealPath();

        if (in_array($ext, ['xlsx', 'xls', 'ods'], true)) {
            return self::xlsxToCsv($path);
        }

        // CSV / TXT (atau ekstensi tak dikenal yang ternyata teks).
        return self::normalizeCsvString((string) file_get_contents($path));
    }

    /**
     * Bersihkan CSV string: hapus BOM UTF-8, dan bila terdeteksi delimiter ';'
     * dominan (Excel ID) → parse-ulang dan tulis-ulang dengan delimiter koma.
     */
    public static function normalizeCsvString(string $csv): string
    {
        // Buang BOM UTF-8 di awal file.
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }

        // Deteksi delimiter dari baris header (data pertama non-komentar/non-kosong).
        $lines = explode("\n", str_replace("\r", '', $csv));
        $header = '';
        foreach ($lines as $l) {
            $t = trim($l);
            if ($t !== '' && ! str_starts_with($t, '#')) { $header = $l; break; }
        }
        $semi  = substr_count($header, ';');
        $comma = substr_count($header, ',');

        // Sudah koma (atau tak ada pemisah sama sekali) → pakai apa adanya.
        if ($semi === 0 || $comma >= $semi) {
            return $csv;
        }

        // Re-tokenize tiap baris dgn ';' lalu tulis ulang sbg CSV koma standar.
        $out = fopen('php://temp', 'r+');
        foreach (explode("\n", str_replace("\r", '', $csv)) as $line) {
            if ($line === '') { continue; }
            // Baris komentar (#) ditulis apa adanya — bukan data.
            if (str_starts_with(trim($line), '#')) {
                fwrite($out, $line . "\n");
                continue;
            }
            $cells = str_getcsv($line, ';', '"', '\\');
            fputcsv($out, $cells, ',', '"', '\\');
        }
        rewind($out);
        $result = stream_get_contents($out);
        fclose($out);

        return $result;
    }

    /**
     * Baca file XLSX (path di disk) → CSV string (delimiter koma) yang formatnya
     * identik dengan template/export CSV, agar importer CSV existing bisa memakainya.
     */
    public static function xlsxToCsv(string $path): string
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $output = fopen('php://temp', 'r+');
        foreach ($sheet->toArray(null, true, false, false) as $row) {
            // Buang baris yang seluruhnya kosong.
            $allEmpty = true;
            foreach ($row as $v) {
                if (trim((string) $v) !== '') { $allEmpty = false; break; }
            }
            if ($allEmpty) {
                continue;
            }
            fputcsv($output, array_map(static fn ($v) => (string) ($v ?? ''), $row), ',', '"', '\\');
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $csv;
    }

    /**
     * Sel ditulis sebagai ANGKA hanya bila aman — yaitu angka "murni" tanpa nol depan
     * dan tidak terlalu panjang. Ini melindungi nilai TEKS yang kebetulan terlihat numerik
     * (telepon "0812...", kode "007", NIK 16 digit) dari korupsi Excel (nol depan hilang /
     * notasi ilmiah). Hanya berlaku untuk baris data (>1), header selalu teks.
     */
    private static function isNumericCell(string $cell, int $rowNum): bool
    {
        if ($rowNum <= 1 || $cell === '') {
            return false;
        }
        // Tolak nol depan ("007", "0812..."), tanda "+", spasi, pemisah ribuan.
        if (! preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $cell)) {
            return false;
        }
        // Angka panjang (>15 digit, mis. NIK) → biarkan teks supaya presisi/format tak rusak.
        $digits = preg_replace('/\D/', '', $cell);
        return strlen((string) $digits) <= 15;
    }

    /**
     * Parse CSV string → array record (tiap record = array kolom).
     *
     * SUMBER TUNGGAL pembacaan record CSV untuk importer. Keunggulan vs explode("\n")
     * + str_getcsv per baris: menghormati field ber-quote yang memuat newline (alamat /
     * deskripsi multi-baris tidak terpecah). Baris kosong & baris komentar ("#") dibuang
     * lebih dulu secara per-baris (komentar bersifat per-baris), lalu sisanya diparse
     * record-based dengan fgetcsv.
     */
    public static function parseCsvRecords(string $csv): array
    {
        $csv = self::normalizeCsvString($csv); // idempotent: buang BOM + ';' → ','

        // Buang komentar (#) & baris kosong dulu (per-baris). Field ber-quote yang
        // memuat newline tetap utuh karena fgetcsv di bawah menyatukan kembali.
        $clean = [];
        foreach (explode("\n", str_replace("\r", '', $csv)) as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '#')) {
                continue;
            }
            $clean[] = $line;
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, implode("\n", $clean));
        rewind($stream);

        $records = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            // fgetcsv mengembalikan [null] untuk baris kosong.
            if ($row === [null] || $row === null) {
                continue;
            }
            $allEmpty = true;
            foreach ($row as $c) {
                if (trim((string) $c) !== '') { $allEmpty = false; break; }
            }
            if ($allEmpty) {
                continue;
            }
            $records[] = $row;
        }
        fclose($stream);

        return $records;
    }
}
