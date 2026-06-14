<?php

namespace App\Console\Commands;

use App\Models\Icd10Code;
use App\Models\Icd10Subdiagnosis;
use App\Models\Icd9Code;
use App\Models\Icd9Subdiagnosis;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Impor master ICD dari `Docs/ICD 10 dan 9.xlsx` → kode kanonik (icd10_codes/
 * icd9_codes, 1 baris/kode) + sub-diagnosa (banyak nama klinis per kode). Idempoten
 * (updateOrCreate by code / (code,name)); NON-destruktif (nama kanonik kode yang sudah
 * ada TIDAK ditimpa; kode lama yang tak ada di file TIDAK dihapus). Lihat plan ICD.
 *
 * Struktur sumber (Sheet1, 1 kolom kode + 1 kolom nama), 3 seksi dipisah baris penanda
 * (kolom A kosong, kolom B = label):
 *   - "ICD-10"           → diagnosa; kode tanpa titik ("H000") → sisip titik → "H00.0"
 *   - "ICD-9 TINDAKAN"    → prosedur; kode di-entry sbg WAKTU (serial) "8:09" → "08.09"
 *   - "ICD-9 PEMERIKSAAN" → penunjang; kode sudah bertitik ("16.21") → apa adanya
 */
class ImportIcdMaster extends Command
{
    protected $signature = 'icd:import {path? : Path ke xlsx (default Docs/ICD 10 dan 9.xlsx)}';
    protected $description = 'Impor master ICD-10/9 + sub-diagnosa dari xlsx (idempoten, non-destruktif).';

    public function handle(): int
    {
        $path = $this->argument('path') ?: base_path('../Docs/ICD 10 dan 9.xlsx');
        if (! is_file($path)) {
            $this->error("File tidak ditemukan: {$path}");
            return self::FAILURE;
        }

        $this->info("Membaca: {$path}");
        $sheet = IOFactory::load($path)->getActiveSheet();
        $hr = $sheet->getHighestRow();

        // Kumpulkan per seksi: list [code, name] urut sesuai file.
        $rows = ['icd10' => [], 'icd9' => []];
        $mode = null;
        for ($r = 1; $r <= $hr; $r++) {
            $aRaw = $sheet->getCell("A{$r}")->getValue();
            $aFmt = trim((string) $sheet->getCell("A{$r}")->getFormattedValue());
            $b    = trim((string) $sheet->getCell("B{$r}")->getValue());

            // Baris penanda seksi: A kosong, B berisi label.
            if ($aFmt === '' && $b !== '') {
                $u = strtoupper($b);
                if (str_contains($u, 'ICD-10'))                 $mode = 'icd10';
                elseif (str_contains($u, 'ICD-9'))              $mode = 'icd9';
                continue;
            }
            if ($aFmt === '' || $b === '' || $mode === null) {
                continue; // baris kosong / sebelum seksi
            }

            $code = $mode === 'icd10' ? $this->dotIcd10($aFmt) : $this->normIcd9($aRaw, $aFmt, $r);
            if ($code === null) {
                continue;
            }
            $rows[$mode][] = [$code, $b];
        }

        $sum10 = $this->ingest($rows['icd10'], Icd10Code::class, Icd10Subdiagnosis::class, 'icd10_code_id');
        $sum9  = $this->ingest($rows['icd9'], Icd9Code::class, Icd9Subdiagnosis::class, 'icd9_code_id');

        $this->newLine();
        $this->info("ICD-10 : {$sum10['codes']} kode kanonik ({$sum10['created']} baru), {$sum10['subs']} sub-diagnosa.");
        $this->info("ICD-9  : {$sum9['codes']} kode kanonik ({$sum9['created']} baru), {$sum9['subs']} sub-diagnosa.");
        $this->info('Selesai (idempoten).');

        return self::SUCCESS;
    }

    /** Sisip titik setelah 3 char (H000 → H00.0); sudah bertitik / ≤3 char → apa adanya. */
    private function dotIcd10(string $s): ?string
    {
        $s = strtoupper(trim($s));
        if ($s === '') return null;
        if (strlen($s) >= 4 && $s[3] !== '.') {
            return substr($s, 0, 3) . '.' . substr($s, 3);
        }
        return $s;
    }

    /** Normalisasi ICD-9: serial WAKTU "8:09"→"08.09"; selain itu string bertitik apa adanya. */
    private function normIcd9($raw, string $fmt, int $rowNo): ?string
    {
        // Kode di-entry sbg waktu Excel (fraksi hari) → konversi jam:menit → HH.MM.
        if (is_numeric($raw) && $raw > 0 && $raw < 1) {
            $secs = (int) round($raw * 86400);
            if ($secs % 60 !== 0) {
                $this->warn("  Baris {$rowNo}: detik ≠ 00 pada kode waktu (".$fmt.") — pakai jam:menit saja.");
            }
            return sprintf('%02d.%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60));
        }
        $s = strtoupper(trim($fmt));
        return $s === '' ? null : $s;
    }

    /**
     * Tulis kanonik + sub untuk satu set. Kanonik: updateOrCreate by code, nama kode
     * BARU = nama sub pertama (decision #3), kode lama TAK ditimpa. Sub: by (code,name).
     */
    private function ingest(array $pairs, string $codeModel, string $subModel, string $fk): array
    {
        // Grup nama per kode, urut sesuai kemunculan.
        $byCode = [];
        foreach ($pairs as [$code, $name]) {
            $byCode[$code] ??= [];
            // dedupe nama persis (case-insensitive) dlm satu kode
            $key = mb_strtolower($name);
            $byCode[$code][$key] ??= $name;
        }

        $created = 0; $subs = 0;
        foreach ($byCode as $code => $names) {
            $names = array_values($names);
            $category = explode('.', $code)[0];

            $existing = $codeModel::withTrashed()->where('code', $code)->first();
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $canonical = $existing; // JANGAN timpa nama kode yang sudah ada
            } else {
                $canonical = $codeModel::create([
                    'code'           => $code,
                    'category'       => $category,
                    'description'    => $names[0],   // nama sub pertama sbg nama kanonik
                    'is_eye_related' => true,
                    'is_favorite'    => false,
                ]);
                $created++;
            }

            foreach ($names as $i => $name) {
                $sub = $subModel::withTrashed()->where('code', $code)->where('name', $name)->first();
                if ($sub) {
                    if ($sub->trashed()) $sub->restore();
                    $sub->fill([$fk => $canonical->id, 'is_eye_related' => true, 'is_active' => true, 'sort_order' => $i])->save();
                } else {
                    $subModel::create([
                        $fk => $canonical->id, 'code' => $code, 'name' => $name,
                        'is_eye_related' => true, 'is_active' => true, 'sort_order' => $i,
                    ]);
                }
                $subs++;
            }
        }

        return ['codes' => count($byCode), 'created' => $created, 'subs' => $subs];
    }
}
