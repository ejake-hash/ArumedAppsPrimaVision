<?php

namespace App\Console\Commands;

use App\Models\BpjsVClaimLog;
use Illuminate\Console\Command;

/**
 * Inspeksi log VClaim untuk SEP RAWAT INAP (Fase 0 — verifikasi dasar rujukan).
 *
 * TUJUAN: membuktikan dari log NYATA (bukan tebakan) apa dasar rujukan SEP ranap
 * yang DITERIMA BPJS — apakah cukup reuse rujukan FKTP (asalRujukan '1') seperti
 * rawat jalan, ATAU BPJS menuntut Nomor SPRI / Surat Kontrol (asalRujukan '2',
 * noRujukan = no dokumen FKRTL). Pertanyaan ini menentukan apakah generateSepLocked
 * perlu cabang baru "noRujukan = no_spri" untuk RANAP.
 *
 * Selaras pelajaran proyek: untuk field SEP, BACA BpjsVClaimLog dulu, jangan menebak.
 *
 * Read-only (tidak memutasi). Contoh:
 *   php artisan bpjs:inspect-ranap-sep
 *   php artisan bpjs:inspect-ranap-sep --only-failed --limit=20
 *   php artisan bpjs:inspect-ranap-sep --visit=<uuid>
 *
 * Cara baca SUKSES: pakai kolom boolean `is_success` (= metaData.code '200'),
 * BUKAN response_payload->metaData (untuk response array, metaData tak tersimpan).
 */
class InspectRanapSep extends Command
{
    protected $signature = 'bpjs:inspect-ranap-sep
                            {--limit=50 : Jumlah maksimum entri GENERATE_SEP yang ditampilkan}
                            {--only-failed : Hanya tampilkan SEP ranap yang GAGAL (is_success=false)}
                            {--visit= : Batasi pada satu visit_id tertentu}';

    protected $description = 'Inspeksi log VClaim SEP rawat inap (jnsPelayanan=1): dasar rujukan + cross-ref SPRI, untuk verifikasi Fase 0.';

    public function handle(): int
    {
        $limit  = max(1, (int) $this->option('limit'));
        $onlyFailed = (bool) $this->option('only-failed');
        $visitId = $this->option('visit');

        $query = BpjsVClaimLog::query()
            ->where('action', 'GENERATE_SEP')
            ->where('request_payload->jnsPelayanan', '1'); // '1' = Rawat Inap

        if ($visitId) {
            $query->where('visit_id', $visitId);
        }
        if ($onlyFailed) {
            $query->where('is_success', false);
        }

        $logs = $query->latest()->limit($limit)->get();

        if ($logs->isEmpty()) {
            $this->warn('Tidak ada entri GENERATE_SEP dengan jnsPelayanan=1 (rawat inap) sesuai filter.');
            $this->line('Catatan: bila DB ini belum pernah menerbitkan SEP rawat inap, jalankan di server produksi (arumed_primavision).');
            return self::SUCCESS;
        }

        // Hitungan agregat untuk kesimpulan Fase 0.
        $totalSukses   = 0;
        $totalGagal    = 0;
        $asalRujukanDist = [];   // nilai asalRujukan -> jumlah (hanya yang SUKSES)
        $suksesDgnSpri = 0;      // SEP sukses yang visit-nya punya INSERT_SPRI sukses

        foreach ($logs as $log) {
            $req  = (array) $log->request_payload;
            $resp = (array) $log->response_payload;

            $sukses     = (bool) $log->is_success;
            $asalRujukan = (string) data_get($req, 'rujukan.asalRujukan', '');
            $noRujukan   = (string) data_get($req, 'rujukan.noRujukan', '');
            $ppkRujukan  = (string) data_get($req, 'rujukan.ppkRujukan', '');
            $tglRujukan  = (string) data_get($req, 'rujukan.tglRujukan', '');
            $skdpNo      = (string) data_get($req, 'skdp.noSurat', '');
            $skdpDpjp    = (string) data_get($req, 'skdp.kodeDPJP', '');
            $dpjpLayan   = (string) data_get($req, 'dpjpLayan', '');
            $tujuanKunj  = (string) data_get($req, 'tujuanKunj', '');
            $diagAwal    = (string) data_get($req, 'diagAwal', '');
            $poliTujuan  = (string) data_get($req, 'poli.tujuan', '');
            $noSep       = (string) data_get($resp, 'sep.noSep', data_get($resp, 'response.sep.noSep', ''));

            // Cross-ref SPRI: INSERT_SPRI dengan visit_id sama.
            $spris = $log->visit_id
                ? BpjsVClaimLog::where('action', 'INSERT_SPRI')->where('visit_id', $log->visit_id)->latest()->get()
                : collect();
            $spriSukses = $spris->firstWhere('is_success', true);

            if ($sukses) {
                $totalSukses++;
                $asalRujukanDist[$asalRujukan] = ($asalRujukanDist[$asalRujukan] ?? 0) + 1;
                if ($spriSukses) {
                    $suksesDgnSpri++;
                }
            } else {
                $totalGagal++;
            }

            // ── Cetak blok per entri ───────────────────────────────────────
            $tanda = $sukses ? '<info>✓ SUKSES</info>' : '<error>✗ GAGAL</error>';
            $this->line(str_repeat('─', 72));
            $this->line(sprintf('%s · %s · visit=%s', $tanda, $log->created_at?->format('Y-m-d H:i'), $log->visit_id ?? '—'));
            $this->line(sprintf('  rujukan : asalRujukan=%s  noRujukan=%s  ppkRujukan=%s  tglRujukan=%s',
                $this->q($asalRujukan), $this->q($noRujukan), $this->q($ppkRujukan), $this->q($tglRujukan)));
            $this->line(sprintf('  skdp    : noSurat=%s  kodeDPJP=%s   | dpjpLayan=%s  tujuanKunj=%s',
                $this->q($skdpNo), $this->q($skdpDpjp), $this->q($dpjpLayan), $this->q($tujuanKunj)));
            $this->line(sprintf('  lain    : diagAwal=%s  poli.tujuan=%s', $this->q($diagAwal), $this->q($poliTujuan)));

            if ($sukses) {
                $this->line(sprintf('  hasil   : noSep=%s', $this->q($noSep)));
            } else {
                $this->line(sprintf('  hasil   : http=%s  pesan=%s',
                    $log->http_status ?? '—', $log->error_message ?: '(kosong)'));
            }

            if ($spris->isNotEmpty()) {
                foreach ($spris as $s) {
                    $sResp = (array) $s->response_payload;
                    $noSpri = (string) data_get($sResp, 'response.noSPRI',
                        data_get($sResp, 'noSPRI', data_get($sResp, 'response.noSuratKontrol', '')));
                    $urut = ($log->created_at && $s->created_at)
                        ? ($s->created_at->lt($log->created_at) ? 'SEBELUM SEP' : 'sesudah SEP')
                        : '—';
                    $this->line(sprintf('  SPRI    : %s  noSPRI=%s  (%s, %s)',
                        $s->is_success ? '✓' : '✗', $this->q($noSpri), $urut, $s->created_at?->format('Y-m-d H:i')));
                }
            } else {
                $this->line('  SPRI    : (tidak ada INSERT_SPRI untuk visit ini)');
            }
        }

        // ── Kesimpulan Fase 0 ──────────────────────────────────────────────
        $this->line(str_repeat('═', 72));
        $this->info(sprintf('Total SEP ranap dipindai: %d  (sukses: %d, gagal: %d)', $logs->count(), $totalSukses, $totalGagal));

        if (! empty($asalRujukanDist)) {
            $this->line('Distribusi asalRujukan pada SEP ranap SUKSES:');
            foreach ($asalRujukanDist as $val => $cnt) {
                $label = $val === '1' ? "'1' (FKTP — reuse rujukan FKTP)" : ($val === '2' ? "'2' (FKRTL — dokumen RS / SPRI / surat kontrol)" : "'{$val}'");
                $this->line(sprintf('   %s : %d', $label, $cnt));
            }
        }
        $this->line(sprintf('SEP ranap sukses yang visit-nya punya SPRI sukses: %d / %d', $suksesDgnSpri, $totalSukses));

        $this->newLine();
        $this->line('<comment>Interpretasi:</comment>');
        $this->line("  • Bila SEP ranap SUKSES didominasi asalRujukan '1' + noRujukan = rujukan FKTP →");
        $this->line('    reuse rujukan FKTP DITERIMA BPJS; cabang "noRujukan = no_spri" TIDAK perlu.');
        $this->line("  • Bila SEP ranap GAGAL menuntut nomor SPRI/kontrol, atau yang sukses memakai asalRujukan '2'");
        $this->line('    + noRujukan = nomor SPRI → perlu tambah cabang SPRI di generateSepLocked (asalRujukan');
        $this->line("    '2', noRujukan=no_spri, ppkRujukan=kodeFaskes), analog blok surat kontrol.");

        return self::SUCCESS;
    }

    /** Tampilkan nilai kosong sebagai (kosong) agar mudah dibaca. */
    private function q(string $v): string
    {
        return $v === '' ? '(kosong)' : $v;
    }
}
