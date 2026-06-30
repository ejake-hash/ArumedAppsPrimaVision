<?php

namespace App\Console\Commands;

use App\Models\Visit;
use App\Services\AdmisiService;
use Illuminate\Console\Command;

/**
 * Rekonsiliasi SEP "stuck ISSUING" — jaring SEKUNDER state-machine penerbitan SEP
 * (rancangan design_sep_out_of_transaction). Jaring PRIMER tetap recovery-on-klik-ulang.
 *
 * Sisir visit dgn sep_status=ISSUING basi & no_sep kosong (proses penerbitan mati di
 * tengah / BPJS code '0'/timeout saat SEP mungkin sudah terbit). Terbitkan ulang via
 * AdmisiService::bpjsGenerateSep → BPJS menolak duplikat → jalur recovery menautkan
 * no_sep (ISSUED), atau bila memang belum pernah terbit → terbit baru / FAILED.
 *
 * DIJALANKAN MANUAL (server PrimaVision belum punya scheduler/worker). Contoh harian:
 *   php artisan bpjs:sep-reconcile            # dry-run: daftar saja
 *   php artisan bpjs:sep-reconcile --apply    # eksekusi penerbitan ulang
 */
class BpjsSepReconcile extends Command
{
    protected $signature = 'bpjs:sep-reconcile
        {--apply : Eksekusi penerbitan ulang (default dry-run: hanya menampilkan daftar)}
        {--ttl=300 : Ambang detik sep_issuing_at dianggap basi (default 300, > worst-case penerbitan)}';

    protected $description = 'Sisir SEP stuck (sep_status=ISSUING basi, no_sep kosong) → terbitkan ulang (self-heal) atau tandai status';

    public function handle(AdmisiService $admisi): int
    {
        $ttl = max(150, (int) $this->option('ttl'));

        $stuck = Visit::query()
            ->where('sep_status', 'ISSUING')
            ->whereNull('no_sep')
            ->where('sep_issuing_at', '<', now()->subSeconds($ttl))
            ->get(['id', 'sep_issuing_at']);

        if ($stuck->isEmpty()) {
            $this->info("Tidak ada SEP ISSUING basi (> {$ttl}s). Bersih.");
            return self::SUCCESS;
        }

        $this->warn("{$stuck->count()} visit ISSUING basi (> {$ttl}s):");
        foreach ($stuck as $v) {
            $this->line("  - {$v->id} (sejak {$v->sep_issuing_at})");
        }

        if (! $this->option('apply')) {
            $this->comment('Dry-run. Tambahkan --apply untuk menerbitkan ulang.');
            return self::SUCCESS;
        }

        $linked = 0;
        $failed = 0;
        $error  = 0;
        foreach ($stuck as $v) {
            try {
                $res   = $admisi->bpjsGenerateSep(['visit_id' => $v->id]);
                $noSep = $res['response']['sep']['noSep'] ?? null;
                if ($noSep) {
                    $linked++;
                    $this->info("  ✓ {$v->id} → SEP {$noSep}");
                } else {
                    $failed++;
                    $this->error("  ✗ {$v->id} → " . ($res['metaData']['message'] ?? 'gagal tanpa nomor'));
                }
            } catch (\Throwable $e) {
                $error++;
                $this->error("  ! {$v->id} → {$e->getMessage()}");
            }
        }

        $this->info("Selesai. Tertaut: {$linked}, gagal: {$failed}, error: {$error}.");
        return self::SUCCESS;
    }
}
