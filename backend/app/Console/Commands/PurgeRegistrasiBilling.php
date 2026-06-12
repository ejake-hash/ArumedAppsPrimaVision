<?php

namespace App\Console\Commands;

use App\Services\KasirService;
use Illuminate\Console\Command;

/**
 * Bersihkan baris tagihan "Biaya Pendaftaran" warisan generator lama
 * buildRegistrasiLines (sudah DIHAPUS di c0322bc) yang masih membeku di invoice
 * lama (dibuat sebelum fix deploy). Generator sudah tak ada → invoice BARU bersih;
 * command ini membenahi yang LAMA.
 *
 * Target presisi: item_type=REGISTRASI + description='Biaya Pendaftaran'. Item
 * REGISTRASI yang ditambah MANUAL kasir (deskripsi lain) TIDAK tersentuh.
 *
 * Default DRY-RUN. Invoice PAID/PARTIALLY_PAID DILEWATI kecuali --include-paid
 * (menghapus dari invoice lunas mengubah total historis — keputusan eksplisit).
 */
class PurgeRegistrasiBilling extends Command
{
    protected $signature = 'kasir:purge-registrasi
                            {--apply : Tulis perubahan (default: dry-run preview saja)}
                            {--include-paid : Ikut bersihkan invoice PAID/PARTIALLY_PAID (ubah total historis)}';

    protected $description = "Hapus baris tagihan 'Biaya Pendaftaran' warisan generator lama dari invoice + hitung ulang total.";

    public function handle(KasirService $kasir): int
    {
        $apply       = (bool) $this->option('apply');
        $includePaid = (bool) $this->option('include-paid');

        $this->info('Purge baris tagihan "Biaya Pendaftaran" warisan (buildRegistrasiLines lama).');
        $this->line('Mode      : ' . ($apply ? 'APPLY (menulis)' : 'DRY-RUN (preview)'));
        $this->line('Paid       : ' . ($includePaid ? 'IKUT dibersihkan' : 'DILEWATI (default)'));
        $this->newLine();

        $report = $kasir->purgeLegacyRegistrasi($apply, $includePaid);

        if ($report['matched'] === 0) {
            $this->info('Tidak ada baris "Biaya Pendaftaran" — tidak ada yang perlu dibersihkan.');
            return self::SUCCESS;
        }

        $this->table(
            ['Invoice', 'Status', 'Baris', 'Aksi'],
            array_map(fn ($r) => [$r['invoice'], $r['status'], $r['lines'], $r['action']], $report['invoices'])
        );

        $this->newLine();
        $this->line("Total baris cocok : {$report['matched']}");
        $this->line('Dihapus           : ' . ($apply ? $report['deleted'] : '0 (dry-run)'));
        if ($report['skipped_paid'] > 0) {
            $this->warn("Dilewati (PAID)   : {$report['skipped_paid']} baris — jalankan dengan --include-paid bila ingin ikut dibersihkan.");
        }
        if (! $apply) {
            $this->newLine();
            $this->comment('Dry-run. Tambahkan --apply untuk mengeksekusi.');
        }

        return self::SUCCESS;
    }
}
