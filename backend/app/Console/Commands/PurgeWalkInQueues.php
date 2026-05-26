<?php

namespace App\Console\Commands;

use App\Models\Patient;
use App\Models\Queue;
use App\Models\Visit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeWalkInQueues extends Command
{
    protected $signature = 'antrian:purge-walkin
                            {--dry-run : Tampilkan apa yang akan dihapus tanpa eksekusi}';

    protected $description = 'Hapus antrean walk-in (patient=Belum Terdaftar, station=ADMISI) dari hari-hari sebelumnya. Counter ADMISI per hari sudah auto-reset karena generateQueueNumber pakai whereDate(today).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $today = today();

        $visits = Visit::with('patient')
            ->whereHas('patient', fn ($q) => $q->where('name', 'Belum Terdaftar'))
            ->where('current_station', 'ADMISI')
            ->whereDate('visit_date', '<', $today)
            ->get();

        if ($visits->isEmpty()) {
            $this->info('Tidak ada walk-in stale untuk di-purge.');
            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY RUN] ' : '') . "Akan purge {$visits->count()} kunjungan walk-in stale.");

        if ($dry) {
            foreach ($visits as $v) {
                $this->line("  - visit_id={$v->id}  visit_date={$v->visit_date}  patient_id={$v->patient_id}");
            }
            return self::SUCCESS;
        }

        $purged = 0;
        DB::transaction(function () use ($visits, &$purged) {
            foreach ($visits as $visit) {
                Queue::where('visit_id', $visit->id)->delete();
                $patientId = $visit->patient_id;
                $visit->delete();
                if ($patientId) {
                    Patient::where('id', $patientId)
                        ->where('name', 'Belum Terdaftar')
                        ->delete();
                }
                $purged++;
            }
        });

        $this->info("Selesai. {$purged} walk-in dihapus (soft delete).");
        return self::SUCCESS;
    }
}
