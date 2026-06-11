<?php

namespace App\Console\Commands;

use App\Models\BillingInvoice;
use App\Models\SurgeryPackage;
use App\Models\VisitSurgeryPackage;
use App\Models\VisitSurgeryPackageItem;
use App\Services\KasirService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Replace kwitansi lama agar mengikuti komposisi paket bedah TERBARU
 * (pasca sinkron `paket:import-excel` dari Docs/PAKET BEDAH.xlsx).
 *
 * Per invoice ber-paket BEDAH:
 *   1) Snapshot per-visit (visit_surgery_package items) di-REPLACE penuh dari
 *      komposisi master paket terkini (item_type/item_id/qty/notes), unit_price
 *      via getPrice tarif penjamin visit. Header snapshot (sell_price, varian
 *      tarif terpilih, label) TIDAK disentuh — harga jual paket saat planning tetap.
 *   2) reconsolidateInvoice → semua baris kwitansi dibangun ulang + DISKON_PAKET
 *      menyesuaikan → net umumnya tetap = harga jual paket.
 *
 * Cakupan: SEMUA penjamin termasuk invoice PAID (keputusan user); PAID yang jadi
 * bersisa dilaporkan eksplisit. Default DRY-RUN (transaksi + rollback).
 */
class RemediasiPaketExcelInvoice extends Command
{
    protected $signature = 'kasir:remediasi-paket-excel
                            {--apply : Tulis perubahan (default: dry-run preview saja)}
                            {--id= : Batasi ke satu invoice_id tertentu}';

    protected $description = 'Re-sync snapshot paket BEDAH per-visit dari komposisi master terbaru + bangun ulang invoice (replace kwitansi lama).';

    public function handle(KasirService $svc): int
    {
        $apply = (bool) $this->option('apply');
        $id    = $this->option('id');

        $query = BillingInvoice::with(['items', 'visit.surgeryPackageSnapshots.items'])
            ->where('status', '!=', 'CANCELLED')
            ->whereHas('visit.surgeryPackageSnapshots', fn ($s) => $s->where('package_type', VisitSurgeryPackage::TYPE_BEDAH));
        if ($id) {
            $query->where('id', $id);
        }
        $invoices = $query->get();

        if ($invoices->isEmpty()) {
            $this->info('Tidak ada invoice paket BEDAH yang perlu di-replace.');
            return self::SUCCESS;
        }

        $this->info(($apply ? '[APPLY] ' : '[DRY RUN] ') . "Kandidat: {$invoices->count()} invoice paket BEDAH.");
        $this->line(str_repeat('-', 96));

        $metrics = fn ($inv) => [
            'total'   => (float) $inv->total,
            'covered' => (float) $inv->covered_amount,
            'sisa'    => max(0.0, (float) $inv->total - (float) $inv->covered_amount - (float) $inv->paid_amount),
        ];
        $fmt = fn ($m) => 'total=' . number_format($m['total'], 0, ',', '.')
            . ' covered=' . number_format($m['covered'], 0, ',', '.')
            . ' sisa=' . number_format($m['sisa'], 0, ',', '.');

        if (! $apply) {
            DB::beginTransaction();
        }

        $ok = 0; $fail = 0; $resynced = 0; $paidShifted = 0; $totalShifted = 0;
        foreach ($invoices as $inv) {
            $before = $metrics($inv);
            try {
                $resynced += $this->resyncSnapshotItems($svc, $inv->visit);
                $rebuilt   = $svc->reconsolidateInvoice($inv->id);
                $after     = $metrics($rebuilt);
                $delta     = $after['total'] - $before['total'];
                $this->line("{$inv->invoice_number}  [{$inv->status}]"
                    . (abs($delta) >= 1 ? sprintf('  Δtotal=%+s', number_format($delta, 0, ',', '.')) : '  (total tetap)'));
                $this->line('  sebelum: ' . $fmt($before));
                $this->line('  sesudah: ' . $fmt($after));
                if (abs($delta) >= 1) {
                    $totalShifted++;
                }
                if ($inv->status === 'PAID' && $after['sisa'] > 0.5) {
                    $this->warn('  ⚠ PAID → muncul SISA ' . number_format($after['sisa'], 0, ',', '.') . ' (perlu kebijakan tagih-selisih/write-off)');
                    $paidShifted++;
                }
                $ok++;
            } catch (\Throwable $e) {
                $this->error("  GAGAL {$inv->invoice_number}: " . $e->getMessage());
                $fail++;
            }
        }

        $this->line(str_repeat('-', 96));
        $ringkas = "{$ok} invoice diproses ({$resynced} snapshot di-resync, {$totalShifted} total berubah, {$paidShifted} PAID bersisa), {$fail} gagal.";
        if (! $apply) {
            DB::rollBack();
            $this->warn("DRY RUN — tidak ada perubahan ditulis. {$ringkas} Jalankan ulang dengan --apply.");
            return self::SUCCESS;
        }
        $this->info("Selesai. {$ringkas}");
        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * REPLACE seluruh item snapshot paket BEDAH dari komposisi master terkini.
     * Header snapshot (sell_price/varian/label) dipertahankan. Return jumlah snapshot di-resync.
     */
    private function resyncSnapshotItems(KasirService $svc, $visit): int
    {
        if (! $visit) {
            return 0;
        }
        $priceType = ['PROCEDURE' => 'procedure', 'MEDICATION' => 'medication', 'BHP' => 'bhp', 'IOL' => 'iol'];
        $n = 0;
        foreach ($visit->surgeryPackageSnapshots->where('package_type', VisitSurgeryPackage::TYPE_BEDAH) as $snap) {
            if (! $snap->source_surgery_package_id) {
                continue;   // snapshot tanpa rujukan master (legacy) — biarkan apa adanya
            }
            $pkg = SurgeryPackage::with('items')->find($snap->source_surgery_package_id);
            if (! $pkg) {
                continue;
            }
            $snap->items()->delete();
            foreach ($pkg->items as $pi) {
                VisitSurgeryPackageItem::create([
                    'visit_surgery_package_id' => $snap->id,
                    'item_type'                => $pi->item_type,
                    'item_id'                  => $pi->item_id,
                    'quantity'                 => $pi->quantity ?? 1,
                    'unit_price'               => $svc->getPrice($priceType[$pi->item_type] ?? 'procedure', $pi->item_id, $visit->guarantor_type, $visit->insurer_id),
                    'notes'                    => $pi->notes ?? null,
                ]);
            }
            $snap->load('items');
            $snap->recalcTotalBasePrice();
            $n++;
        }
        return $n;
    }
}
