<?php

namespace App\Console\Commands;

use App\Models\Visit;
use App\Services\FormRegistry\FormRegistryService;
use Illuminate\Console\Command;

/**
 * Backfill dokumen DRAFT (default Resume Medis) untuk visit yang RME-nya SUDAH
 * difinalisasi tapi BELUM punya dokumen Form Registry → supaya muncul di antrean
 * TTD dokter. Akar masalah: finalizeKunjungan tidak membuat patient_document;
 * dokumen hanya lahir bila dokter menuntaskan modal "Setuju & Terbitkan" yang
 * non-blocking. Lihat FormRegistryService::ensureDraftForVisit().
 *
 * Default DRY-RUN (hanya daftar kandidat). Pakai --apply untuk benar-benar membuat.
 * WAJIB ada filter tanggal (--date/--from/--to) agar tidak men-backfill SELURUH
 * riwayat (bisa ribuan, membanjiri antrean) — kecuali sengaja pakai --all.
 */
class BackfillResumeDraft extends Command
{
    protected $signature = 'resume:backfill-draft
                            {--apply : Buat dokumen (default: dry-run preview saja)}
                            {--code=RESUME_MEDIS : Kode template}
                            {--date= : Filter 1 tanggal kunjungan (Y-m-d)}
                            {--from= : Filter visit_date >= (Y-m-d)}
                            {--to= : Filter visit_date <= (Y-m-d)}
                            {--all : Izinkan tanpa filter tanggal (backfill SELURUH riwayat)}
                            {--limit=0 : Batasi jumlah visit (0 = semua kandidat)}';

    protected $description = 'Backfill dokumen DRAFT Resume Medis utk visit finalisasi yang belum punya dokumen → masuk antrean TTD.';

    public function handle(FormRegistryService $svc): int
    {
        $apply = (bool) $this->option('apply');
        $code  = (string) $this->option('code');
        $limit = (int) $this->option('limit');
        $date  = $this->option('date');
        $from  = $this->option('from');
        $to    = $this->option('to');

        if (!$date && !$from && !$to && !$this->option('all')) {
            $this->error('Tanpa filter tanggal, backfill mencakup SELURUH riwayat (bisa ribuan & membanjiri antrean).');
            $this->line('Beri --date=YYYY-MM-DD, atau --from/--to, atau --all bila sengaja semua.');
            return self::FAILURE;
        }

        // Kandidat: visit dgn doctorExamination FINALISASI, TAPI belum punya dokumen
        // non-arsip (status != SUPERSEDED/VOID/REJECTED) utk template ini.
        $q = Visit::query()
            ->whereHas('doctorExamination', fn ($e) => $e->where('is_finalized', true))
            ->whereDoesntHave('patientDocuments', fn ($d) => $d
                ->where('template_code', $code)
                ->whereNotIn('status', ['SUPERSEDED', 'VOID', 'REJECTED']))
            ->with('patient');

        if ($date) { $q->whereDate('visit_date', $date); }
        if ($from) { $q->whereDate('visit_date', '>=', $from); }
        if ($to)   { $q->whereDate('visit_date', '<=', $to); }

        $q->orderBy('visit_date');
        if ($limit > 0) { $q->limit($limit); }

        $visits = $q->get();
        $mode = $apply ? '[APPLY]' : '[DRY RUN]';
        $this->info("{$mode} Kandidat backfill {$code}: {$visits->count()} visit");

        $created = 0; $skipped = 0; $failed = 0;
        foreach ($visits as $v) {
            $label = ($v->patient?->no_rm ?? '-') . ' | ' . ($v->patient?->name ?? '-')
                   . ' | ' . ($v->visit_date ?? '-');

            if (!$apply) {
                $this->line("  • {$label}");
                continue;
            }

            try {
                $doc = $svc->ensureDraftForVisit($v->id, $code);
                if ($doc) {
                    $created++;
                    $this->line("  ✓ {$label} → DRAFT {$doc->id}");
                } else {
                    $skipped++;
                    $this->line("  - {$label} (sudah ada / dilewati)");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  ✗ {$label} → " . $e->getMessage());
            }
        }

        if ($apply) {
            $this->info("Selesai: dibuat={$created} dilewati={$skipped} gagal={$failed}");
        } else {
            $this->info('Dry-run — jalankan ulang dengan --apply untuk membuat dokumen.');
        }

        return self::SUCCESS;
    }
}
