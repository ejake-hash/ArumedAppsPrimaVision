<?php

namespace App\Console\Commands;

use App\Models\Visit;
use App\Services\QueueService;
use Illuminate\Console\Command;

/**
 * Rapikan pasien yang NYANGKUT di stasiun BEDAH tanpa jadwal valid: punya antrean
 * BEDAH aktif (WAITING/CALLED) tapi tak ada SurgerySchedule non-CANCELLED (lewat
 * visit maupun doctor_examination). Penyebab historis: planning bedah dibatalkan /
 * jadwal dihapus-cancel tanpa membersihkan antrean → pasien hilang dari papan bedah
 * (guard getPatientQueue) DAN tak pernah sampai Kasir.
 *
 * Tiap pasien diteruskan via alur normal QueueService (RAJAL: BEDAH→KASIR,
 * RANAP: kembali ke baris RANAP). Operasi yang SUDAH mulai (IN_PROGRESS) tak tersentuh.
 *
 * Default DRY-RUN (hanya daftar). Jalankan dengan --apply untuk benar-benar melepas.
 */
class SweepUnscheduledBedah extends Command
{
    protected $signature = 'bedah:sweep-unscheduled {--apply : Lepas pasien (default dry-run)}';

    protected $description = 'Lepas pasien yang nyangkut di stasiun BEDAH tanpa jadwal → teruskan ke alur normal (KASIR/RANAP)';

    public function handle(QueueService $queue): int
    {
        $apply = (bool) $this->option('apply');

        $candidates = Visit::query()
            ->whereHas('queues', fn ($q) => $q
                ->where('station', 'BEDAH')
                ->whereIn('status', ['WAITING', 'CALLED']))
            // Tak ada jadwal valid (non-CANCELLED) dari KEDUA tautan.
            ->whereDoesntHave('surgerySchedule', fn ($s) => $s->where('status', '!=', 'CANCELLED'))
            ->whereDoesntHave('doctorExamination.surgerySchedule', fn ($s) => $s->where('status', '!=', 'CANCELLED'))
            ->with('patient')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Tidak ada pasien nyangkut di BEDAH tanpa jadwal. Bersih.');
            return self::SUCCESS;
        }

        $this->warn(($apply ? '[APPLY] ' : '[DRY-RUN] ') . "Pasien nyangkut di BEDAH tanpa jadwal: {$candidates->count()}");
        $released = 0;

        foreach ($candidates as $v) {
            $label = ($v->patient?->name ?? '—') . ' (RM ' . ($v->patient?->no_rm ?? '—') . ', visit ' . substr($v->id, 0, 8) . ')';

            if (! $apply) {
                $this->line("  • {$label} → akan diteruskan ke alur normal");
                continue;
            }

            $ok = $queue->releaseUnscheduledBedah($v);
            if ($ok) {
                $released++;
                $this->line("  ✓ {$label} → dilepas dari BEDAH");
            } else {
                $this->line("  - {$label} → dilewati (tak ada antrean bedah aktif / ternyata masih punya jadwal)");
            }
        }

        if ($apply) {
            $this->info("Selesai. Dilepas: {$released}/{$candidates->count()}.");
        } else {
            $this->info('Dry-run. Jalankan ulang dengan --apply untuk menerapkan.');
        }

        return self::SUCCESS;
    }
}
