<?php

namespace App\Console\Commands;

use App\Models\BillingInvoice;
use App\Models\Queue;
use App\Models\Visit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remediasi backlog: tutup (SELESAI) kunjungan BPJS yang NYANGKUT di KASIR.
 *
 * Latar: penyelesaian kasir BPJS sepenuhnya MANUAL (kasir buka baris → invoice
 * dibuat lazily → klik "Konfirmasi BPJS" → SELESAI). Karena BPJS tak ada uang
 * ditagih, baris ini sering tak dibuka → menumpuk sebagai WAITING di antrean
 * kasir, lintas-hari, dan MEMBLOKIR pasien daftar-ulang (guard registerVisit
 * menolak pasien yang punya kunjungan aktif non-RANAP).
 *
 * "Buka kunci" = dorong visit nyangkut itu ke current_station=SELESAI:
 *   1. (bila ada invoice DRAFT/FINALIZED) tandai PAID(BPJS, Rp0, covered=total)
 *      — sekadar mencatat pendapatan internal; klaim INA-CBG tetap manual di luar app.
 *   2. tutup baris antrean KASIR yang masih terbuka (WAITING/CALLED/IN_PROGRESS → COMPLETED).
 *   3. set visit.current_station = 'SELESAI'.  ←★ titik buka-kunci
 *
 * Sengaja TIDAK memakai confirmBpjsCoverage(): method itu merutekan ke FARMASI
 * bila ada resep (alur pasien HIDUP), padahal visit ini sudah ditinggalkan
 * berhari-hari → kita tutup LANGSUNG ke SELESAI.
 *
 * Idempoten (visit SELESAI dilewati). SAFETY: hanya menyentuh visit_date < hari ini.
 * Default DRY-RUN; menulis hanya dengan --apply.
 *
 *   php artisan kasir:tutup-bpjs-nyangkut                 (dry-run, KASIR/BPJS)
 *   php artisan kasir:tutup-bpjs-nyangkut --limit=5       (dry-run 5 teratas)
 *   php artisan kasir:tutup-bpjs-nyangkut --id=<uuid>     (satu visit)
 *   php artisan kasir:tutup-bpjs-nyangkut --apply         (TULIS perubahan)
 */
class TutupBpjsNyangkut extends Command
{
    protected $signature = 'kasir:tutup-bpjs-nyangkut
        {--station=KASIR : Stasiun sasaran (default KASIR)}
        {--id= : Batasi ke satu visit (uuid)}
        {--limit=0 : Batasi jumlah visit yang diproses (0 = semua)}
        {--apply : Tulis perubahan (tanpa ini = dry-run)}';

    protected $description = 'Tutup (SELESAI) kunjungan BPJS nyangkut di KASIR agar pasien bisa daftar lagi. Default DRY-RUN.';

    private const OPEN = ['WAITING', 'CALLED', 'IN_PROGRESS'];
    private const MARK_PAID_FROM = ['DRAFT', 'FINALIZED', 'PARTIALLY_PAID'];

    public function handle(): int
    {
        $apply   = (bool) $this->option('apply');
        $station = strtoupper((string) $this->option('station'));
        $id      = $this->option('id');
        $limit   = max(0, (int) $this->option('limit'));

        $q = Visit::query()
            ->whereHas('patient', fn ($x) => $x->where('name', '!=', 'Belum Terdaftar'))
            ->where('current_station', $station)
            ->where('guarantor_type', 'BPJS')
            ->whereDate('visit_date', '<', today())   // SAFETY: jangan sentuh hari ini
            ->with(['patient:id,name', 'billingInvoice'])
            ->orderBy('visit_date');

        if ($id) {
            $q->where('id', $id);
        }
        if ($limit > 0) {
            $q->limit($limit);
        }

        $visits = $q->get();

        $this->newLine();
        $this->line(sprintf('  Mode      : %s', $apply ? 'APPLY (MENULIS)' : 'DRY-RUN (tanpa perubahan)'));
        $this->line(sprintf('  Sasaran   : current_station=%s · BPJS · visit_date < %s', $station, today()->toDateString()));
        $this->line(sprintf('  Ditemukan : %d visit', $visits->count()));
        $this->newLine();

        if ($visits->isEmpty()) {
            $this->info('Tidak ada visit nyangkut yang cocok. Selesai.');

            return self::SUCCESS;
        }

        $targetVisitIds   = $visits->pluck('id');
        $targetPatientIds = $visits->pluck('patient_id')->unique();

        // Pasien yang MASIH terblokir setelah aksi ini (punya visit aktif lain di luar sasaran).
        $stillBlocked = Visit::whereIn('patient_id', $targetPatientIds)
            ->whereNotIn('id', $targetVisitIds)
            ->where('current_station', '!=', 'SELESAI')
            ->where(fn ($x) => $x->where('jenis_pelayanan', '!=', 'RANAP')->orWhereNull('jenis_pelayanan'))
            ->pluck('patient_id')->unique();

        $cPaid = 0; $cNoInv = 0; $cCancelledInv = 0; $sumPaid = 0.0; $cQueue = 0;
        $rows = [];

        foreach ($visits as $v) {
            $inv     = $v->billingInvoice;
            $invSt   = $inv->status ?? 'NONE';
            $openQ   = Queue::where('visit_id', $v->id)->where('station', $station)
                ->whereIn('status', self::OPEN)->count();
            $cQueue += $openQ > 0 ? 1 : 0;

            if ($inv && in_array($invSt, self::MARK_PAID_FROM, true)) {
                $plan = sprintf('inv %s→PAID(BPJS Rp%s) + SELESAI', $invSt, number_format((float) $inv->total, 0, ',', '.'));
                $cPaid++; $sumPaid += (float) $inv->total;
            } elseif ($inv && $invSt === 'CANCELLED') {
                $plan = 'inv CANCELLED (dibiarkan) → SELESAI tanpa tagihan';
                $cCancelledInv++;
            } elseif (! $inv) {
                $plan = 'TANPA invoice → SELESAI tanpa tagihan';
                $cNoInv++;
            } else {
                $plan = sprintf('inv %s → SELESAI', $invSt);
            }
            if ($openQ > 0) {
                $plan .= sprintf(' [+tutup %d antrean]', $openQ);
            }

            $rows[] = [
                $v->visit_date?->toDateString(),
                substr($v->no_registrasi ?? substr($v->id, 0, 8), 0, 18),
                substr($v->patient->name ?? '?', 0, 22),
                $invSt,
                $plan,
            ];

            if ($apply) {
                DB::transaction(function () use ($v, $inv, $invSt, $station) {
                    if ($inv && in_array($invSt, self::MARK_PAID_FROM, true)) {
                        $inv->update([
                            'status'         => 'PAID',
                            'payment_method' => 'BPJS',
                            'covered_amount' => $inv->total,
                            'paid_amount'    => 0,
                            'paid_at'        => now(),
                            'notes'          => trim(($inv->notes ? $inv->notes."\n" : '')
                                .'[Auto-tutup backlog KASIR-BPJS '.now()->toDateString().']'),
                        ]);
                    }
                    Queue::where('visit_id', $v->id)->where('station', $station)
                        ->whereIn('status', self::OPEN)
                        ->update(['status' => 'COMPLETED']);

                    $v->update(['current_station' => 'SELESAI']);
                });
            }
        }

        $this->table(['Tgl', 'No.Reg', 'Pasien', 'Invoice', 'Rencana aksi'], array_slice($rows, 0, 30));
        if (count($rows) > 30) {
            $this->line(sprintf('  … +%d baris lagi (tampil 30 pertama)', count($rows) - 30));
        }

        $this->newLine();
        $this->line('  ── RINGKASAN ─────────────────────────────────');
        $this->line(sprintf('  Invoice DRAFT/FINAL → PAID(BPJS) : %d  (Rp %s)', $cPaid, number_format($sumPaid, 0, ',', '.')));
        $this->line(sprintf('  Tutup TANPA invoice              : %d', $cNoInv));
        $this->line(sprintf('  Invoice CANCELLED → tutup        : %d', $cCancelledInv));
        $this->line(sprintf('  Antrean kasir yang ditutup       : %d', $cQueue));
        $this->newLine();
        $this->line(sprintf('  Pasien unik tersentuh            : %d', $targetPatientIds->count()));
        $this->line(sprintf('  → FULLY unblocked (bisa daftar)  : %d', $targetPatientIds->count() - $stillBlocked->count()));
        $this->line(sprintf('  → masih terblokir (visit lain)   : %d', $stillBlocked->count()));
        $this->newLine();

        if ($apply) {
            $this->info(sprintf('✅ APPLIED: %d visit di-SELESAI-kan.', $visits->count()));
        } else {
            $this->warn('DRY-RUN — TIDAK ada data yang diubah. Tambahkan --apply untuk menulis.');
        }

        return self::SUCCESS;
    }
}
