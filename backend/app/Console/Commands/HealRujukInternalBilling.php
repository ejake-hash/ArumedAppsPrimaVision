<?php

namespace App\Console\Commands;

use App\Services\KasirService;
use Illuminate\Console\Command;

/**
 * Sembuhkan pasien rujuk-internal same-day yang terlanjur punya >1 invoice (dibuat
 * sebelum fitur konsolidasi). Default = dry-run (laporan). Pakai --apply untuk eksekusi.
 *
 *   php artisan kasir:heal-rujuk-internal          # laporan saja
 *   php artisan kasir:heal-rujuk-internal --apply  # batalkan invoice dobel + gabung 1
 */
class HealRujukInternalBilling extends Command
{
    protected $signature = 'kasir:heal-rujuk-internal {--apply : Eksekusi (default hanya laporan/dry-run)}';

    protected $description = 'Gabungkan tagihan rujuk-internal same-day yang masih dobel jadi 1 kwitansi (anchor)';

    public function handle(KasirService $kasir): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'Mode APPLY — mengeksekusi penggabungan…' : 'Mode DRY-RUN — laporan saja (pakai --apply untuk eksekusi).');

        $r = $kasir->healInternalReferralBilling($apply);

        foreach ($r['details'] as $line) {
            $this->line('  ' . $line);
        }
        $this->newLine();
        $this->table(
            ['Rantai', 'Sudah OK', $apply ? 'Digabung' : 'Akan digabung', 'Lewati (paid)', 'Error'],
            [[$r['chains'], $r['already_ok'], $apply ? $r['healed'] : $r['would_heal'], $r['skipped_paid'], $r['errors']]]
        );

        if (! $apply && $r['would_heal'] > 0) {
            $this->warn("Jalankan ulang dengan --apply untuk menggabungkan {$r['would_heal']} rantai.");
        }
        if ($r['skipped_paid'] > 0) {
            $this->warn("{$r['skipped_paid']} rantai dilewati karena sudah ada pembayaran — tangani manual.");
        }

        return self::SUCCESS;
    }
}
