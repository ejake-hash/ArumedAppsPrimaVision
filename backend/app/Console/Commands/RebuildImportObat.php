<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import the obat catalog into arumed_dev (BARANG vs HARGA model):
 *   - medications (master, 411) from obat-20260602.xlsx — normalized enums, kept formularium
 *     (FORNAS/NON-FORNAS), auto code MED-###.
 *   - medication_tariffs (UMUM price) from the 200 obat rows of Buku Tarif 2025 (OBP/OBT/OBI),
 *     auto-MATCHED to the master by a normalized name key.
 *
 * Unmatched buku-tarif prices and unpriced master meds are written to review CSVs for the
 * pharmacist. medication_tariffs has UNIQUE(medication_id, insurer_id), so a drug appearing in
 * two pos (dobel-pos) is priced once — OBP wins (rows processed OBP->OBT->OBI). Berry Vision
 * Dispersible duplicate (Rp 710 row) is dropped per user decision.
 *
 * DEV / REHEARSAL ONLY. Dry-run by default; --force to apply (also refuses if medications
 * already populated). Review CSVs are written in both modes.
 */
class RebuildImportObat extends Command
{
    protected $signature = 'rebuild:import-obat
        {--force : Apply (default: dry-run preview only)}
        {--obat-file= : obat master xlsx}
        {--tarif-file= : Buku Tarif xlsx}';

    protected $description = 'Import medications master (411) + medication_tariffs via auto-match to Buku Tarif obat prices. DEV ONLY.';

    private array $golonganMap = [
        'obat keras' => 'KERAS', 'obat bebas' => 'BEBAS', 'obat bebas terbatas' => 'BEBAS_TERBATAS',
        'narkotika' => 'NARKOTIKA', 'psikotropika' => 'PSIKOTROPIKA',
        // kosong / suplemen / jamu => NULL (golongan tak punya enum LAIN)
    ];

    private array $formMap = [
        'tablet' => 'TABLET', 'kaplet' => 'TABLET', 'caplet' => 'TABLET',
        'kapsul' => 'KAPSUL', 'sirup' => 'SIRUP',
        'tetes mata' => 'TETES_MATA', 'salep mata' => 'SALEP_MATA',
        'injeksi' => 'INJEKSI', 'infus parenteral' => 'INJEKSI',
        // serbuk / suspensi / salep / suppositoria / cairan inhalasi => LAIN ; kosong => NULL
    ];

    private array $posMap = ['Obat Pulang' => 'OBAT_PULANG', 'Obat Tindakan' => 'OBAT_TINDAKAN', 'Obat Injeksi' => 'OBAT_INJEKSI'];

    private array $posPriority = ['Obat Pulang' => 1, 'Obat Tindakan' => 2, 'Obat Injeksi' => 3];

    private string $now;

    private string $umumId;

    private int $medSeq = 0;

    public function handle(): int
    {
        $db = DB::connection()->getDatabaseName();
        if (app()->environment('production') || $db === 'arumed_primavision') {
            $this->error("REFUSED: must not run on production / arumed_primavision (db={$db}).");

            return self::FAILURE;
        }
        $umum = DB::table('insurers')->where('is_system', true)->where('type', 'UMUM')->first();
        if (! $umum) {
            $this->error('Insurer UMUM tidak ditemukan.');

            return self::FAILURE;
        }
        $this->umumId = $umum->id;
        $this->now = now()->toDateTimeString();
        $force = (bool) $this->option('force');

        $obatFile = $this->option('obat-file') ?: base_path('../Docs/migrasi data/obat-20260602.xlsx');
        $tarifFile = $this->option('tarif-file') ?: base_path('../Docs/migrasi data/Buku Tarif 2025.xlsx');
        foreach ([$obatFile, $tarifFile] as $f) {
            if (! is_file($f)) {
                $this->error("File not found: {$f}");

                return self::FAILURE;
            }
        }

        $existing = DB::table('medications')->count();
        if ($force && $existing > 0) {
            $this->error("medications sudah terisi ({$existing}). Reset dulu (rebuild:reset-to-base) sebelum impor obat.");

            return self::FAILURE;
        }

        // ── 1) Parse obat master (411) ──────────────────────────────────────
        $master = $this->parseObat($obatFile);   // each: cols + 'norm'
        $this->info("DB={$db}  MODE=" . ($force ? 'FORCE' : 'DRY-RUN') . "  master obat: " . count($master));

        // ── 2) Insert master (force) + build normalized index ──────────────
        $normIndex = [];   // normName => [indices]
        $alk = 0;
        foreach ($master as $i => &$m) {
            $normIndex[$m['norm']][] = $i;
            if (str_starts_with(mb_strtolower($m['name']), 'alk -')) {
                $alk++;
            }
            if ($force) {
                $m['id'] = $this->insertMed($m);
            }
        }
        unset($m);
        $this->line('   (catatan: ' . $alk . " baris berawalan 'Alk -' = alkes/BHP ikut di file obat — diimpor sbg medication apa adanya)");

        // ── 3) Parse buku tarif obat (OBP/OBT/OBI), Berry-merge, sort pos ──
        $tarif = $this->parseTarifObat($tarifFile);
        usort($tarif, fn ($a, $b) => ($this->posPriority[$a['kat']] ?? 9) <=> ($this->posPriority[$b['kat']] ?? 9));

        // ── 4) Match + create tariffs ──────────────────────────────────────
        $priced = [];        // master index => pos used
        $tariffsCreated = 0;
        $unmatched = [];     // buku tarif rows with no master match
        $dobelPos = [];      // buku tarif rows whose master is already priced
        $formConflicts = []; // 1 buku-tarif row matched masters with DIFFERENT form_sediaan
        foreach ($tarif as $t) {
            $hits = $normIndex[$t['norm']] ?? [];
            if (! $hits) {
                $unmatched[] = $t;

                continue;
            }
            if (count($hits) > 1) {
                $forms = array_values(array_unique(array_filter(array_map(fn ($i) => $master[$i]['form'], $hits))));
                if (count($forms) > 1) {
                    $formConflicts[] = ['name' => $t['name'], 'forms' => $forms, 'masters' => array_map(fn ($i) => $master[$i]['name'], $hits)];
                }
            }
            foreach ($hits as $i) {
                if (isset($priced[$i])) {
                    $dobelPos[] = ['tarif' => $t, 'master' => $master[$i]['name'], 'sudah' => $priced[$i]];

                    continue;
                }
                $priced[$i] = $this->posMap[$t['kat']];
                if ($force) {
                    DB::table('medication_tariffs')->insert([
                        'id' => (string) Str::orderedUuid(),
                        'medication_id' => $master[$i]['id'],
                        'insurer_id' => $this->umumId,
                        'price' => $t['harga'],
                        'pos_kwitansi' => $this->posMap[$t['kat']],
                        'is_active' => true,
                        'created_at' => $this->now, 'updated_at' => $this->now,
                    ]);
                }
                $tariffsCreated++;
            }
        }

        // ── 5) Reports ─────────────────────────────────────────────────────
        $unpriced = [];
        foreach ($master as $i => $m) {
            if (! isset($priced[$i])) {
                $unpriced[] = $m;
            }
        }
        $dir = base_path('../Docs/migrasi data/');
        // Fuzzy SUGGESTIONS for unmatched (NOT auto-applied — pharmacist confirms; score exposes
        // dose mismatches like Captopril 25 vs 12,5 that look similar but are different drugs).
        $unpricedNorm = array_map(fn ($m) => ['name' => $m['name'], 'norm' => $m['norm']], $unpriced);
        $unmatchedRows = [];
        foreach ($unmatched as $t) {
            $best = 0.0;
            $bestName = '';
            $bestNorm = '';
            foreach ($unpricedNorm as $c) {
                similar_text($t['norm'], $c['norm'], $p);
                if ($p > $best) {
                    $best = $p;
                    $bestName = $c['name'];
                    $bestNorm = $c['norm'];
                }
            }
            $doseSama = ($bestNorm !== '' && $this->doseSig($t['norm']) === $this->doseSig($bestNorm)) ? 'Y' : 'N';
            $unmatchedRows[] = [$t['name'], $t['kat'], $t['harga'], $t['norm'], $bestName, round($best) . '%', $doseSama];
        }
        $this->writeCsv($dir . 'obat-REVIEW-unmatched-harga.csv',
            ['buku_tarif_nama', 'kategori', 'harga', 'norm_key', 'SARAN_master_terdekat', 'kemiripan', 'dosis_sama'],
            $unmatchedRows);
        $this->writeCsv($dir . 'obat-REVIEW-unpriced-master.csv',
            ['master_nama', 'kfa_code', 'golongan', 'form_sediaan', 'norm_key'],
            array_map(fn ($m) => [$m['name'], $m['kfa'], $m['golongan'] ?? '', $m['form'] ?? '', $m['norm']], $unpriced));

        // ── Summary ────────────────────────────────────────────────────────
        $this->newLine();
        $this->info('=== RINGKASAN OBAT ===');
        $this->line('  master medications     : ' . count($master) . ($force ? ' (inserted)' : ' (akan di-insert)'));
        $this->line('  buku tarif obat rows   : ' . count($tarif) . ' (setelah Berry-merge)');
        $this->line('  tarif UMUM dibuat      : ' . $tariffsCreated . ($force ? '' : ' (akan dibuat)'));
        $this->line('  master TERHARGA        : ' . count($priced) . ' / ' . count($master));
        $this->line('  master UNPRICED        : ' . count($unpriced) . '  → obat-REVIEW-unpriced-master.csv');
        $this->line('  buku-tarif UNMATCHED   : ' . count($unmatched) . '  → obat-REVIEW-unmatched-harga.csv');
        $this->line('  dobel-pos di-skip      : ' . count($dobelPos) . ' (med sudah berharga; pos pertama menang)');
        $this->line('  FORM-CONFLICT merge    : ' . count($formConflicts) . ' (1 harga → master beda bentuk; RISIKO harga tertukar)');
        foreach ($formConflicts as $fc) {
            $this->warn('     ⚠ "' . $fc['name'] . '" [' . implode('/', $fc['forms']) . '] → ' . implode(' ; ', $fc['masters']));
        }
        foreach (array_slice($dobelPos, 0, 10) as $d) {
            $this->line(sprintf('     - "%s" (%s) → "%s" sudah %s', $d['tarif']['name'], $d['tarif']['kat'], $d['master'], $d['sudah']));
        }
        if (! $force) {
            $this->newLine();
            $this->warn('DRY-RUN — DB belum berubah (CSV review sudah ditulis). Jalankan --force untuk eksekusi.');
        }

        return self::SUCCESS;
    }

    // ── Parsing ───────────────────────────────────────────────────────────

    private function parseObat(string $file): array
    {
        $rows = IOFactory::load($file)->getSheetByName('Obat')->toArray();
        $out = [];
        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $name = trim((string) ($r[1] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'kfa' => $this->blankNull($r[0] ?? null),
                'name' => $name,
                'generic' => $this->blankNull($r[2] ?? null),
                'composition' => $this->blankNull($r[3] ?? null),
                'manufacturer' => $this->blankNull($r[4] ?? null),
                'formularium' => trim((string) ($r[5] ?? '')) ?: 'NON-FORNAS',
                'form' => $this->mapForm((string) ($r[6] ?? '')),
                'golongan' => $this->mapGolongan((string) ($r[7] ?? '')),
                'unit_besar' => $this->blankNull($r[8] ?? null),
                'unit_kecil' => $this->blankNull($r[9] ?? null),
                'konversi' => $this->intNull($r[10] ?? null),
                'stock' => (int) ($r[11] ?? 0),
                'min_stock' => (int) ($r[12] ?? 0),
                'price' => (float) preg_replace('/[^0-9.]/', '', (string) ($r[13] ?? 0)) ?: 0,
                'is_active' => ((string) ($r[17] ?? '1')) !== '0',
                'norm' => $this->norm($name),
            ];
        }

        return $out;
    }

    private function parseTarifObat(string $file): array
    {
        $rows = IOFactory::load($file)->getSheetByName('Tarif per Tindakan')->toArray();
        $out = [];
        foreach ($rows as $idx => $r) {
            if ($idx === 0) {
                continue;
            }
            $name = trim((string) ($r[1] ?? ''));
            $kat = trim((string) ($r[2] ?? ''));
            if ($name === '' || ! isset($this->posMap[$kat])) {
                continue;
            }
            $harga = (int) preg_replace('/[^0-9]/', '', (string) ($r[3] ?? '0'));
            // Keputusan user: drop baris duplikat 'Berry Vision Dispersible' harga 710 (keep 2614).
            if (mb_strtolower($name) === 'berry vision dispersible' && $harga === 710) {
                continue;
            }
            $out[] = ['name' => $name, 'kat' => $kat, 'harga' => $harga, 'norm' => $this->norm($name)];
        }

        return $out;
    }

    // ── Normalization & mapping ────────────────────────────────────────────

    private function norm(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = str_replace(',', '.', $s);                                  // decimal comma → dot
        $s = preg_replace('/\b(bpjs|inhealth)\b/u', ' ', $s);           // penjamin suffix
        $s = preg_replace('/\b(tab|tablet|kaplet|caplet|kapsul|cap|inj|injeksi|syrup|sirup|drops|drop|nebule|nebules)\b/u', ' ', $s); // form/descriptor tokens (dosis tetap dipertahankan)
        $s = preg_replace('/\s*(mg|ml|mcg|gr|g|iu)\b/u', '$1', $s);     // remove space before unit (% handled below — \b fails after %)
        $s = preg_replace('/[^a-z0-9.\/%+]+/u', ' ', $s);              // punctuation → space
        $s = preg_replace('/\s*\/\s*/', '/', $s);                      // tighten slashes: "5mg / ml" → "5mg/ml"
        $s = preg_replace('/\s+%/', '%', $s);                          // tighten percent: "20 %" → "20%"
        $s = preg_replace('/\s+/', ' ', trim($s));

        return $s;
    }

    /** Tanda-tangan dosis: kumpulan token angka(+unit) terurut, untuk cek dosis sama. */
    private function doseSig(string $norm): string
    {
        preg_match_all('/[0-9][0-9.]*(?:mg|ml|mcg|gr|g|iu|%)?/u', $norm, $m);
        $t = $m[0];
        sort($t);

        return implode('|', $t);
    }

    private function mapGolongan(string $raw): ?string
    {
        return $this->golonganMap[mb_strtolower(trim($raw))] ?? null;
    }

    private function mapForm(string $raw): ?string
    {
        $k = mb_strtolower(trim($raw));
        if ($k === '') {
            return null;
        }

        return $this->formMap[$k] ?? 'LAIN';
    }

    private function blankNull($v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }

    private function intNull($v): ?int
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : (int) $s;
    }

    private function insertMed(array $m): string
    {
        $this->medSeq++;
        $id = (string) Str::orderedUuid();
        DB::table('medications')->insert([
            'id' => $id,
            'code' => 'MED-' . str_pad((string) $this->medSeq, 4, '0', STR_PAD_LEFT),
            'kfa_code' => $m['kfa'],
            'name' => $m['name'],
            'generic_name' => $m['generic'],
            'composition' => $m['composition'],
            'manufacturer' => $m['manufacturer'],
            'formularium' => $m['formularium'],
            'form_sediaan' => $m['form'],
            'golongan' => $m['golongan'],
            'unit' => $m['unit_kecil'] ?? $m['unit_besar'],
            'unit_besar' => $m['unit_besar'],
            'unit_kecil' => $m['unit_kecil'],
            'konversi' => $m['konversi'],
            'stock' => $m['stock'],
            'min_stock' => $m['min_stock'],
            'price' => $m['price'],
            'is_active' => $m['is_active'],
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);

        return $id;
    }

    private function writeCsv(string $path, array $header, array $rows): void
    {
        $fh = fopen($path, 'w');
        fputcsv($fh, $header);
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);
    }
}
