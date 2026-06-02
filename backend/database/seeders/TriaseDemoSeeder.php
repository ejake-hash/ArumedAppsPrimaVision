<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Insurer;
use App\Models\NurseAssessment;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * TriaseDemoSeeder — pasien demo untuk stasiun TRIASE (PerawatView).
 *
 * Sejajar dengan RefraksionisDemoSeeder (stasiun paralel). Membuat beberapa pasien
 * yang HARI INI duduk di antrean TRIASE sehingga SELURUH tampilan/filter/skenario
 * PerawatView ada datanya:
 *
 *   - Filter status antrean : WAITING (Menunggu) + CALLED (Dipanggil) +
 *     IN_PROGRESS (Proses) + COMPLETED (Selesai).
 *   - Filter penjamin       : BPJS + Umum/Asuransi.
 *   - State NurseAssessment :
 *       (a) BELUM ADA          → pasien baru, form kosong (uji create/store).
 *       (b) DRAFT              → TTV + keluhan terisi, belum finalize.
 *       (c) DRAFT + ALERGI     → ada alergi (uji badge "⚠ Alergi" di stasiun
 *                                berikutnya: Refraksionis/Dokter).
 *       (d) FINALIZED (dikunci)→ is_finalized=true, queue COMPLETED (read-only +
 *                                CPPT + cetak tiket).
 *
 * Catatan teknis (disesuaikan dengan PerawatService::getPatientQueue):
 *   - getPatientQueue() memfilter Queue station=TRIASE + whereDate(created_at, today)
 *     + whereHas('visit'), eager-load visit.patient/nurseAssessment/insurer.
 *     Maka queue dibuat HARI INI dan visit valid.
 *   - TRIASE berbagi prefix "TR" dengan REFRAKSIONIS (SHARED_PREFIX_GROUPS), jadi
 *     queue_sequence dihitung lintas kedua station agar nomor tak collision.
 *   - bmi di-clamp ≤ 999.99 mengikuti PerawatService::calculateBmi (kolom decimal(5,2)).
 *
 * IDEMPOTEN: pasien via NIK; visit via (patient, visit_date, station=TRIASE);
 * nurse_assessment via visit_id; queue via (visit, station) dengan sinkron status
 * pada run berulang.
 *
 * Jalankan: php artisan db:seed --class=TriaseDemoSeeder
 */
class TriaseDemoSeeder extends Seeder
{
    public function run(): void
    {
        $umum     = Insurer::where('type', 'UMUM')->value('id');
        $bpjs     = Insurer::where('type', 'BPJS')->value('id');
        $asuransi = Insurer::where('type', 'ASURANSI')->where('is_active', true)->value('id');

        // Perawat untuk assessed_by/finalized_by (opsional — boleh null kalau tak ada).
        $perawatId = Employee::whereHas('user.role', fn ($q) => $q->where('name', 'perawat'))->value('id')
            ?? Employee::value('id');

        $profiles = [
            // (a) Pasien BARU, BPJS — belum ada nurse_assessment (form kosong).
            [
                'nik' => '3275088801000001', 'rm' => 'RM-TR-001', 'name' => 'Andi Pratama',
                'gender' => 'L', 'dob' => '1995-02-14', 'guarantor' => 'BPJS', 'insurer' => $bpjs,
                'bpjs' => '0003344556677', 'class' => 'Baru', 'queue_status' => 'WAITING',
                'assessment' => null,
            ],

            // (b) Umum — DRAFT (TTV + keluhan), queue CALLED.
            [
                'nik' => '3275088801000002', 'rm' => 'RM-TR-002', 'name' => 'Rina Marlina',
                'gender' => 'P', 'dob' => '1982-09-30', 'guarantor' => 'UMUM', 'insurer' => $umum,
                'bpjs' => null, 'class' => 'Kontrol', 'queue_status' => 'CALLED',
                'assessment' => [
                    'finalized' => false,
                    'data' => [
                        'td_sistol' => 120, 'td_diastol' => 80, 'nadi' => 78,
                        'suhu' => '36.50', 'respirasi' => 18, 'spo2' => '98.00',
                        'kgd' => '110.00', 'pain_scale' => 1,
                        'berat_badan' => '58.00', 'tinggi_badan' => '160.00',
                        'has_allergy' => false, 'allergy_detail' => null,
                        'chief_complaint' => 'Mata kanan terasa pegal sejak 3 hari, penglihatan sedikit kabur.',
                        'rps' => 'Keluhan memberat saat membaca lama. Tidak ada nyeri hebat.',
                    ],
                ],
            ],

            // (c) Asuransi — DRAFT + ALERGI (uji badge alergi di stasiun lanjut), IN_PROGRESS.
            [
                'nik' => '3275088801000003', 'rm' => 'RM-TR-003', 'name' => 'Budi Santoso',
                'gender' => 'L', 'dob' => '1970-12-05', 'guarantor' => 'ASURANSI', 'insurer' => $asuransi,
                'bpjs' => null, 'class' => 'Baru', 'queue_status' => 'IN_PROGRESS',
                'assessment' => [
                    'finalized' => false,
                    'data' => [
                        'td_sistol' => 145, 'td_diastol' => 92, 'nadi' => 88,
                        'suhu' => '37.00', 'respirasi' => 20, 'spo2' => '97.00',
                        'kgd' => '215.00', 'pain_scale' => 3,
                        'berat_badan' => '82.00', 'tinggi_badan' => '168.00',
                        'has_allergy' => true, 'allergy_detail' => 'Penisilin, Sulfa',
                        'chief_complaint' => 'Penglihatan kedua mata makin kabur, riwayat hipertensi & DM.',
                        'rps' => 'DM tipe 2 tidak terkontrol, hipertensi. Kontrol rutin terlambat.',
                    ],
                ],
            ],

            // (d) BPJS — FINALIZED (dikunci) + alergi, queue COMPLETED.
            [
                'nik' => '3275088801000004', 'rm' => 'RM-TR-004', 'name' => 'Siti Aminah',
                'gender' => 'P', 'dob' => '1958-06-21', 'guarantor' => 'BPJS', 'insurer' => $bpjs,
                'bpjs' => '0008899001122', 'class' => 'Kontrol', 'queue_status' => 'COMPLETED',
                'assessment' => [
                    'finalized' => true,
                    'data' => [
                        'td_sistol' => 130, 'td_diastol' => 85, 'nadi' => 72,
                        'suhu' => '36.70', 'respirasi' => 18, 'spo2' => '99.00',
                        'kgd' => '125.00', 'pain_scale' => 0,
                        'berat_badan' => '55.00', 'tinggi_badan' => '152.00',
                        'has_allergy' => true, 'allergy_detail' => 'Aspirin',
                        'chief_complaint' => 'Kontrol pasca operasi katarak mata kiri, penglihatan membaik.',
                        'rps' => 'Post-op katarak OS 2 minggu lalu. Tidak ada keluhan nyeri.',
                    ],
                ],
            ],

            // (e) Umum — FINALIZED tanpa alergi, queue COMPLETED (tampilan "Selesai" bersih).
            [
                'nik' => '3275088801000005', 'rm' => 'RM-TR-005', 'name' => 'Dewi Lestari',
                'gender' => 'P', 'dob' => '1990-04-11', 'guarantor' => 'UMUM', 'insurer' => $umum,
                'bpjs' => null, 'class' => 'Baru', 'queue_status' => 'COMPLETED',
                'assessment' => [
                    'finalized' => true,
                    'data' => [
                        'td_sistol' => 118, 'td_diastol' => 76, 'nadi' => 70,
                        'suhu' => '36.40', 'respirasi' => 16, 'spo2' => '99.00',
                        'kgd' => '95.00', 'pain_scale' => 0,
                        'berat_badan' => '60.00', 'tinggi_badan' => '165.00',
                        'has_allergy' => false, 'allergy_detail' => null,
                        'chief_complaint' => 'Mata kering dan gatal terutama sore hari.',
                        'rps' => 'Sering di depan layar komputer. Tidak ada riwayat penyakit kronis.',
                    ],
                ],
            ],
        ];

        DB::transaction(function () use ($profiles, $perawatId) {
            foreach ($profiles as $prof) {
                $patient = Patient::firstOrCreate(
                    ['nik' => $prof['nik']],
                    [
                        'no_rm'         => $prof['rm'],
                        'identity_type' => 'KTP',
                        'name'          => $prof['name'],
                        'gender'        => $prof['gender'],
                        'date_of_birth' => $prof['dob'],
                        'bpjs_number'   => $prof['bpjs'],
                        'address'       => 'Medan',
                        'is_active'     => true,
                    ],
                );

                $visit = Visit::firstOrNew([
                    'patient_id'      => $patient->id,
                    'visit_date'      => today()->toDateString(),
                    'current_station' => 'TRIASE',
                ]);
                if (! $visit->exists) {
                    $visit->fill([
                        'insurer_id'     => $prof['insurer'],
                        'classification' => $prof['class'],
                        'guarantor_type' => $prof['guarantor'],
                        'visit_type'     => 'REGULAR',
                    ]);
                    $visit->save();
                }

                if ($prof['assessment']) {
                    $this->seedAssessment($visit, $prof['assessment'], $perawatId);
                }

                $this->enqueueTriase($visit, $prof['queue_status']);
            }
        });

        $this->command?->info('TriaseDemoSeeder: ' . count($profiles) . ' pasien di antrean TRIASE hari ini (baru/draft/alergi/finalized).');
    }

    /** Buat/refresh nurse_assessment sesuai skenario (idempoten via visit_id). */
    private function seedAssessment(Visit $visit, array $a, ?string $perawatId): void
    {
        $assessment = NurseAssessment::firstOrNew(['visit_id' => $visit->id]);
        if ($assessment->exists) {
            return;
        }

        $data = $a['data'];
        $data['bmi'] = $this->calculateBmi($data['berat_badan'] ?? null, $data['tinggi_badan'] ?? null);

        $assessment->fill(array_merge($data, [
            'assessed_by_id'  => $perawatId,
            'is_finalized'    => $a['finalized'],
            'finalized_at'    => $a['finalized'] ? now()->subMinutes(10) : null,
            'finalized_by_id' => $a['finalized'] ? $perawatId : null,
        ]))->save();

        if ($a['finalized']) {
            // Mirror finalizeAssessment: tandai triase selesai, TANPA memicu enqueue
            // DOKTER otomatis (seeder ini fokus stasiun TRIASE).
            $visit->update(['triase_completed_at' => $visit->triase_completed_at ?? now()->subMinutes(10)]);
        }
    }

    /**
     * Enqueue ke antrean TRIASE hari ini (idempoten via visit+station).
     * Sequence dihitung lintas grup prefix "TR" (TRIASE+REFRAKSIONIS).
     */
    private function enqueueTriase(Visit $visit, string $status): void
    {
        $existing = Queue::where('visit_id', $visit->id)->where('station', 'TRIASE')->first();
        if ($existing) {
            if ($existing->status !== $status) {
                $existing->update($this->statusTimestamps($status));
            }
            return;
        }

        $sharedStations = Queue::SHARED_PREFIX_GROUPS['TR'] ?? ['TRIASE'];
        $seq = (int) (Queue::whereIn('station', $sharedStations)->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;

        Queue::create(array_merge([
            'visit_id'       => $visit->id,
            'station'        => 'TRIASE',
            'queue_prefix'   => 'TR',
            'queue_sequence' => $seq,
            'queue_number'   => 'TR-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
        ], $this->statusTimestamps($status)));
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

    /** Sama dengan PerawatService::calculateBmi — clamp ≤ 999.99 (kolom decimal(5,2)). */
    private function calculateBmi($bb, $tb): ?float
    {
        $bb = $bb !== null ? (float) $bb : null;
        $tb = $tb !== null ? (float) $tb : null;
        if (! $bb || ! $tb || $tb == 0) {
            return null;
        }

        return min(round($bb / pow($tb / 100, 2), 2), 999.99);
    }
}
