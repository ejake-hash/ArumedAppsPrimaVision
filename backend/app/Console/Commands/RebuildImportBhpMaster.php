<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import the BHP STOCK catalog (consumables) from bhp-20260602.xlsx into bhp_items.
 *
 * This is a SEPARATE catalog from the BHP billing items already imported from Buku Tarif
 * (only ~2 overlap — kept separate per user decision): stock items are inventory consumables
 * WITHOUT a tariff (absorbed in procedures/sets). MEDICAL_SUPPLIES is folded into MEDICAL_BHP.
 * Code prefix BHPS- marks stock items (vs BHP-/CSSD- billing items).
 *
 * DEV / REHEARSAL ONLY. Dry-run by default; --force to apply. Skip-by-(name+category)
 * idempotency against rows previously imported by this command (BHPS code).
 */
class RebuildImportBhpMaster extends Command
{
    protected $signature = 'rebuild:import-bhp-master {--force} {--file=}';

    protected $description = 'Import BHP stock catalog (126) from bhp-20260602.xlsx into bhp_items (no tariff). DEV ONLY.';

    public function handle(): int
    {
        $db = DB::connection()->getDatabaseName();
        if (app()->environment('production') || $db === 'arumed_primavision') {
            $this->error("REFUSED: must not run on production / arumed_primavision (db={$db}).");

            return self::FAILURE;
        }
        $file = $this->option('file') ?: base_path('../Docs/migrasi data/bhp-20260602.xlsx');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }
        $force = (bool) $this->option('force');
        $now = now()->toDateTimeString();

        $rows = IOFactory::load($file)->getSheetByName('BHP')->toArray();
        $created = 0;
        $skipped = 0;
        $collide = 0; // name already exists among EXISTING bhp_items (billing) — informational
        $seq = (int) DB::table('bhp_items')->where('code', 'like', 'BHPS-%')->count();

        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $name = trim((string) ($r[0] ?? ''));
            if ($name === '') {
                continue;
            }
            // Re-run guard: skip if this STOCK row (BHPS) was already imported.
            if (DB::table('bhp_items')->where('name', $name)->where('code', 'like', 'BHPS-%')->exists()) {
                $skipped++;

                continue;
            }
            if (DB::table('bhp_items')->where('name', $name)->where('code', 'not like', 'BHPS-%')->exists()) {
                $collide++; // same name as a billing item — kept separate per decision
            }
            if ($force) {
                $seq++;
                DB::table('bhp_items')->insert([
                    'id' => (string) Str::orderedUuid(),
                    'code' => 'BHPS-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                    'name' => $name,
                    'category' => 'MEDICAL_BHP', // MEDICAL_SUPPLIES folded in
                    'unit' => $this->n($r[2] ?? null),
                    'manufacturer' => $this->n($r[3] ?? null),
                    'stock' => (int) ($r[4] ?? 0),
                    'min_stock' => (int) ($r[5] ?? 0),
                    'price' => (float) preg_replace('/[^0-9.]/', '', (string) ($r[6] ?? 0)) ?: 0,
                    'batch_number' => $this->n($r[8] ?? null),
                    'description' => $this->n($r[9] ?? null),
                    'is_active' => ((string) ($r[10] ?? '1')) !== '0',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $created++;
        }

        $this->info("DB={$db}  MODE=" . ($force ? 'FORCE' : 'DRY-RUN'));
        $this->line('bhp-master (BHPS, MEDICAL_BHP, tanpa tarif): +' . $created . ($force ? ' (inserted)' : ' (akan)') . "  | skip(sudah ada): {$skipped}");
        $this->line('   nama sama dgn item billing (sengaja terpisah): ' . $collide);
        if (! $force) {
            $this->warn('DRY-RUN — belum ada perubahan. Jalankan --force untuk eksekusi.');
        }

        return self::SUCCESS;
    }

    private function n($v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }
}
