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

// Rekam Medis BPJS (WS Rekam Medis) — kirim RM kunjungan SELESAI hari ini ke BPJS
// (mengisi i-Care nasional), 23:59 WIB. Menggantikan tombol manual di DokterView.
// No-op bila integrasi belum aktif. Tunggakan lama: jalankan manual `rm:batch-sync
// --backlog` (lihat RmBatchSync).
Schedule::command('rm:batch-sync')
    ->dailyAt('23:59')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// BPJS Aplicare — rekonsiliasi ketersediaan tempat tidur tiap 30 menit (jaring
// pengaman; push utama event-driven dari RanapService). No-op bila integrasi off.
Schedule::command('aplicare:sync')
    ->everyThirtyMinutes()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Jam 17.00 WIB — laporan tunggakan kasir (tagihan belum tutup kasir + umurnya)
// ke supervisor (env KASIR_BACKLOG_REPORT_TO). No-op aman bila tak ada tunggakan
// atau penerima belum diset.
Schedule::command('kasir:report-backlog')
    ->dailyAt('17:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Jam 05.00 WIB — tarik data marketing dari Google Sheet (survei kepuasan + peserta
// event). Aman bila Sheet belum dibagikan / URL kosong (di-log & dilewati).
Schedule::command('marketing:sync-google')
    ->dailyAt('05:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
