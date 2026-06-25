<?php

namespace App\Console\Commands;

use App\Services\Bpjs\BpjsRekamMedisService;
use Illuminate\Console\Command;

/**
 * Batch kirim Rekam Medis ke BPJS (WS Rekam Medis) — mengisi i-Care nasional.
 * Menggantikan tombol manual "Kirim RM ke BPJS" di DokterView.
 *
 *   rm:batch-sync                       → kunjungan SELESAI hari ini (dijadwal 23:59 WIB)
 *   rm:batch-sync --backlog             → semua kunjungan ber-SEP yang belum/gagal terkirim
 *   rm:batch-sync --backlog --limit=200 → drain tunggakan, dibatasi 200 kunjungan
 *
 * Aman bila integrasi belum aktif: keluar tanpa menggagalkan scheduler.
 */
class RmBatchSync extends Command
{
    protected $signature = 'rm:batch-sync
                            {--backlog : Kirim semua kunjungan ber-SEP yang belum/gagal terkirim, bukan hanya hari ini}
                            {--limit=500 : Batas jumlah kunjungan saat --backlog}';

    protected $description = 'Kirim rekam medis kunjungan BPJS ke WS Rekam Medis (mengisi i-Care). Default hari ini; --backlog untuk drain tunggakan.';

    public function handle(BpjsRekamMedisService $service): int
    {
        if (! $service->isEnabled()) {
            $this->warn('Integrasi WS Rekam Medis BPJS belum aktif — batch dilewati.');
            return self::SUCCESS;
        }

        $mode  = $this->option('backlog') ? 'BACKLOG' : 'AUTO';
        $limit = $this->option('backlog') ? (int) $this->option('limit') : null;

        try {
            $r = $service->batchSend($mode, $limit);
            $this->info("Batch RM ({$mode}) selesai: terkirim={$r['sent']} gagal={$r['failed']} total={$r['total']}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Batch RM gagal: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
