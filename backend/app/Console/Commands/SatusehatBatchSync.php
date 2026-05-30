<?php

namespace App\Console\Commands;

use App\Services\SatusehatService;
use Illuminate\Console\Command;

/**
 * Fase 5 Bridging Satu Sehat — batch sync harian (Encounter/Condition/obat).
 *
 *   satusehat:batch-sync            → batchSync('AUTO')   (dijadwal 23:59 WIB)
 *   satusehat:batch-sync --retry    → retry log PARTIAL/FAILED terbaru (01:00 WIB)
 *
 * Aman bila integrasi belum aktif: SatusehatService melempar 503 → command
 * keluar tanpa gagalkan scheduler (status SKIPPED di output).
 */
class SatusehatBatchSync extends Command
{
    protected $signature = 'satusehat:batch-sync
                            {--retry : Retry sync-log PARTIAL/FAILED terakhir, bukan batch baru}';

    protected $description = 'Kirim kunjungan SELESAI hari ini ke Satu Sehat (FHIR Bundle). --retry untuk ulangi log gagal.';

    public function handle(SatusehatService $service): int
    {
        $service->boot();

        if (! $service->isEnabled()) {
            $this->warn('Integrasi Satu Sehat belum aktif — batch dilewati.');
            return self::SUCCESS;
        }

        try {
            if ($this->option('retry')) {
                $log = $service->retryLatestUnfinished();
                if (! $log) {
                    $this->info('Tidak ada sync-log PARTIAL/FAILED untuk di-retry.');
                    return self::SUCCESS;
                }
                $this->info("Retry log {$log->id}: status={$log->status} sent={$log->total_sent} failed={$log->total_failed}");
                return self::SUCCESS;
            }

            $log = $service->batchSync('AUTO');
            $this->info("Batch selesai: status={$log->status} sent={$log->total_sent} failed={$log->total_failed}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Batch sync gagal: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
