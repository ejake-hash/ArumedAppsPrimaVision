<?php

namespace App\Console\Commands;

use App\Services\SatusehatService;
use Illuminate\Console\Command;

/**
 * Backfill historis Satu Sehat massal lewat terminal: kirim N kunjungan eligible
 * (SELESAI + NIK pasien + diagnosis ICD-10 + NIK dokter, belum SYNCED), terlama
 * dulu, dalam satu SatusehatSyncLog bertipe BACKFILL. Termasuk me-retry visit
 * FAILED yang eligible.
 *
 *   php artisan satusehat:backfill                       → s/d 1000 kunjungan
 *   php artisan satusehat:backfill --limit=5000          → maksimum per run (cap 5000)
 *   php artisan satusehat:backfill --from=2026-06-01 --to=2026-06-12
 *   php artisan satusehat:backfill --count               → hanya tampilkan sisa eligible
 *
 * WAJIB Satu Sehat aktif (server prod). Untuk >5000, jalankan beberapa kali —
 * tiap run mengambil yang terlama dulu.
 */
class SatusehatBackfill extends Command
{
    protected $signature = 'satusehat:backfill
                            {--limit=1000 : Maksimum kunjungan diproses (cap 5000)}
                            {--from= : Tanggal mulai (YYYY-MM-DD), opsional}
                            {--to= : Tanggal akhir (YYYY-MM-DD), opsional}
                            {--count : Hanya hitung sisa eligible, tanpa mengirim}';

    protected $description = 'Backfill kunjungan historis eligible ke Satu Sehat via terminal.';

    public function handle(SatusehatService $service): int
    {
        $service->boot();

        if (! $service->isEnabled()) {
            $this->warn('Integrasi Satu Sehat belum aktif — backfill dilewati.');
            return self::SUCCESS;
        }

        $from = $this->option('from') ?: null;
        $to   = $this->option('to') ?: null;

        if ($this->option('count')) {
            $c = $service->countBackfillEligible($from, $to);
            $this->info("Eligible backfill: {$c['eligible']} kunjungan"
                . (($from || $to) ? " (rentang {$from} … {$to})" : '') . '.');
            return self::SUCCESS;
        }

        $limit = max(1, min((int) $this->option('limit'), 5000));

        $this->info("Mulai backfill — maksimum {$limit} kunjungan"
            . (($from || $to) ? " (rentang {$from} … {$to})" : '') . '. Ini bisa makan waktu…');

        try {
            $log = $service->backfillSync($limit, $from, $to);
        } catch (\Throwable $e) {
            $this->error('Backfill gagal: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info("Backfill selesai: status={$log->status} terkirim={$log->total_sent} gagal={$log->total_failed}");
        if ($log->notes) {
            $this->line('Catatan: ' . $log->notes);
        }

        return self::SUCCESS;
    }
}
