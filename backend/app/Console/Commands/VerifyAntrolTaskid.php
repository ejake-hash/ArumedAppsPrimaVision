<?php

namespace App\Console\Commands;

use App\Models\Patient;
use App\Models\Visit;
use App\Services\BpjsAntreanService;
use App\Services\QueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * VERIFIKASI ALUR TASKID BPJS Antrean (Sisi A) — TANPA menembak BPJS / butuh HFIS.
 *
 * Meng-spy BpjsAntreanService (merekam payload /antrean/updatewaktu; membalas sukses
 * seolah BPJS menerima, seperti kondisi pasca-HFIS), lalu menjalankan siklus
 * QueueService::reportTask untuk pasien sintetis DI DALAM TRANSAKSI yang DIROLLBACK
 * (nol polusi data). Memverifikasi:
 *   1. Urutan taskid — pasien BARU 1→2→3→4→5→6→7 · pasien LAMA 3→4→5
 *   2. Waktu (epoch ms) MONOTON NAIK (t1<t2<…) — aturan keras BPJS
 *   3. kodebooking konsisten di semua panggilan
 *   4. Guard monoton: taskid sama/mundur di-SKIP; 99 (batal) selalu boleh
 *
 * Bukti sisi-RS untuk Form UAT "Simulasi SIM FKRTL — Pemanggilan Antrean", berlaku
 * SEBELUM HFIS terdaftar. Tool verifikasi/dev — aman dihapus setelah UAT.
 *
 * Jalankan:  php artisan antrol:verify-taskid
 */
class VerifyAntrolTaskid extends Command
{
    protected $signature = 'antrol:verify-taskid';

    protected $description = 'Verifikasi urutan/monotonik/guard taskid BPJS Antrean (Sisi A) tanpa menembak BPJS';

    private const MAKNA = [
        1  => 'mulai tunggu admisi',
        2  => 'mulai layan admisi',
        3  => 'selesai admisi / mulai tunggu poli',
        4  => 'dipanggil poli (mulai layan poli)',
        5  => 'selesai poli / mulai tunggu farmasi',
        6  => 'mulai buat obat',
        7  => 'obat selesai dibuat',
        99 => 'batal / tidak hadir',
    ];

    public function handle(): int
    {
        // ---- SPY: rekam updatewaktu/add TANPA BPJS; balas sukses (is_success=true) agar
        //      guard monoton (bpjs_last_taskid) maju persis seperti saat BPJS menerima. ----
        $spy = new class extends BpjsAntreanService
        {
            /** @var array<int,array<string,mixed>> */
            public array $waktu = [];

            /** @var array<int,array<string,mixed>> */
            public array $add = [];

            public function __construct() {}                 // JANGAN panggil parent (hindari BpjsClient)

            public function isEnabled(): bool { return true; }

            public function boot(): void {}

            private function ok(): array
            {
                return ['is_success' => true, 'metaData' => ['code' => '1', 'message' => 'Ok'], 'http_status' => 200, 'response' => null];
            }

            public function updateWaktuAntrean(array $data, ?string $visitId = null): array
            {
                $this->waktu[] = $data;

                return $this->ok();
            }

            public function addAntrean(array $data, ?string $visitId = null): array
            {
                $this->add[] = $data;

                return $this->ok();
            }

            public function addAntreanFarmasi(array $data, ?string $visitId = null): array { return $this->ok(); }
        };
        app()->instance(BpjsAntreanService::class, $spy);

        /** @var QueueService $qs */
        $qs = app(QueueService::class);

        $problems = [];

        DB::beginTransaction();
        try {
            // ---- Pasien sintetis (throwaway; ikut di-rollback) ----
            $patient = new Patient();
            $patient->forceFill([
                'id'            => (string) Str::uuid(),
                'no_rm'         => 'VERIFY-' . strtoupper(Str::random(6)),
                'nik'           => '32' . str_pad((string) random_int(0, 99999999999999), 14, '0', STR_PAD_LEFT),
                'name'          => 'VERIFY TASKID',
                'date_of_birth' => '1990-01-01',
            ])->saveQuietly();

            $mkVisit = function (string $klasifikasi) use ($patient): Visit {
                $v = new Visit();
                $v->forceFill([
                    'id'                => (string) Str::uuid(),
                    'patient_id'        => $patient->id,
                    'visit_date'        => now('Asia/Jakarta')->toDateString(),
                    'classification'    => $klasifikasi,
                    'visit_type'        => 'REGULAR',
                    'current_station'   => 'TRIASE',
                    'guarantor_type'    => 'BPJS',
                    'bpjs_booking_code' => 'VF' . strtoupper(Str::random(8)),
                ])->saveQuietly();

                return $v;
            };

            $run = function (Visit $v, array $taskids) use ($qs): void {
                foreach ($taskids as $i => $t) {
                    if ($i > 0) {
                        // Di produksi jarak t1<t2<… dijamin latensi HTTP updatewaktu; di sini
                        // 2ms cukup untuk memastikan cap microtime bergerak (monoton).
                        usleep(2000);
                    }
                    $qs->reportTask($v, $t);
                }
            };

            // ============ FASE 1 — PASIEN BARU: 1→2→3→4→5→6→7 ============
            $spy->waktu = [];
            $vBaru = $mkVisit('Baru');
            $run($vBaru, [1, 2, 3, 4, 5, 6, 7]);
            $baru = $spy->waktu;
            $this->renderPhase('PASIEN BARU (1→2→3→4→5→6→7)', $baru);
            $this->verify('BARU', $baru, [1, 2, 3, 4, 5, 6, 7], $vBaru->bpjs_booking_code, $problems);

            // ============ FASE 2 — GUARD MONOTON ============
            // taskid mundur (3<=7) harus DI-SKIP; taskid 99 (batal) harus TETAP terkirim.
            $spy->waktu = [];
            $qs->reportTask($vBaru, 3);          // harus di-skip (3 <= last 7)
            $skipCount = count($spy->waktu);
            $qs->reportTask($vBaru, 99);         // harus lolos (99 selalu boleh)
            $after99 = $spy->waktu;
            $this->line('');
            $this->info('FASE 2 — GUARD MONOTON');
            $this->line("  reportTask(3) setelah last=7  → terkirim: {$skipCount} (harap 0 = di-skip)");
            $has99 = ! empty($after99) && (int) ($after99[count($after99) - 1]['taskid'] ?? 0) === 99;
            $this->line('  reportTask(99) batal          → terkirim: ' . ($has99 ? 'YA (taskid 99)' : 'TIDAK') . ' (harap YA)');
            if ($skipCount !== 0) {
                $problems[] = '[GUARD] taskid mundur (3) tidak di-skip padahal last=7';
            }
            if (! $has99) {
                $problems[] = '[GUARD] taskid 99 (batal) tidak terkirim';
            }

            // ============ FASE 3 — PASIEN LAMA: 3→4→5 ============
            $spy->waktu = [];
            $vLama = $mkVisit('Lama');
            $run($vLama, [3, 4, 5]);
            $lama = $spy->waktu;
            $this->renderPhase('PASIEN LAMA (3→4→5)', $lama);
            $this->verify('LAMA', $lama, [3, 4, 5], $vLama->bpjs_booking_code, $problems);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('GAGAL menjalankan verifikasi: ' . $e->getMessage());
            $this->line($e->getFile() . ':' . $e->getLine());

            return self::FAILURE;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(); // JANGAN commit — semua data sintetis dibuang
            }
        }

        // ---- RINGKASAN ----
        $this->line('');
        if (empty($problems)) {
            $this->info('✅ SEMUA LULUS — urutan taskid, monoton waktu, kodebooking, & guard benar. (Data uji di-rollback.)');

            return self::SUCCESS;
        }

        $this->error('❌ ADA MASALAH (' . count($problems) . '):');
        foreach ($problems as $p) {
            $this->line('  • ' . $p);
        }

        return self::FAILURE;
    }

    /** Cetak tabel satu fase. */
    private function renderPhase(string $judul, array $rows): void
    {
        $this->line('');
        $this->info("FASE — {$judul}");
        $table = [];
        $prev = null;
        foreach ($rows as $r) {
            $t     = (int) ($r['taskid'] ?? 0);
            $waktu = (int) ($r['waktu'] ?? 0);
            $delta = $prev === null ? '—' : ($waktu - $prev);
            $table[] = [
                $t,
                self::MAKNA[$t] ?? '?',
                $waktu,
                $delta,
                $r['kodebooking'] ?? '(kosong)',
            ];
            $prev = $waktu;
        }
        $this->table(['taskid', 'makna', 'waktu (epoch ms)', 'Δ ms', 'kodebooking'], $table);
    }

    /** Verifikasi urutan taskid, monoton waktu, konsistensi kodebooking. */
    private function verify(string $fase, array $captured, array $expected, ?string $book, array &$problems): void
    {
        $got = array_map(static fn ($r) => (int) ($r['taskid'] ?? 0), $captured);
        if ($got !== $expected) {
            $problems[] = "[{$fase}] urutan taskid — harap " . json_encode($expected) . ' dapat ' . json_encode($got);
        }

        for ($k = 1; $k < count($captured); $k++) {
            $now = (int) $captured[$k]['waktu'];
            $bef = (int) $captured[$k - 1]['waktu'];
            if ($now <= $bef) {
                $problems[] = "[{$fase}] waktu TIDAK naik di taskid {$captured[$k]['taskid']} ({$now} <= {$bef})";
            }
        }

        foreach ($captured as $r) {
            if (($r['kodebooking'] ?? null) !== $book) {
                $problems[] = "[{$fase}] kodebooking salah — harap {$book} dapat " . ($r['kodebooking'] ?? 'null');
                break;
            }
        }
    }
}
