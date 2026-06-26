<?php

namespace App\Console\Commands;

use App\Models\MarketingEvent;
use App\Services\MarketingMasterService;
use App\Services\MarketingSurveyService;
use Illuminate\Console\Command;

/**
 * Sinkron data marketing dari Google Sheet (anyone-with-link) — dijadwalkan harian.
 *
 *   marketing:sync-google   → tarik survei kepuasan + peserta semua event yang
 *                             punya participant_sheet_url.
 *
 * Aman bila Sheet belum dibagikan/URL kosong: GoogleSheetCsvService mengembalikan
 * ok=false → di-log & dilewati, command tetap SUCCESS (tidak meledak).
 */
class MarketingSyncGoogle extends Command
{
    protected $signature = 'marketing:sync-google';

    protected $description = 'Tarik survei kepuasan & peserta event marketing dari Google Sheet (harian).';

    public function handle(MarketingSurveyService $survey, MarketingMasterService $master): int
    {
        // 1) Survei kepuasan
        $s = $survey->sync();
        if ($s['ok']) {
            $this->info("Survei: {$s['fetched']} baris, {$s['inserted']} baru.");
        } else {
            $this->warn('Survei dilewati: ' . ($s['message'] ?? 'tidak diketahui'));
        }

        // 2) Peserta event (yang punya sheet)
        $events = MarketingEvent::query()
            ->whereNotNull('participant_sheet_url')
            ->where('is_active', true)
            ->get();

        $totalNew = 0;
        foreach ($events as $event) {
            $r = $master->syncParticipants($event);
            if ($r['ok']) {
                $totalNew += $r['inserted'];
                $this->info("Event [{$event->name}]: {$r['fetched']} baris, {$r['inserted']} baru.");
            } else {
                $this->warn("Event [{$event->name}] dilewati: " . ($r['message'] ?? 'tidak diketahui'));
            }
        }

        $this->info("Selesai. Total peserta baru: {$totalNew} dari {$events->count()} event.");

        return self::SUCCESS;
    }
}
