<?php

namespace App\Console\Commands;

use App\Mail\KasirBacklogReport;
use App\Models\Visit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Laporan harian "tunggakan kasir": kunjungan yang BELUM tutup kasir
 * (current_station != SELESAI, billing belum dikunci) beserta UMUR-nya.
 *
 * Tujuan kepatuhan: penundaan tutup kasir jadi terlihat oleh supervisor tiap
 * hari — bukan sekadar mengandalkan window papan. Dijadwalkan 17.00 WIB
 * (routes/console.php). No-op aman bila tak ada penerima / tak ada tunggakan.
 *
 * Penerima diambil dari env KASIR_BACKLOG_REPORT_TO (boleh dipisah koma),
 * fallback MAIL_FROM_ADDRESS. Tanpa penerima valid → hanya log, tak kirim.
 */
class ReportKasirBacklog extends Command
{
    protected $signature = 'kasir:report-backlog
                            {--dry-run : Tampilkan ringkasan tanpa mengirim email}
                            {--threshold=7 : Ambang umur (hari) tagihan dianggap "lewat target"}';

    protected $description = 'Laporan harian tagihan yang belum tutup kasir beserta umurnya; kirim ke supervisor (KASIR_BACKLOG_REPORT_TO).';

    public function handle(): int
    {
        $dry       = (bool) $this->option('dry-run');
        $threshold = max(0, (int) $this->option('threshold'));

        // Kunjungan belum SELESAI + billing belum dikunci (selaras scope
        // boardVisibleOpenBilling klausa ke-3) yang sudah pernah sampai KASIR.
        $visits = Visit::query()
            ->whereNull('deleted_at')
            ->where('current_station', '!=', 'SELESAI')
            ->where(function ($iv) {
                $iv->whereDoesntHave('billingInvoice')
                   ->orWhereHas('billingInvoice', fn ($bi) => $bi->whereNotIn('status', ['PAID', 'PARTIALLY_PAID', 'CANCELLED']));
            })
            ->whereHas('queues', fn ($q) => $q->where('station', 'KASIR'))
            ->with(['patient:id,name,no_rm', 'billingInvoice'])
            ->get();

        $today = today();
        $rows = $visits->map(function (Visit $v) use ($today) {
            // Umur = sejak baris antrean KASIR pertama dibuat (cermin papan kasir).
            $firstKasir = $v->queues->where('station', 'KASIR')->min('created_at') ?? $v->created_at;
            $age = $today->diffInDays(\Illuminate\Support\Carbon::parse($firstKasir)->startOfDay());
            $inv = $v->billingInvoice;
            return [
                'visit_id' => $v->id,
                'name'     => $v->patient?->name ?? '—',
                'no_rm'    => $v->patient?->no_rm ?? '—',
                'status'   => $inv?->status ?? 'TANPA-INVOICE',
                'total'    => (float) ($inv?->total ?? 0),
                'age'      => $age,
                'since'    => \Illuminate\Support\Carbon::parse($firstKasir)->format('Y-m-d'),
            ];
        })->sortByDesc('age')->values();

        $overdue = $rows->where('age', '>', $threshold)->values();
        $sumTotal = $rows->sum('total');

        if ($rows->isEmpty()) {
            $this->info('Tidak ada tagihan tertunda di kasir. Tidak ada laporan dikirim.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%sTunggakan kasir: %d tagihan (Rp %s), %d lewat %d hari.',
            $dry ? '[DRY RUN] ' : '',
            $rows->count(),
            number_format($sumTotal, 0, ',', '.'),
            $overdue->count(),
            $threshold
        ));
        foreach ($rows->take(15) as $r) {
            $this->line(sprintf('  - H+%-3d %-28s RM %-10s %-14s Rp %s', $r['age'], mb_substr($r['name'], 0, 28), $r['no_rm'], $r['status'], number_format($r['total'], 0, ',', '.')));
        }

        $recipients = $this->recipients();
        if (empty($recipients)) {
            $this->warn('KASIR_BACKLOG_REPORT_TO / MAIL_FROM_ADDRESS kosong — laporan TIDAK dikirim (hanya ditampilkan).');
            return self::SUCCESS;
        }

        if ($dry) {
            $this->info('[DRY RUN] Akan dikirim ke: ' . implode(', ', $recipients));
            return self::SUCCESS;
        }

        try {
            Mail::to($recipients)->send(new KasirBacklogReport(
                rows: $rows->all(),
                overdueCount: $overdue->count(),
                sumTotal: $sumTotal,
                threshold: $threshold,
                reportDate: $today->format('Y-m-d'),
            ));
            $this->info('Laporan terkirim ke: ' . implode(', ', $recipients));
        } catch (\Throwable $e) {
            Log::error('kasir:report-backlog gagal kirim', ['error' => $e->getMessage()]);
            $this->error('Gagal mengirim laporan: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function recipients(): array
    {
        // config (bukan env langsung) → aman saat config:cache.
        $raw = config('mail.kasir_backlog_to') ?: config('mail.from.address');
        if (! $raw) {
            return [];
        }
        return collect(explode(',', $raw))
            ->map(fn ($e) => trim($e))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();
    }
}
