<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import the deterministic billing catalog from "Buku Tarif 2025.xlsx" into arumed_dev.
 *
 * Sheet "Tarif per Tindakan" (636 rows) is routed by its 18 categories into:
 *   - procedures (+ procedure_categories + procedure_tariffs UMUM)  ~296
 *   - bhp_items  (+ bhp_tariffs UMUM)                               ~129  (BHP/CSSD)
 *   - iol_items  (+ iol_tariffs UMUM)                                  6
 *   - room_tariffs UMUM                                                5  (Sewa Kamar classes)
 * Obat (medications master + price-matching) and PAKET BEDAH are handled by separate steps.
 *
 * Every billing row gets a UMUM tariff (insurer_id of the system UMUM insurer — never NULL,
 * otherwise kasir getPrice returns Rp 0). Raw DB inserts are used on purpose so the
 * ProcedureObserver mirror (category 'Penunjang' -> diagnostic_test_types) does NOT fire,
 * keeping diagnostic_test_types BIOM-only.
 *
 * DEV / REHEARSAL ONLY. Dry-run by default; --force to apply. Idempotent (skip-by-name).
 */
class RebuildImportCatalog extends Command
{
    protected $signature = 'rebuild:import-catalog
        {--only=categories,procedures,bhp,iol,room : Comma list of domains to run}
        {--force : Apply (default: dry-run preview only)}
        {--file= : Buku Tarif xlsx path}';

    protected $description = 'Import billing catalog (procedures/bhp/iol/room + categories) from Buku Tarif 2025 into arumed_dev. DEV ONLY.';

    /** Category names (sheet "Kategori") whose tariff rows become procedures. */
    private array $procCats = [
        'Tarif Administrasi', 'Konsultasi Dokter', 'Visite Dokter',
        'Pemeriksaan Dasar Rutin', 'Pemeriksaan Dasar Lainnya',
        'Pemeriksaan Penunjang Diagnostik Mata', 'Laboratorium', 'Radiologi',
        'Tindakan Dokter', 'Tindakan Perawatan dan Kefarmasian', 'Sewa Peralatan Medik',
    ];

    /** BHP billing categories -> bhp_items.category */
    private array $bhpCats = ['Bahan Habis Pakai' => 'MEDICAL_BHP', 'CSSD' => 'CSSD'];

    private string $iolCat = 'IOL (Intra Ocular Lens)';

    private string $swkCat = 'Sewa Kamar';

    private string $now;

    private string $umumId;

    private array $seq = [];

    public function handle(): int
    {
        $db = DB::connection()->getDatabaseName();
        if (app()->environment('production') || $db === 'arumed_primavision') {
            $this->error("REFUSED: must not run on production / arumed_primavision (db={$db}).");

            return self::FAILURE;
        }

        $umum = DB::table('insurers')->where('is_system', true)->where('type', 'UMUM')->first();
        if (! $umum) {
            $this->error('Insurer sistem UMUM tidak ditemukan — tidak bisa buat tarif.');

            return self::FAILURE;
        }
        $this->umumId = $umum->id;
        $this->now = now()->toDateTimeString();

        $file = $this->option('file') ?: base_path('../Docs/migrasi data/Buku Tarif 2025.xlsx');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $ss = IOFactory::load($file);
        $catMeta = $this->readKategori($ss);          // name => [prefix, description]
        $rows = $this->readTarif($ss);                 // list of [name, kategori, harga]

        $force = (bool) $this->option('force');
        $only = array_map('trim', explode(',', (string) $this->option('only')));

        $this->info("DB={$db}  MODE=" . ($force ? 'FORCE' : 'DRY-RUN') . '  UMUM=' . $this->umumId);
        $this->info('Buku Tarif rows: ' . count($rows) . '  | only=' . implode(',', $only));
        $this->newLine();

        if (in_array('categories', $only, true)) {
            $this->importCategories($catMeta, $force);
        }
        if (in_array('procedures', $only, true)) {
            $this->importProcedures($rows, $catMeta, $force);
        }
        if (in_array('bhp', $only, true)) {
            $this->importBhp($rows, $force);
        }
        if (in_array('iol', $only, true)) {
            $this->importIol($rows, $force);
        }
        if (in_array('room', $only, true)) {
            $this->importRoom($rows, $force);
        }

        if (! $force) {
            $this->newLine();
            $this->warn('DRY-RUN — belum ada perubahan. Jalankan dgn --force untuk eksekusi.');
        }

        return self::SUCCESS;
    }

    // ── Readers ───────────────────────────────────────────────────────────────

    private function readKategori($ss): array
    {
        $rows = $ss->getSheetByName('Kategori')->toArray();
        $meta = [];
        for ($i = 1; $i < count($rows); $i++) {
            $name = trim((string) ($rows[$i][0] ?? ''));
            if ($name === '') {
                continue;
            }
            $meta[$name] = [
                'prefix' => trim((string) ($rows[$i][1] ?? '')),
                'description' => trim((string) ($rows[$i][2] ?? '')),
            ];
        }

        return $meta;
    }

    private function readTarif($ss): array
    {
        $rows = $ss->getSheetByName('Tarif per Tindakan')->toArray();
        $out = [];
        for ($i = 1; $i < count($rows); $i++) {
            $name = trim((string) ($rows[$i][1] ?? ''));
            $kat = trim((string) ($rows[$i][2] ?? ''));
            if ($name === '' || $kat === '') {
                continue;
            }
            $out[] = ['name' => $name, 'kategori' => $kat, 'harga' => $this->parseHarga($rows[$i][3] ?? '')];
        }

        return $out;
    }

    private function parseHarga($raw): int
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $raw);

        return $digits === '' ? 0 : (int) $digits;
    }

    // ── Importers ───────────────────────────────────────────────────────────────

    private function importCategories(array $catMeta, bool $force): void
    {
        $want = array_merge($this->procCats, [$this->swkCat]); // + Sewa Kamar (utk 2 OR procedure)
        $created = 0;
        $skipped = 0;
        foreach ($want as $name) {
            if (! isset($catMeta[$name])) {
                $this->warn("  kategori '{$name}' tak ada di sheet Kategori");

                continue;
            }
            if (DB::table('procedure_categories')->where('name', $name)->exists()) {
                $skipped++;

                continue;
            }
            if ($force) {
                $this->ins('procedure_categories', [
                    'name' => $name,
                    'code_prefix' => $catMeta[$name]['prefix'],
                    'description' => $catMeta[$name]['description'],
                    'is_active' => true,
                ]);
            }
            $created++;
        }
        $this->line(sprintf('categories : +%d  (skip %d)  dari %d kategori procedure', $created, $skipped, count($want)));
    }

    private function importProcedures(array $rows, array $catMeta, bool $force): void
    {
        $created = 0;
        $skipped = 0;
        $perCat = [];
        foreach ($rows as $r) {
            $isProc = in_array($r['kategori'], $this->procCats, true);
            $isOr = ($r['kategori'] === $this->swkCat && stripos($r['name'], 'ruang operasi') !== false);
            if (! $isProc && ! $isOr) {
                continue;
            }
            $prefix = $catMeta[$r['kategori']]['prefix'] ?? 'GEN';
            if (DB::table('procedures')->where('name', $r['name'])->where('category', $r['kategori'])->exists()) {
                $skipped++;

                continue;
            }
            if ($force) {
                $pid = $this->ins('procedures', [
                    'name' => $r['name'],
                    'code' => $this->nextCode('procedures', $prefix),
                    'category' => $r['kategori'],
                    'base_price' => $r['harga'],
                    'is_active' => true,
                ]);
                $this->ins('procedure_tariffs', [
                    'procedure_id' => $pid, 'insurer_id' => $this->umumId, 'price' => $r['harga'], 'is_active' => true,
                ]);
            }
            $created++;
            $perCat[$r['kategori']] = ($perCat[$r['kategori']] ?? 0) + 1;
        }
        $this->line(sprintf('procedures : +%d  (skip %d)', $created, $skipped));
        foreach ($perCat as $c => $n) {
            $this->line(sprintf('   %-40s %d', $c, $n));
        }
    }

    private function importBhp(array $rows, bool $force): void
    {
        $created = 0;
        $skipped = 0;
        $dropped = 0;
        foreach ($rows as $r) {
            if (! isset($this->bhpCats[$r['kategori']])) {
                continue;
            }
            // Keputusan user: 'phacoemulsifikasi micro surgery set' (BHP+CSSD) → CSSD saja.
            if ($r['kategori'] === 'Bahan Habis Pakai' && strtolower($r['name']) === 'phacoemulsifikasi micro surgery set') {
                $dropped++;

                continue;
            }
            $cat = $this->bhpCats[$r['kategori']];
            $prefix = $r['kategori'] === 'CSSD' ? 'CSSD' : 'BHP';
            if (DB::table('bhp_items')->where('name', $r['name'])->where('category', $cat)->exists()) {
                $skipped++;

                continue;
            }
            if ($force) {
                $bid = $this->ins('bhp_items', [
                    'name' => $r['name'],
                    'code' => $this->nextCode('bhp_items', $prefix),
                    'category' => $cat,
                    'is_active' => true,
                ]);
                $this->ins('bhp_tariffs', [
                    'bhp_item_id' => $bid, 'insurer_id' => $this->umumId, 'price' => $r['harga'], 'is_active' => true,
                ]);
            }
            $created++;
        }
        $this->line(sprintf('bhp        : +%d  (skip %d, drop %d phaco-set)', $created, $skipped, $dropped));
    }

    private function importIol(array $rows, bool $force): void
    {
        $created = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            if ($r['kategori'] !== $this->iolCat) {
                continue;
            }
            if (DB::table('iol_items')->where('brand', $r['name'])->exists()) {
                $skipped++;

                continue;
            }
            if ($force) {
                $iid = $this->ins('iol_items', [
                    'brand' => $r['name'],
                    'iol_type' => 'MONOFOCAL',
                    'is_active' => true,
                ]);
                $this->ins('iol_tariffs', [
                    'iol_item_id' => $iid, 'insurer_id' => $this->umumId, 'price' => $r['harga'], 'is_active' => true,
                ]);
            }
            $created++;
            $this->line('   IOL: ' . $r['name'] . '  Rp ' . number_format($r['harga']));
        }
        $this->line(sprintf('iol        : +%d  (skip %d)', $created, $skipped));
    }

    private function importRoom(array $rows, bool $force): void
    {
        $created = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            if ($r['kategori'] !== $this->swkCat) {
                continue;
            }
            if (stripos($r['name'], 'ruang operasi') !== false) {
                continue; // OR → procedure (importProcedures)
            }
            $class = $this->roomClass($r['name']);
            if (DB::table('room_tariffs')->where('room_class', $class)->where('insurer_id', $this->umumId)->exists()) {
                $skipped++;

                continue;
            }
            if ($force) {
                $this->ins('room_tariffs', [
                    'room_class' => $class, 'insurer_id' => $this->umumId, 'price' => $r['harga'], 'is_active' => true,
                ]);
            }
            $created++;
            $this->line(sprintf('   ROOM: "%s" → class=[%s]  Rp %s', $r['name'], $class, number_format($r['harga'])));
        }
        $this->line(sprintf('room       : +%d  (skip %d)', $created, $skipped));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function roomClass(string $name): string
    {
        $n = strtolower($name);
        if (str_contains($n, 'vip')) {
            return 'VIP';
        }
        if (preg_match('/kelas\s*(iii|ii|iv|i|1|2|3|4)\b/i', $name, $m)) {
            return strtoupper($m[1]);
        }

        return trim($name);
    }

    private function nextCode(string $table, string $prefix): string
    {
        $this->seq[$prefix] = $this->seq[$prefix] ?? 0;
        do {
            $this->seq[$prefix]++;
            $code = $prefix . '-' . str_pad((string) $this->seq[$prefix], 3, '0', STR_PAD_LEFT);
        } while (DB::table($table)->where('code', $code)->exists());

        return $code;
    }

    private function ins(string $table, array $data): string
    {
        $id = (string) Str::orderedUuid();
        DB::table($table)->insert(array_merge($data, [
            'id' => $id, 'created_at' => $this->now, 'updated_at' => $this->now,
        ]));

        return $id;
    }
}
