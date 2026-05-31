<?php

namespace Database\Seeders;

use App\Models\DiagnosticOrder;
use App\Models\DiagnosticResult;
use App\Models\DiagnosticTestType;
use App\Models\Employee;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * PenunjangDemoSeeder — pasien demo untuk stasiun PENUNJANG (PenunjangView).
 *
 * Membuat beberapa pasien yang HARI INI duduk di antrean PENUNJANG dengan
 * order diagnostik bervariasi, sehingga seluruh tab/filter PenunjangView ada
 * datanya:
 *   - Filter status: WAITING (Menunggu) + COMPLETED (Selesai).
 *   - Filter penjamin: UMUM + BPJS + ASURANSI.
 *   - Order: Biometri (BIOM, form OD/OS khusus) + jenis lain (OCT/USG/FP).
 *   - Hasil: sebagian REQUESTED (belum dikerjakan), sebagian punya hasil
 *     (PENDING draft) dan ada yang sudah REVIEWED/COMPLETED.
 *
 * Catatan teknis:
 *   - getPatientQueue() memfilter Queue station=PENUNJANG + whereDate(created_at, today)
 *     + whereHas('visit'). Maka queue dibuat hari ini & visit valid.
 *   - test_type pada diagnostic_orders menyimpan KODE master (BIOM/OCT/...),
 *     bukan nama. Penanda form biometri di frontend = kode 'BIOM'.
 *
 * IDEMPOTEN: pasien via NIK; visit via (patient, visit_date, station=PENUNJANG);
 * order via (visit, test_type); hasil via diagnostic_order_id; queue via (visit, station).
 *
 * Jalankan: php artisan db:seed --class=PenunjangDemoSeeder
 */
class PenunjangDemoSeeder extends Seeder
{
    public function run(): void
    {
        $orderedBy = Employee::query()->value('id'); // dokter pengorder (boleh null)
        $umum      = Insurer::where('type', 'UMUM')->value('id');
        $bpjs      = Insurer::where('type', 'BPJS')->value('id');
        $asuransi  = Insurer::where('type', 'ASURANSI')->where('is_active', true)->value('id');

        // Pastikan jenis penunjang yang dipakai tersedia (aman di DB bersih).
        $types = [
            'BIOM'  => ['name' => 'Biometri',              'category' => 'Penunjang', 'sort' => 90],
            'OCT'   => ['name' => 'OCT Macula / Saraf',    'category' => 'Imaging',   'sort' => 10],
            'USG'   => ['name' => 'USG B-Scan',            'category' => 'Imaging',   'sort' => 40],
            'FP'    => ['name' => 'Foto Fundus',           'category' => 'Imaging',   'sort' => 20],
        ];
        foreach ($types as $code => $t) {
            DiagnosticTestType::firstOrCreate(
                ['code' => $code],
                ['name' => $t['name'], 'category' => $t['category'], 'is_active' => true, 'sort_order' => $t['sort']],
            );
        }

        // Definisi pasien demo + skenario order.
        $profiles = [
            [
                'nik' => '3275088801000001', 'rm' => 'RM-PNJ-001', 'name' => 'Rahmat Hidayat',
                'gender' => 'L', 'dob' => '1965-04-12', 'guarantor' => 'UMUM', 'insurer' => $umum,
                'bpjs' => null, 'class' => 'Pre-Op', 'queue_status' => 'WAITING',
                'orders' => [
                    ['type' => 'BIOM', 'eye' => 'ods', 'status' => 'REQUESTED', 'notes' => 'Biometri pra-bedah katarak OD.', 'result' => null],
                ],
            ],
            [
                'nik' => '3275088801000002', 'rm' => 'RM-PNJ-002', 'name' => 'Siti Aminah',
                'gender' => 'P', 'dob' => '1972-09-30', 'guarantor' => 'BPJS', 'insurer' => $bpjs,
                'bpjs' => '0001234567890', 'class' => 'Baru', 'queue_status' => 'IN_PROGRESS',
                'orders' => [
                    ['type' => 'OCT', 'eye' => 'os', 'status' => 'IN_PROGRESS', 'notes' => 'OCT makula OS — curiga edema.', 'result' => [
                        'result_status' => 'PENDING',
                        'expertise'     => ['kesimpulan' => '', 'ringkasan' => 'Akuisisi OCT makula OS selesai, menunggu ekspertise.'],
                        'reviewed'      => false,
                    ]],
                ],
            ],
            [
                'nik' => '3275088801000003', 'rm' => 'RM-PNJ-003', 'name' => 'Bambang Sutrisno',
                'gender' => 'L', 'dob' => '1958-01-22', 'guarantor' => 'UMUM', 'insurer' => $umum,
                'bpjs' => null, 'class' => 'Pre-Op', 'queue_status' => 'WAITING',
                'orders' => [
                    ['type' => 'BIOM', 'eye' => 'ods', 'status' => 'COMPLETED', 'notes' => 'Biometri OD & OS untuk IOL.', 'result' => [
                        'result_status' => 'REVIEWED',
                        'expertise'     => [
                            'kesimpulan' => 'Biometri layak implantasi IOL, target emetropia.',
                            'ringkasan'  => 'AL & K normal, ACD cukup.',
                            'od' => ['axial_length' => '23.45', 'k1' => '43.50', 'k2' => '44.00', 'acd' => '3.10', 'recommended_iol_power' => '+21.0', 'iol_type' => 'MONOFOCAL', 'brand' => 'Alcon SN60WF'],
                            'os' => ['axial_length' => '23.60', 'k1' => '43.25', 'k2' => '43.75', 'acd' => '3.15', 'recommended_iol_power' => '+20.5', 'iol_type' => 'MONOFOCAL', 'brand' => 'Alcon SN60WF'],
                        ],
                        'reviewed'      => true,
                    ]],
                    ['type' => 'USG', 'eye' => 'od', 'status' => 'REQUESTED', 'notes' => 'USG B-Scan OD — media keruh, nilai segmen posterior.', 'result' => null],
                ],
            ],
            [
                'nik' => '3275088801000004', 'rm' => 'RM-PNJ-004', 'name' => 'Dewi Lestari',
                'gender' => 'P', 'dob' => '1985-06-05', 'guarantor' => 'ASURANSI', 'insurer' => $asuransi,
                'bpjs' => null, 'class' => 'Baru', 'queue_status' => 'COMPLETED',
                'orders' => [
                    ['type' => 'FP', 'eye' => 'ods', 'status' => 'COMPLETED', 'notes' => 'Foto fundus ODS — skrining retinopati.', 'result' => [
                        'result_status' => 'COMPLETED',
                        'expertise'     => ['kesimpulan' => 'Fundus dalam batas normal, tidak tampak tanda retinopati.', 'ringkasan' => 'Papil batas tegas, makula reflek fovea +, vaskular normal.'],
                        'reviewed'      => false,
                    ]],
                ],
            ],
        ];

        DB::transaction(function () use ($profiles, $orderedBy) {
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
                    'current_station' => 'PENUNJANG',
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

                foreach ($prof['orders'] as $o) {
                    $order = DiagnosticOrder::firstOrCreate(
                        ['visit_id' => $visit->id, 'test_type' => $o['type']],
                        [
                            'ordered_by_id' => $orderedBy,
                            'eye_side'      => $o['eye'],
                            'notes'         => $o['notes'],
                            'status'        => $o['status'],
                        ],
                    );

                    if ($o['result']) {
                        DiagnosticResult::firstOrCreate(
                            ['diagnostic_order_id' => $order->id],
                            [
                                'performed_by_id' => $orderedBy,
                                'expertise_data'  => $o['result']['expertise'],
                                'notes'           => $o['notes'],
                                'result_status'   => $o['result']['result_status'],
                                'uploaded_at'     => now()->subHour(),
                                'reviewed_by_id'  => $o['result']['reviewed'] ? $orderedBy : null,
                                'reviewed_at'     => $o['result']['reviewed'] ? now()->subMinutes(20) : null,
                            ],
                        );
                    }
                }

                $this->enqueuePenunjang($visit, $prof['queue_status']);
            }
        });

        $this->command?->info('PenunjangDemoSeeder: ' . count($profiles) . ' pasien di antrean PENUNJANG hari ini.');
    }

    /** Enqueue ke antrean PENUNJANG hari ini (idempoten via visit+station). */
    private function enqueuePenunjang(Visit $visit, string $status): void
    {
        $existing = Queue::where('visit_id', $visit->id)->where('station', 'PENUNJANG')->first();
        if ($existing) {
            // Pastikan status sesuai skenario (untuk run berulang).
            if ($existing->status !== $status) {
                $existing->update($this->statusTimestamps($status));
            }
            return;
        }

        $seq = (int) (Queue::where('station', 'PENUNJANG')->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;
        Queue::create(array_merge([
            'visit_id'       => $visit->id,
            'station'        => 'PENUNJANG',
            'queue_prefix'   => 'P',
            'queue_sequence' => $seq,
            'queue_number'   => 'P-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'status'         => $status,
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
}
