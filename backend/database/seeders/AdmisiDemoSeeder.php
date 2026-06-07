<?php

namespace Database\Seeders;

use App\Models\DoctorSchedule;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AdmisiDemoSeeder — data demo untuk stasiun ADMISI (AdmisiView.vue).
 *
 * Sejajar dengan TriaseDemoSeeder / RefraksionisDemoSeeder. Mengisi SELURUH
 * tampilan AdmisiView dengan data HARI INI sehingga setiap panel & filter ada
 * isinya:
 *
 *   1. Panel "Antrian Admisi" (callableQueue di FE) — pasien WALK-IN dari
 *      anjungan/kiosk yang BELUM didaftarkan (placeholder "Belum Terdaftar",
 *      station ADMISI, prefix A-NNN). Menguji tombol Panggil + Daftarkan:
 *        (a) WAITING  → siap dipanggil.
 *        (b) CALLED   → sudah dipanggil, siap didaftarkan petugas.
 *      Sumber: AdmisiService::getAntrian() (station=ADMISI, today, !=CANCELLED).
 *
 *   2. Tabel "Seluruh Kunjungan Hari Ini" — kunjungan TERDAFTAR lintas penjamin
 *      & stasiun, untuk menguji filter station/penjamin + stat cards dashboard:
 *        (c) BPJS      di TRIASE  + no_sep terisi (hitungan SEP).
 *        (d) UMUM      di TRIASE.
 *        (e) ASURANSI  di DOKTER  + verifikasi TPA PENDING.
 *        (f) BPJS      di SELESAI (kunjungan selesai).
 *        (g) UMUM      CANCELLED  (kunjungan dibatalkan).
 *      Sumber: AdmisiService::getKunjungan() (visit_date=today).
 *
 * Catatan teknis:
 *   - Visit terdaftar mengikuti alur registerVisit(): skip ADMISI, langsung
 *     enqueue TRIASE + REFRAKSIONIS paralel (prefix shared "TR"). Untuk skenario
 *     yang sudah maju ke DOKTER/SELESAI, current_station + queue disesuaikan.
 *   - Walk-in kiosk meniru ambilTiketUmumKiosk(): patient placeholder
 *     "Belum Terdaftar", visit current_station=ADMISI, queue prefix "A".
 *
 * IDEMPOTEN: pasien via NIK (placeholder walk-in pakai NIK '9000…' tetap agar
 *   tak menumpuk tiap run); visit via (patient, visit_date); queue via
 *   (visit, station) dengan sinkron status pada run berulang.
 *
 * Jalankan: php artisan db:seed --class=AdmisiDemoSeeder
 */
class AdmisiDemoSeeder extends Seeder
{
    public function run(): void
    {
        $umum     = Insurer::where('type', 'UMUM')->value('id');
        $bpjs     = Insurer::where('type', 'BPJS')->value('id');
        $asuransi = Insurer::where('type', 'ASURANSI')->where('is_active', true)->value('id');

        // Jadwal dokter aktif (opsional) — agar kolom "Dokter" terisi pada
        // skenario yang sudah di DOKTER. Boleh null kalau belum ada jadwal.
        $doctorScheduleId = DoctorSchedule::query()->value('id');

        // ── 1. WALK-IN KIOSK (placeholder, antrean ADMISI) ──────────────────
        $walkIns = [
            // (a) Baru ambil tiket, menunggu dipanggil.
            ['nik' => '9000000000000001', 'status' => 'WAITING'],
            // (b) Sudah dipanggil, siap didaftarkan petugas.
            ['nik' => '9000000000000002', 'status' => 'CALLED'],
        ];

        // ── 2. KUNJUNGAN TERDAFTAR (tabel "Seluruh Kunjungan Hari Ini") ─────
        $registered = [
            // (c) BPJS, di TRIASE, SEP sudah terbit.
            [
                'nik' => '3275088802000001', 'rm' => 'RM-AD-001', 'name' => 'Hendra Wijaya',
                'gender' => 'L', 'dob' => '1988-03-22', 'guarantor' => 'BPJS', 'insurer' => $bpjs,
                'bpjs' => '8800000000001', 'class' => 'Baru', 'phone' => '081234500001',
                'station' => 'TRIASE', 'no_sep' => 'SEP-DEMO-AD-0001',
            ],
            // (d) UMUM, di TRIASE.
            [
                'nik' => '3275088802000002', 'rm' => 'RM-AD-002', 'name' => 'Maya Sari',
                'gender' => 'P', 'dob' => '1996-11-08', 'guarantor' => 'UMUM', 'insurer' => $umum,
                'bpjs' => null, 'class' => 'Baru', 'phone' => '081234500002',
                'station' => 'TRIASE', 'no_sep' => null,
            ],
            // (e) ASURANSI, sudah di DOKTER, verifikasi TPA pending.
            [
                'nik' => '3275088802000003', 'rm' => 'RM-AD-003', 'name' => 'Rangga Putra',
                'gender' => 'L', 'dob' => '1979-07-15', 'guarantor' => 'ASURANSI', 'insurer' => $asuransi,
                'bpjs' => null, 'class' => 'Kontrol', 'phone' => '081234500003',
                'station' => 'DOKTER', 'no_sep' => null, 'tpa_pending' => true,
            ],
            // (f) BPJS, kunjungan SELESAI.
            [
                'nik' => '3275088802000004', 'rm' => 'RM-AD-004', 'name' => 'Lestari Ningsih',
                'gender' => 'P', 'dob' => '1965-01-30', 'guarantor' => 'BPJS', 'insurer' => $bpjs,
                'bpjs' => '8800000000004', 'class' => 'Kontrol', 'phone' => '081234500004',
                'station' => 'SELESAI', 'no_sep' => 'SEP-DEMO-AD-0004',
            ],
            // (g) UMUM, kunjungan DIBATALKAN.
            [
                'nik' => '3275088802000005', 'rm' => 'RM-AD-005', 'name' => 'Bayu Saputra',
                'gender' => 'L', 'dob' => '2001-05-19', 'guarantor' => 'UMUM', 'insurer' => $umum,
                'bpjs' => null, 'class' => 'Baru', 'phone' => '081234500005',
                'station' => 'CANCELLED', 'no_sep' => null,
            ],
        ];

        DB::transaction(function () use ($walkIns, $registered, $doctorScheduleId) {
            foreach ($walkIns as $w) {
                $this->seedWalkIn($w['nik'], $w['status']);
            }
            foreach ($registered as $r) {
                $this->seedRegistered($r, $doctorScheduleId);
            }
        });

        $this->command?->info(
            'AdmisiDemoSeeder: ' . count($walkIns) . ' walk-in antrean ADMISI + '
            . count($registered) . ' kunjungan terdaftar hari ini.'
        );
    }

    /**
     * Walk-in kiosk — patient placeholder "Belum Terdaftar" + visit di ADMISI
     * + queue prefix "A". Meniru ambilTiketUmumKiosk(). Idempoten via NIK tetap.
     */
    private function seedWalkIn(string $nik, string $status): void
    {
        $patient = Patient::firstOrCreate(
            ['nik' => $nik],
            [
                'no_rm'         => null,
                'name'          => 'Belum Terdaftar',
                'gender'        => null,
                'date_of_birth' => null,
                'is_active'     => true,
            ],
        );

        $visit = Visit::firstOrNew([
            'patient_id'      => $patient->id,
            'visit_date'      => today()->toDateString(),
            'current_station' => 'ADMISI',
        ]);
        if (! $visit->exists) {
            $visit->fill([
                'insurer_id'            => null,
                'classification'        => 'Baru',
                'guarantor_type'        => 'UMUM',
                'satusehat_sync_status' => 'PENDING',
            ])->save();
        }

        $this->enqueue($visit, 'ADMISI', $status);
    }

    /**
     * Kunjungan terdaftar — patient + visit (station sesuai skenario) + queue.
     * station 'CANCELLED' = visit dibatalkan (current_station SELESAI, queue
     * CANCELLED) agar muncul di filter "Batal".
     */
    private function seedRegistered(array $r, ?string $doctorScheduleId): void
    {
        $patient = Patient::firstOrCreate(
            ['nik' => $r['nik']],
            [
                'no_rm'         => $r['rm'],
                'identity_type' => 'KTP',
                'name'          => $r['name'],
                'gender'        => $r['gender'],
                'date_of_birth' => $r['dob'],
                'bpjs_number'   => $r['bpjs'],
                'phone'         => $r['phone'],
                'address'       => 'Medan',
                'is_active'     => true,
            ],
        );

        $isCancel       = $r['station'] === 'CANCELLED';
        $currentStation = $isCancel ? 'SELESAI' : $r['station'];
        $atDokter       = $r['station'] === 'DOKTER';

        $visit = Visit::firstOrNew([
            'patient_id' => $patient->id,
            'visit_date' => today()->toDateString(),
        ]);
        if (! $visit->exists) {
            $visit->fill([
                'insurer_id'         => $r['insurer'],
                'doctor_schedule_id' => $atDokter ? $doctorScheduleId : null,
                'no_registrasi'      => $this->nextNoRegistrasi(),
                'classification'     => $r['class'],
                'guarantor_type'     => $r['guarantor'],
                'visit_type'         => 'REGULAR',
                'current_station'    => $currentStation,
                'no_sep'             => $r['no_sep'] ?? null,
                'satusehat_sync_status'         => 'PENDING',
                'insurance_verification_status' => ! empty($r['tpa_pending']) ? 'PENDING' : 'NONE',
            ])->save();
        }

        // Verifikasi TPA awal (tab "Verifikasi Pending" billing) — opsional.
        if (! empty($r['tpa_pending']) && $r['insurer']
            && class_exists(\App\Models\InsuranceVerification::class)
            && ! \App\Models\InsuranceVerification::where('visit_id', $visit->id)->exists()
        ) {
            \App\Models\InsuranceVerification::create([
                'visit_id'    => $visit->id,
                'insurer_id'  => $r['insurer'],
                'verified_by' => null,
                'status'      => 'PENDING',
                'member_name' => $r['name'],
            ]);
        }

        // Antrean TRIASE + REFRAKSIONIS paralel (pola registerVisit).
        $trStatus = $isCancel ? 'CANCELLED' : ($atDokter || $r['station'] === 'SELESAI' ? 'COMPLETED' : 'WAITING');
        $this->enqueue($visit, 'TRIASE', $trStatus);
        $this->enqueue($visit, 'REFRAKSIONIS', $trStatus, reuseTrSeq: true);

        // Antrean DOKTER untuk skenario yang sudah maju ke dokter.
        if ($atDokter) {
            $this->enqueue($visit, 'DOKTER', 'IN_PROGRESS');
        }
    }

    /**
     * Enqueue idempoten via (visit, station). Sequence dihitung lintas grup
     * prefix (TRIASE+REFRAKSIONIS berbagi "TR"). reuseTrSeq: pakai sequence dari
     * baris TRIASE visit yang sama agar nomor TR-NNN identik (paralel).
     */
    private function enqueue(Visit $visit, string $station, string $status, bool $reuseTrSeq = false): void
    {
        $existing = Queue::where('visit_id', $visit->id)->where('station', $station)->first();
        if ($existing) {
            if ($existing->status !== $status) {
                $existing->update($this->statusTimestamps($status));
            }
            return;
        }

        $prefix = Queue::PREFIX_MAP[$station] ?? 'A';

        if ($reuseTrSeq) {
            $tr = Queue::where('visit_id', $visit->id)->where('station', 'TRIASE')->first();
            $seq = $tr?->queue_sequence ?? $this->nextSeq($station, $prefix);
        } else {
            $seq = $this->nextSeq($station, $prefix);
        }

        Queue::create(array_merge([
            'visit_id'       => $visit->id,
            'station'        => $station,
            'queue_prefix'   => $prefix,
            'queue_sequence' => $seq,
            'queue_number'   => $prefix . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
        ], $this->statusTimestamps($status)));
    }

    /** Sequence berikutnya hari ini, lintas grup prefix bila ada (mis. TR). */
    private function nextSeq(string $station, string $prefix): int
    {
        $group = null;
        foreach (Queue::SHARED_PREFIX_GROUPS as $p => $stations) {
            if ($p === $prefix) {
                $group = $stations;
                break;
            }
        }
        $stations = $group ?? [$station];

        return (int) (Queue::whereIn('station', $stations)
            ->whereDate('created_at', today())
            ->max('queue_sequence') ?? 0) + 1;
    }

    /** Timestamp called/started/completed sesuai status agar tampilan konsisten. */
    private function statusTimestamps(string $status): array
    {
        return [
            'status'       => $status,
            'called_at'    => in_array($status, ['CALLED', 'IN_PROGRESS', 'COMPLETED'], true) ? now()->subMinutes(30) : null,
            'started_at'   => in_array($status, ['IN_PROGRESS', 'COMPLETED'], true) ? now()->subMinutes(25) : null,
            'completed_at' => $status === 'COMPLETED' ? now()->subMinutes(5) : null,
        ];
    }

    /** Nomor registrasi berikutnya — sama format dengan generateNoRegistrasi(). */
    private function nextNoRegistrasi(): string
    {
        $prefix = 'REG-' . today()->format('Ymd') . '-';
        $last = Visit::withTrashed()
            ->where('no_registrasi', 'like', $prefix . '%')
            ->orderByDesc('no_registrasi')
            ->value('no_registrasi');
        $next = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
