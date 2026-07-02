<?php

namespace App\Console\Commands;

use App\Services\PharmacySaleService;
use Illuminate\Console\Command;

/**
 * Lepas penjualan obat PENDING (channel KASIR) yang menggantung > N jam.
 *
 *   pharmacy:release-stale-pending [--hours=24]
 *
 * PENDING menahan reserve stok (createPending → persistSale consume) TANPA auto-release;
 * bila kasir tak pernah settlePayment maupun cancel, stok fisik vs sistem drift & alert
 * low-stock salah. Command ini (dijadwalkan) membatalkan yang basi lewat cancel() yang
 * me-restock via consumed_batches. Hanya menyentuh PENDING (paid_amount=0) → aman.
 */
class ReleaseStalePharmacyPending extends Command
{
    protected $signature = 'pharmacy:release-stale-pending {--hours=24}';

    protected $description = 'Batalkan penjualan obat PENDING (channel KASIR) yang menggantung > N jam & kembalikan stok reserve.';

    public function handle(PharmacySaleService $service): int
    {
        $hours = (int) $this->option('hours');
        $hours = $hours > 0 ? $hours : 24;

        $r = $service->releaseStalePending($hours);
        $this->info("Release stale pending (TTL {$hours} jam): dilepas {$r['released']}, gagal {$r['failed']}, discan {$r['scanned']}.");

        return self::SUCCESS;
    }
}
