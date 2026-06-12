<?php

namespace App\Console\Commands;

use App\Services\SatusehatService;
use Illuminate\Console\Command;

/**
 * Resolve IHS pasien massal lewat terminal (NIK → IHS Kemenkes, di-cache di
 * patients.satusehat_ihs). Dipakai untuk mengisi ribuan pasien sekaligus tanpa
 * harus klik tombol "Resolve IHS" di UI berulang-ulang.
 *
 *   php artisan satusehat:resolve-ihs                  → proses s/d 5000 pasien
 *   php artisan satusehat:resolve-ihs --limit=10000    → naikkan batas total
 *   php artisan satusehat:resolve-ihs --chunk=1000     → besar batch per panggilan
 *
 * Berhenti rapi bila: target tercapai, antrean habis, satu chunk tak meresolve
 * apa pun (sisanya kemungkinan NIK tak valid / belum terdaftar di Kemenkes),
 * atau integrasi error (token/jaringan). WAJIB Satu Sehat aktif (server prod).
 */
class SatusehatResolveIhs extends Command
{
    protected $signature = 'satusehat:resolve-ihs
                            {--limit=5000 : Maksimum pasien diproses total}
                            {--chunk=500 : Jumlah pasien per panggilan (cap 1000)}';

    protected $description = 'Resolve IHS pasien massal (NIK → IHS) via terminal, di-chunk.';

    public function handle(SatusehatService $service): int
    {
        $service->boot();

        if (! $service->isEnabled()) {
            $this->warn('Integrasi Satu Sehat belum aktif — resolve dilewati.');
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $chunk = max(1, min((int) $this->option('chunk'), 1000));

        $totalProcessed = 0;
        $totalResolved  = 0;
        $totalNotFound  = 0;

        $this->info("Mulai resolve IHS — target {$limit} pasien, chunk {$chunk}.");

        while ($totalProcessed < $limit) {
            $take = min($chunk, $limit - $totalProcessed);

            try {
                $r = $service->resolveIhsBatch($take);
            } catch (\Throwable $e) {
                $this->error('Berhenti — error integrasi: ' . $e->getMessage());
                break;
            }

            $totalProcessed += $r['processed'];
            $totalResolved  += $r['resolved'];
            $totalNotFound  += $r['not_found'];

            $this->line(sprintf(
                '  +%d diproses (resolve %d, tak ketemu %d) — sisa bisa di-resolve: %d',
                $r['processed'], $r['resolved'], $r['not_found'], $r['remaining_resolvable']
            ));

            if (! empty($r['error'])) {
                $this->error('Berhenti — error integrasi: ' . $r['error']);
                break;
            }
            // Antrean habis, atau satu chunk penuh tanpa hasil → sisanya kemungkinan
            // NIK tak valid/belum terdaftar; tak ada gunanya menembak Kemenkes lagi.
            if ($r['processed'] === 0 || $r['remaining_resolvable'] === 0 || $r['resolved'] === 0) {
                break;
            }
        }

        $this->info(sprintf(
            'Selesai: %d diproses, %d ter-resolve, %d tak ketemu. Sisa pasien tanpa IHS: %d.',
            $totalProcessed, $totalResolved, $totalNotFound,
            \App\Models\Patient::whereNull('satusehat_ihs')->count()
        ));

        return self::SUCCESS;
    }
}
