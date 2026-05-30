<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Jam 00:00 — purge walk-in stale (patient=Belum Terdaftar, station=ADMISI, visit_date < today)
// Counter ADMISI auto-reset karena generateQueueNumber pakai whereDate(today).
Schedule::command('antrian:purge-walkin')
    ->dailyAt('00:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Satu Sehat — batch sync kunjungan SELESAI hari ini (Encounter/Condition/obat).
// 23:59 batch utama, 01:00 retry PARTIAL/FAILED. No-op bila integrasi belum aktif.
Schedule::command('satusehat:batch-sync')
    ->dailyAt('23:59')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('satusehat:batch-sync --retry')
    ->dailyAt('01:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
