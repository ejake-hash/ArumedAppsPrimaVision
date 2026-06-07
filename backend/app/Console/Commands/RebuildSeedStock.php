<?php

namespace App\Console\Commands;

use App\Models\InventoryStock;
use App\Services\InventoryStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed opening inventory stock into inventory_stocks from the imported master stock columns.
 *
 *   medications.stock  > 0  -> inventory_stocks (MEDICATION, FARMASI)   [apotek: tampil + dispensing]
 *   bhp_items.stock    > 0  -> inventory_stocks (BHP, INVENTORI)        [gudang]
 *
 * The pharmacy/inventory UI reads inventory_stocks (per-location), NOT medications.stock
 * (vestigial). Uses InventoryStockService::upsertStock (handles NULL-batch UNIQUE + audit).
 * upsertStock ADDS qty, so this refuses to run if inventory_stocks is already populated
 * (re-run would double stock). DEV / REHEARSAL ONLY. Dry-run by default; --force to apply.
 */
class RebuildSeedStock extends Command
{
    protected $signature = 'rebuild:seed-stock {--force : Apply (default: dry-run preview only)}';

    protected $description = 'Seed opening inventory_stocks: obat->FARMASI, BHP->INVENTORI, qty from master stock. DEV ONLY.';

    public function handle(): int
    {
        $db = DB::connection()->getDatabaseName();
        if (app()->environment('production') || $db === 'arumed_primavision') {
            $this->error("REFUSED: must not run on production / arumed_primavision (db={$db}).");

            return self::FAILURE;
        }
        $force = (bool) $this->option('force');

        $med = DB::table('medications')->whereNull('deleted_at')->where('stock', '>', 0);
        $bhp = DB::table('bhp_items')->whereNull('deleted_at')->where('stock', '>', 0);
        $medCount = (clone $med)->count();
        $medSum = (float) (clone $med)->sum('stock');
        $bhpCount = (clone $bhp)->count();
        $bhpSum = (float) (clone $bhp)->sum('stock');
        $existing = DB::table('inventory_stocks')->count();

        $this->info("DB={$db}  MODE=" . ($force ? 'FORCE' : 'DRY-RUN'));
        $this->line(sprintf('  MEDICATION -> FARMASI   : %d item, total qty %s', $medCount, number_format($medSum)));
        $this->line(sprintf('  BHP        -> INVENTORI  : %d item, total qty %s', $bhpCount, number_format($bhpSum)));
        $this->line('  inventory_stocks existing: ' . $existing);

        if ($force && $existing > 0) {
            $this->error("REFUSED: inventory_stocks sudah terisi ({$existing}). upsertStock MENAMBAH qty → kosongkan dulu sebelum seed ulang.");

            return self::FAILURE;
        }

        if (! $force) {
            $this->warn("\nDRY-RUN — belum ada perubahan. Jalankan --force untuk eksekusi.");

            return self::SUCCESS;
        }

        $svc = app(InventoryStockService::class);
        $medDone = 0;
        $bhpDone = 0;
        DB::transaction(function () use ($med, $bhp, $svc, &$medDone, &$bhpDone) {
            (clone $med)->select('id', 'stock', 'expiry_date')->orderBy('id')->chunk(200, function ($rows) use ($svc, &$medDone) {
                foreach ($rows as $r) {
                    $svc->upsertStock(InventoryStock::TYPE_MEDICATION, $r->id, InventoryStock::LOC_FARMASI, null, (float) $r->stock, $r->expiry_date ?: null);
                    $medDone++;
                }
            });
            (clone $bhp)->select('id', 'stock')->orderBy('id')->chunk(200, function ($rows) use ($svc, &$bhpDone) {
                foreach ($rows as $r) {
                    $svc->upsertStock(InventoryStock::TYPE_BHP, $r->id, InventoryStock::LOC_INVENTORI, null, (float) $r->stock, null);
                    $bhpDone++;
                }
            });
        });

        $this->info("\nDONE. MEDICATION->FARMASI: {$medDone}  | BHP->INVENTORI: {$bhpDone}");
        $this->line('inventory_stocks total: ' . DB::table('inventory_stocks')->count());

        return self::SUCCESS;
    }
}
