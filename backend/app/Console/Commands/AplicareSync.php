<?php

namespace App\Console\Commands;

use App\Services\BpjsAplicareService;
use Illuminate\Console\Command;

/**
 * Rekonsiliasi BPJS Aplicare — push ketersediaan SEMUA ruang ke BPJS.
 *
 *   aplicare:sync             → sinkron seluruh ruang aktif (jaring pengaman)
 *
 * Push utama bersifat event-driven (Job PushAplicareRoom dari RanapService saat
 * admit/transfer/discharge). Command ini dijadwalkan via cron (mis. tiap 30 menit)
 * untuk menutup celah bila ada push yang gagal/terlewat. Aman bila integrasi
 * belum aktif: BpjsAplicareService no-op → command keluar SUCCESS (SKIPPED).
 */
class AplicareSync extends Command
{
    protected $signature = 'aplicare:sync';

    protected $description = 'Sinkron ketersediaan tempat tidur semua ruang ke BPJS Aplicare (rekonsiliasi).';

    public function handle(BpjsAplicareService $service): int
    {
        $service->boot();

        if (! $service->isEnabled()) {
            $this->warn('Integrasi Aplicare belum aktif — sinkron dilewati.');
            return self::SUCCESS;
        }

        try {
            $result = $service->syncAll();
            $this->info("Sinkron selesai: terkirim {$result['sent']}, gagal {$result['failed']}, dilewati {$result['skipped']}.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Sinkron Aplicare gagal: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
