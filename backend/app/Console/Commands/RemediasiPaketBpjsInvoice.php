<?php

namespace App\Console\Commands;

use App\Models\BillingInvoice;
use App\Services\KasirService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remediasi invoice paket BPJS lama agar mengikuti skema billing terbaru:
 * prosedur paket bedah ditagih sebagai baris POSITIF (muncul di kwitansi),
 * baris DISKON_PAKET hantu hilang, dan covered_amount = total (pasien tetap 0).
 *
 * Default DRY-RUN (preview before/after tanpa menulis). Pakai --apply untuk
 * benar-benar membangun ulang. Hanya menyentuh kunjungan BPJS NON-COB yang punya
 * snapshot paket BEDAH (termasuk invoice PAID — alur Batalkan biasa menolak PAID).
 */
class RemediasiPaketBpjsInvoice extends Command
{
    protected $signature = 'kasir:remediasi-paket-bpjs
                            {--apply : Tulis perubahan (default: dry-run preview saja)}
                            {--id= : Batasi ke satu invoice_id tertentu}';

    protected $description = 'Bangun ulang invoice paket BPJS lama ke skema terbaru (prosedur paket positif, tanpa diskon hantu, covered=total).';

    public function handle(KasirService $svc): int
    {
        $apply = (bool) $this->option('apply');
        $id    = $this->option('id');

        $query = BillingInvoice::with(['items', 'visit.visitCob'])
            ->where('status', '!=', 'CANCELLED')
            ->whereHas('visit', function ($v) {
                $v->where('guarantor_type', 'BPJS')
                  ->whereHas('surgeryPackageSnapshots', fn ($s) => $s->where('package_type', 'BEDAH'));
            });
        if ($id) {
            $query->where('id', $id);
        }

        // Kecualikan COB aktif (split tanggungan via coverages, bukan jalur ini).
        $invoices = $query->get()->filter(fn ($inv) => ! ($inv->visit?->visitCob?->is_active));

        if ($invoices->isEmpty()) {
            $this->info('Tidak ada invoice paket BPJS non-COB yang perlu diremediasi.');
            return self::SUCCESS;
        }

        $this->info(($apply ? '[APPLY] ' : '[DRY RUN] ') . "Kandidat: {$invoices->count()} invoice.");
        $this->line(str_repeat('-', 92));

        $metrics = fn ($inv) => [
            'total'    => (float) $inv->total,
            'covered'  => (float) $inv->covered_amount,
            'sisa'     => max(0.0, (float) $inv->total - (float) $inv->covered_amount - (float) $inv->paid_amount),
            'tindakan' => $inv->items->where('item_type', 'TINDAKAN')->count(),
            'diskon'   => (float) $inv->items->where('item_type', 'DISKON_PAKET')->sum('total_price'),
        ];

        $fmt = fn ($m) => "total=" . number_format($m['total'], 0, ',', '.')
            . " covered=" . number_format($m['covered'], 0, ',', '.')
            . " sisa=" . number_format($m['sisa'], 0, ',', '.')
            . " tindakan={$m['tindakan']} diskon=" . number_format($m['diskon'], 0, ',', '.');

        // DRY RUN: jalankan dalam transaksi luar lalu rollback (rebuild pakai savepoint).
        if (! $apply) {
            DB::beginTransaction();
        }

        $ok = 0;
        $fail = 0;
        foreach ($invoices as $inv) {
            $before = $metrics($inv);
            try {
                $rebuilt = $svc->reconsolidateInvoice($inv->id);
                $after   = $metrics($rebuilt);
                $this->line("{$inv->invoice_number}  [{$inv->status}]");
                $this->line("  sebelum: " . $fmt($before));
                $this->line("  sesudah: " . $fmt($after));
                $ok++;
            } catch (\Throwable $e) {
                $this->error("  GAGAL {$inv->invoice_number}: " . $e->getMessage());
                $fail++;
            }
        }

        if (! $apply) {
            DB::rollBack();
            $this->line(str_repeat('-', 92));
            $this->warn("DRY RUN — tidak ada perubahan ditulis. {$ok} siap, {$fail} gagal. Jalankan ulang dengan --apply untuk menerapkan.");
            return self::SUCCESS;
        }

        $this->line(str_repeat('-', 92));
        $this->info("Selesai. {$ok} invoice dibangun ulang, {$fail} gagal.");
        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
