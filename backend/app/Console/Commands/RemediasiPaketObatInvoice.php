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
 * Remediasi invoice paket BEDAH lama agar OBAT komponen paket ikut tertagih.
 *
 * Sebelum perbaikan, syncVisitPackageSnapshot MELEWATI obat untuk paket BEDAH →
 * snapshot lama tak punya baris MEDICATION → obat (mis. injeksi Aflibercept/Bevacizumab,
 * komponen termahal) hilang dari tagihan / dinetralkan → UNDERCHARGE.
 *
 * Command ini, per invoice paket BEDAH:
 *   1) BACKFILL baris MEDICATION yang hilang di snapshot dari komposisi master paket
 *      (additive & idempoten — tak menyentuh PROCEDURE/BHP/IOL yang sudah ada),
 *      unit_price = getPrice tarif TERKINI (dicatat: bukan harga era-planning).
 *   2) reconsolidateInvoice → bangun ulang via buildLines terbaru (obat masuk + basis
 *      diskon benar → net = harga jual paket).
 *
 * Cakupan: SEMUA penjamin (BPJS/UMUM/asuransi) termasuk invoice PAID (keputusan user).
 * Untuk invoice PAID, selisih akan muncul sebagai SISA tagihan (kebijakan tagih-selisih /
 * write-off di luar command — laporkan eksplisit).
 *
 * Default DRY-RUN (preview before/after, semua dalam transaksi lalu rollback).
 */
class RemediasiPaketObatInvoice extends Command
{
    protected $signature = 'kasir:remediasi-paket-obat
                            {--apply : Tulis perubahan (default: dry-run preview saja)}
                            {--id= : Batasi ke satu invoice_id tertentu}';

    protected $description = 'Backfill obat komposisi paket BEDAH ke snapshot + bangun ulang invoice lama agar obat (mis. injeksi) ikut tertagih.';

    public function handle(KasirService $svc): int
    {
        $apply = (bool) $this->option('apply');
        $id    = $this->option('id');

        $query = BillingInvoice::with(['items', 'visit.surgeryPackageSnapshots.items'])
            ->where('status', '!=', 'CANCELLED')
            ->whereHas('visit.surgeryPackageSnapshots', fn ($s) => $s->where('package_type', 'BEDAH'));
        if ($id) {
            $query->where('id', $id);
        }
        $invoices = $query->get();

        if ($invoices->isEmpty()) {
            $this->info('Tidak ada invoice paket BEDAH yang perlu diremediasi.');
            return self::SUCCESS;
        }

        $this->info(($apply ? '[APPLY] ' : '[DRY RUN] ') . "Kandidat: {$invoices->count()} invoice paket BEDAH.");
        $this->line(str_repeat('-', 96));

        $metrics = fn ($inv) => [
            'total'   => (float) $inv->total,
            'covered' => (float) $inv->covered_amount,
            'sisa'    => max(0.0, (float) $inv->total - (float) $inv->covered_amount - (float) $inv->paid_amount),
            'obat'    => $inv->items->where('item_type', 'OBAT')->sum('total_price'),
        ];
        $fmt = fn ($m) => 'total=' . number_format($m['total'], 0, ',', '.')
            . ' covered=' . number_format($m['covered'], 0, ',', '.')
            . ' sisa=' . number_format($m['sisa'], 0, ',', '.')
            . ' obat=' . number_format($m['obat'], 0, ',', '.');

        if (! $apply) {
            DB::beginTransaction();
        }

        $ok = 0; $fail = 0; $backfilled = 0; $paidShifted = 0;
        foreach ($invoices as $inv) {
            $before = $metrics($inv);
            try {
                $added = $this->backfillSnapshotObat($svc, $inv->visit);
                $backfilled += $added;
                $rebuilt = $svc->reconsolidateInvoice($inv->id);
                $after   = $metrics($rebuilt);
                $this->line("{$inv->invoice_number}  [{$inv->status}]  (+{$added} obat snapshot)");
                $this->line('  sebelum: ' . $fmt($before));
                $this->line('  sesudah: ' . $fmt($after));
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
        if (! $apply) {
            DB::rollBack();
            $this->warn("DRY RUN — tidak ada perubahan ditulis. {$ok} siap (+{$backfilled} baris obat snapshot), {$fail} gagal, {$paidShifted} PAID akan bersisa. Jalankan ulang dengan --apply untuk menerapkan.");
            return self::SUCCESS;
        }
        $this->info("Selesai. {$ok} invoice dibangun ulang (+{$backfilled} baris obat snapshot), {$fail} gagal, {$paidShifted} PAID kini bersisa.");
        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Tambah baris MEDICATION yang HILANG di snapshot paket BEDAH dari komposisi master.
     * Additive & idempoten: hanya item_id yang belum ada. Return jumlah baris ditambahkan.
     */
    private function backfillSnapshotObat(KasirService $svc, $visit): int
    {
        if (! $visit) {
            return 0;
        }
        $added = 0;
        foreach ($visit->surgeryPackageSnapshots->where('package_type', VisitSurgeryPackage::TYPE_BEDAH) as $snap) {
            if (! $snap->source_surgery_package_id) {
                continue;
            }
            $pkg = SurgeryPackage::with('items')->find($snap->source_surgery_package_id);
            if (! $pkg) {
                continue;
            }
            $existing = $snap->items->where('item_type', 'MEDICATION')->pluck('item_id')->all();
            foreach ($pkg->items->where('item_type', 'MEDICATION') as $pi) {
                if (in_array($pi->item_id, $existing, true)) {
                    continue;
                }
                VisitSurgeryPackageItem::create([
                    'visit_surgery_package_id' => $snap->id,
                    'item_type'                => 'MEDICATION',
                    'item_id'                  => $pi->item_id,
                    'quantity'                 => $pi->quantity ?? 1,
                    'unit_price'               => $svc->getPrice('medication', $pi->item_id, $visit->guarantor_type, $visit->insurer_id),
                    'notes'                    => $pi->notes ?? null,
                ]);
                $added++;
            }
            $snap->load('items');
            $snap->recalcTotalBasePrice();
        }
        return $added;
    }
}
