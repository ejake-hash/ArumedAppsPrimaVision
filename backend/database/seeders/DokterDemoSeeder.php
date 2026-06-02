<?php

namespace Database\Seeders;

use App\Models\DiagnosticOrder;
use App\Models\DiagnosticResult;
use App\Models\DiagnosticTestType;
use App\Models\DoctorExamination;
use App\Models\DoctorSchedule;
use App\Models\Insurer;
use App\Models\Medication;
use App\Models\NurseAssessment;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Procedure;
use App\Models\Queue;
use App\Models\RefractionRecord;
use App\Models\VisitService;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DokterDemoSeeder - pasien demo stasiun DOKTER (RME), 3 jenis penjamin
 * (UMUM, BPJS, ASURANSI), dengan riwayat SOAP + kunjungan hari ini RME lengkap.
 *
 * Untuk SETIAP dokter yang punya jadwal hari ini:
 *   - 4 pasien WAITING di antrean DOKTER hari ini (UMUM, BPJS, ASURANSI, dan
 *     1 pasien dengan order PENUNJANG masih OPEN/REQUESTED untuk uji gate finalize),
 *     visit.doctor_schedule_id = jadwal dokter ybs (syarat getPatientQueue).
 *   - Tiap pasien: 2 kunjungan lama (finalized, SOAP) untuk riwayat RME.
 *   - Kunjungan hari ini diisi penuh (DRAFT, bukan read-only) supaya tiap tab
 *     DokterView ada datanya: vitals, visus, segmen mata, tindakan, resep,
 *     penunjang, dan SOAP.
 *
 * IDEMPOTEN: pasien via NIK; visit via (patient, visit_date[, station]); anak
 * record via firstOrCreate by visit_id.
 *
 * Jalankan: php artisan db:seed --class=DokterDemoSeeder
 */
class DokterDemoSeeder extends Seeder
{
    /** Definisi 3 pasien per dokter (NIK/RM/BPJS dibuat unik per-dokter). */
    private array $profiles = [
        [
            'key' => 'umum', 'name' => 'Sumarni Hadi', 'gender' => 'P', 'dob' => '1969-02-11',
            'guarantor' => 'UMUM', 'bpjs' => null, 'class' => 'Kontrol', 'allergy' => null,
            'chief' => 'Penglihatan mata kanan buram perlahan sejak 3 bulan.',
        ],
        [
            'key' => 'bpjs', 'name' => 'Joko Prasetyo', 'gender' => 'L', 'dob' => '1957-10-03',
            'guarantor' => 'BPJS', 'bpjs' => '00099887', 'class' => 'Kontrol', 'allergy' => null,
            'chief' => 'Kontrol pasca operasi katarak mata kiri, mata terasa berair.',
        ],
        [
            'key' => 'asuransi', 'name' => 'Maria Gunawan', 'gender' => 'P', 'dob' => '1982-12-19',
            'guarantor' => 'ASURANSI', 'bpjs' => null, 'class' => 'Baru', 'allergy' => 'Sulfa',
            'chief' => 'Mata sering pegal dan kabur saat membaca dekat.',
        ],
        // Skenario uji: pasien dengan order PENUNJANG masih OPEN (REQUESTED, belum ada
        // hasil). Dipakai untuk menguji gate finalize-vs-penunjang (nextAfterDokter
        // merutekan ke PENUNJANG selama status REQUESTED/IN_PROGRESS). RME hari ini
        // SENGAJA dibuat DRAFT tanpa hasil penunjang.
        [
            'key' => 'penunjang_open', 'name' => 'Bambang Wijaya', 'gender' => 'L', 'dob' => '1965-07-22',
            'guarantor' => 'UMUM', 'bpjs' => null, 'class' => 'Baru', 'allergy' => null,
            'chief' => 'Penglihatan kedua mata kabur, direncanakan biometri pra-bedah.',
            'penunjang_open' => true,
        ],
    ];

    public function run(): void
    {
        $dow = (int) now()->isoWeekday(); // 1=Senin .. 7=Minggu

        $schedulesToday = DoctorSchedule::where('day_of_week', $dow)
            ->where('is_active', true)
            ->with('employee')
            ->get()
            ->unique('employee_id')
            ->values();

        if ($schedulesToday->isEmpty()) {
            $this->command?->warn("DokterDemoSeeder: tidak ada jadwal dokter untuk hari ini (dow={$dow}). Lewati.");
            return;
        }

        $asuransiInsurer = Insurer::where('type', 'ASURANSI')->where('is_active', true)->first();

        $procedures  = $this->resolveProcedures();
        $medications = $this->resolveMedications();
        $penunjang   = $this->resolvePenunjangType();

        DB::transaction(function () use ($schedulesToday, $asuransiInsurer, $procedures, $medications, $penunjang) {
            $docIndex = 0;
            foreach ($schedulesToday as $sched) {
                $docIndex++;
                $employee = $sched->employee;
                if (! $employee) {
                    continue;
                }

                $patIndex = 0;
                foreach ($this->profiles as $prof) {
                    $patIndex++;
                    $suffix  = str_pad((string) $docIndex, 2, '0', STR_PAD_LEFT)
                             . str_pad((string) $patIndex, 2, '0', STR_PAD_LEFT);
                    $empHash = str_pad((string) crc32($employee->id), 8, '0', STR_PAD_LEFT);
                    $nik     = substr('3299' . $suffix . $empHash, 0, 16);
                    $bpjs    = $prof['bpjs'] ? substr($prof['bpjs'] . $suffix . $empHash, 0, 13) : null;

                    $patient = Patient::firstOrCreate(
                        ['nik' => $nik],
                        [
                            'no_rm'         => 'DK' . $suffix . substr($empHash, 0, 4),
                            'name'          => $prof['name'] . ' (Demo ' . strtoupper($prof['key']) . ')',
                            'gender'        => $prof['gender'],
                            'date_of_birth' => $prof['dob'],
                            'phone'         => '0812-' . $suffix . '-' . str_pad((string) $patIndex, 4, '0', STR_PAD_LEFT),
                            'province'      => 'Sumatera Utara',
                            'bpjs_number'   => $bpjs,
                            'allergy_notes' => $prof['allergy'],
                            'is_active'     => true,
                        ]
                    );

                    $insurerId = $prof['guarantor'] === 'ASURANSI' ? $asuransiInsurer?->id : null;

                    $this->seedPastVisitsWithSoap($patient, $employee, $prof, $insurerId);
                    $this->seedTodayVisit($patient, $employee, $sched, $prof, $insurerId, $procedures, $medications, $penunjang);
                }
            }
        });

        $this->command?->info(
            'DokterDemoSeeder selesai - ' . $schedulesToday->count()
            . ' dokter on-duty x 4 pasien (UMUM/BPJS/ASURANSI + 1 PENUNJANG-OPEN) '
            . '+ riwayat SOAP + RME hari ini lengkap.'
        );
    }

    // ---------------------------------------------------------------------
    // Master resolvers (toleran is_active NULL; buat fallback bila kosong)
    // ---------------------------------------------------------------------

    private function resolveProcedures(): \Illuminate\Support\Collection
    {
        $query = fn () => Procedure::where(fn ($x) => $x->where('is_active', true)->orWhereNull('is_active'))
            ->orderBy('code')->take(2)->get();

        $procs = $query();
        if ($procs->count() < 2) {
            $defs = [
                ['code' => 'TND-DEMO-1', 'name' => 'Funduskopi',      'icd9_code' => '95.11'],
                ['code' => 'TND-DEMO-2', 'name' => 'Tonometri (TIO)', 'icd9_code' => '89.11'],
            ];
            foreach ($defs as $d) {
                Procedure::firstOrCreate(
                    ['code' => $d['code']],
                    ['name' => $d['name'], 'category' => 'Tindakan', 'icd9_code' => $d['icd9_code'], 'is_active' => true]
                );
            }
            $procs = $query();
        }

        return $procs;
    }

    private function resolveMedications(): \Illuminate\Support\Collection
    {
        $query = fn () => Medication::where(fn ($x) => $x->where('is_active', true)->orWhereNull('is_active'))
            ->orderBy('code')->take(2)->get();

        $meds = $query();
        if ($meds->count() < 2) {
            $defs = [
                ['code' => 'OBT-DEMO-1', 'name' => 'Cendo Lyteers Tetes Mata', 'unit' => 'Botol'],
                ['code' => 'OBT-DEMO-2', 'name' => 'Polydex Tetes Mata',       'unit' => 'Botol'],
            ];
            foreach ($defs as $d) {
                Medication::firstOrCreate(
                    ['code' => $d['code']],
                    ['name' => $d['name'], 'unit' => $d['unit'], 'is_active' => true]
                );
            }
            $meds = $query();
        }

        return $meds;
    }

    private function resolvePenunjangType(): ?DiagnosticTestType
    {
        return DiagnosticTestType::where('code', 'BIOM')->first()
            ?? DiagnosticTestType::where('name', 'like', '%Biometri%')->first()
            ?? DiagnosticTestType::query()->first();
    }

    // ---------------------------------------------------------------------
    // Riwayat: 2 kunjungan lama finalized dengan SOAP
    // ---------------------------------------------------------------------

    private function seedPastVisitsWithSoap(Patient $patient, $employee, array $prof, ?string $insurerId): void
    {
        $past = [
            [
                'days_ago' => 90,
                's' => $prof['chief'],
                'o' => 'VOD 6/18 ph 6/9, VOS 6/12. Lensa keruh nuklear OD. Segmen anterior tenang.',
                'a' => 'Katarak senilis nuklear OD (H25.1).',
                'p' => 'Edukasi rencana operasi katarak Phaco + IOL. Air mata buatan. Kontrol 1 bulan.',
                'icd9' => ['16.21'],
            ],
            [
                'days_ago' => 30,
                's' => 'Penglihatan mata kanan dirasakan semakin menurun, tidak nyeri, tidak merah.',
                'o' => 'VOD 6/24 ph 6/12, VOS 6/9. Lensa OD keruh NO3. Fundus sulit dinilai (media keruh).',
                'a' => 'Katarak senilis nuklear OD progresif (H25.1).',
                'p' => 'Direncanakan Phacoemulsifikasi + IOL OD. Biometri dijadwalkan. Kontrol pra-bedah.',
                'icd9' => ['16.21', '95.02'],
            ],
        ];

        foreach ($past as $row) {
            $visitDate = Carbon::today()->subDays($row['days_ago']);

            $visit = Visit::firstOrNew([
                'patient_id' => $patient->id,
                'visit_date' => $visitDate->toDateString(),
            ]);
            if (! $visit->exists) {
                $visit->fill([
                    'insurer_id'      => $insurerId,
                    'classification'  => 'Kontrol',
                    'current_station' => 'SELESAI',
                    'guarantor_type'  => $prof['guarantor'],
                    'created_at'      => $visitDate->copy()->setTime(9, 0),
                    'updated_at'      => $visitDate->copy()->setTime(11, 0),
                ]);
                $visit->save();
            }

            DoctorExamination::firstOrCreate(
                ['visit_id' => $visit->id],
                [
                    'doctor_id'           => $employee->id,
                    'anamnese'            => $row['s'],
                    'soap_subjective'     => $row['s'],
                    'soap_objective'      => $row['o'],
                    'soap_assessment'     => $row['a'],
                    'soap_plan'           => $row['p'],
                    'diagnosis_utama'     => 'H25.1',
                    'diagnosis_sekunder'  => [],
                    'tindakan_codes'      => $row['icd9'],
                    'planning'            => 'PULANG_BEROBAT_JALAN',
                    'is_finalized'        => true,
                    'finalized_at'        => $visitDate->copy()->setTime(11, 0),
                    'digital_signature'   => $employee->name . ($employee->sip ? " (SIP: {$employee->sip})" : ''),
                    'signature_timestamp' => $visitDate->copy()->setTime(11, 0),
                ]
            );
        }
    }

    // ---------------------------------------------------------------------
    // Kunjungan hari ini: WAITING di DOKTER + RME lengkap (DRAFT)
    // ---------------------------------------------------------------------

    private function seedTodayVisit(Patient $patient, $employee, DoctorSchedule $sched, array $prof, ?string $insurerId, $procedures, $medications, ?DiagnosticTestType $penunjang): void
    {
        $visit = Visit::firstOrNew([
            'patient_id'      => $patient->id,
            'visit_date'      => today()->toDateString(),
            'current_station' => 'DOKTER',
        ]);
        if (! $visit->exists) {
            $visit->fill([
                'insurer_id'         => $insurerId,
                'doctor_schedule_id' => $sched->id,
                'classification'     => $prof['class'],
                'guarantor_type'     => $prof['guarantor'],
            ]);
            $visit->save();
        }

        $isBpjs     = $prof['guarantor'] === 'BPJS';
        $isAsuransi = $prof['guarantor'] === 'ASURANSI';

        // Tab Pemeriksaan: vitals (Triase).
        NurseAssessment::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'assessed_by_id'  => null,
                'td_sistol'       => $isBpjs ? 140 : 120,
                'td_diastol'      => $isBpjs ? 90 : 80,
                'nadi'            => 78,
                'suhu'            => 36.6,
                'respirasi'       => 18,
                'spo2'            => 98,
                'kgd'             => $isBpjs ? 165 : 110,
                'has_allergy'     => ! empty($prof['allergy']),
                'allergy_detail'  => $prof['allergy'],
                'chief_complaint' => $prof['chief'],
                'rps'             => 'Keluhan progresif tanpa nyeri hebat maupun mata merah.',
                'pain_scale'      => 1,
                'is_finalized'    => true,
                'finalized_at'    => now()->subHours(2),
            ]
        );

        // Tab Pemeriksaan: visus (Refraksi).
        if (Schema::hasTable('refraction_records')) {
            RefractionRecord::firstOrCreate(
                ['visit_id' => $visit->id],
                [
                    'examined_by_id'   => null,
                    'examination_date' => today(),
                    'visus_awal_od'    => '6/18',
                    'visus_awal_os'    => '6/9',
                    'pinhole_od'       => '6/9',
                    'pinhole_os'       => '6/6',
                    'visus_akhir_od'   => '6/9',
                    'visus_akhir_os'   => '6/6',
                    'iop_od'           => 16,
                    'iop_os'           => 15,
                    'clinical_notes'   => 'Visus dasar untuk konsultasi dokter.',
                    'is_finalized'     => true,
                ]
            );
        }

        // Tab Pemeriksaan Mata + Tab SOAP: DoctorExamination (DRAFT).
        DoctorExamination::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'doctor_id'      => $employee->id,
                'anamnese'       => 'Penglihatan kabur progresif 1 bulan, silau. Riwayat hipertensi terkontrol.',
                'slitlamp_notes' => 'Konjungtiva tenang, kornea jernih.',
                'sa_kornea_od' => 'Jernih',      'sa_kornea_os' => 'Jernih',
                'sa_coa_od'    => 'Dalam',        'sa_coa_os'    => 'Dalam',
                'sa_iris_od'   => 'Normal',       'sa_iris_os'   => 'Normal',
                'sa_pupil_od'  => 'Bulat, RC +',  'sa_pupil_os'  => 'Bulat, RC +',
                'sa_lensa_od'  => 'Keruh',        'sa_lensa_os'  => 'Agak keruh',
                'sp_papil_od'    => 'Batas tegas',    'sp_papil_os'    => 'Batas tegas',
                'sp_macula_od'   => 'Reflek fovea +', 'sp_macula_os'   => 'Reflek fovea +',
                'sp_retina_od'   => 'Datar',          'sp_retina_os'   => 'Datar',
                'sp_vitreous_od' => 'Jernih',         'sp_vitreous_os' => 'Jernih',
                'soap_subjective' => 'Penglihatan mata kanan makin kabur sejak 1 bulan, silau.',
                'soap_objective'  => 'VOD 6/18 VOS 6/9; TIO 16/15 mmHg; Lensa OD keruh.',
                'soap_assessment' => 'Katarak senilis imatur OD (H25.1)',
                'soap_plan'       => $isAsuransi
                    ? 'Rencana fakoemulsifikasi + IOL OD. Pemeriksaan pra-bedah.'
                    : 'Air mata buatan, kontrol 1 bulan, edukasi operasi bila visus menurun.',
                'diagnosis_utama'    => 'H25.1',
                'diagnosis_sekunder' => ['H40.9'],
                'tindakan_codes'     => $procedures->pluck('icd9_code')->filter()->values()->all(),
                'is_finalized'       => false,
            ]
        );

        // Tab Tindakan: 2 VisitService.
        foreach ($procedures as $proc) {
            VisitService::firstOrCreate(
                ['visit_id' => $visit->id, 'procedure_id' => $proc->id],
                ['performed_by_id' => $employee->id, 'quantity' => 1, 'price' => 0]
            );
        }

        // Tab Resep: Prescription + 2 item.
        $presc = Prescription::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'prescribed_by_id' => $employee->id,
                'status'           => 'DRAFT',
                'notes'            => 'Tetes mata sesuai aturan, kontrol bila keluhan bertambah.',
            ]
        );
        if (! $presc->items()->exists()) {
            $rx = [
                ['med' => $medications[0] ?? null, 'dose' => '1 tetes', 'freq' => '4x/hari', 'dur' => 14, 'posisi' => 'ODS'],
                ['med' => $medications[1] ?? null, 'dose' => '1 tetes', 'freq' => '3x/hari', 'dur' => 7,  'posisi' => 'OD (Kanan)'],
            ];
            foreach ($rx as $r) {
                if (! $r['med']) {
                    continue;
                }
                PrescriptionItem::create([
                    'prescription_id' => $presc->id,
                    'medication_id'   => $r['med']->id,
                    'quantity'        => 1,
                    'dosage'          => $r['dose'],
                    'instructions'    => $r['freq'] . ' ' . $r['posisi'],
                    'dose'            => $r['dose'],
                    'frequency'       => $r['freq'],
                    'route'           => 'Tetes mata',
                    'duration_days'   => $r['dur'],
                    'notes'           => $r['posisi'],
                ]);
            }
        }

        // Tab Penunjang.
        //   - Skenario `penunjang_open`: order REQUESTED (belum selesai, tanpa hasil)
        //     → nextAfterDokter merutekan ke PENUNJANG; berguna menguji gate finalize.
        //   - Pasien lain: order COMPLETED + hasil biometri (alur normal).
        if ($penunjang) {
            $penunjangOpen = ! empty($prof['penunjang_open']);

            $order = DiagnosticOrder::firstOrCreate(
                ['visit_id' => $visit->id, 'test_type' => $penunjang->code],
                [
                    'ordered_by_id' => $employee->id,
                    'eye_side'      => 'ods',
                    'notes'         => $penunjangOpen
                        ? 'Biometri IOL — MENUNGGU dikerjakan unit penunjang.'
                        : 'Pra-bedah katarak - biometri IOL.',
                    'status'        => $penunjangOpen ? 'REQUESTED' : 'COMPLETED',
                ]
            );

            if (! $penunjangOpen) {
                DiagnosticResult::firstOrCreate(
                    ['diagnostic_order_id' => $order->id],
                    [
                        'performed_by_id' => null,
                        'expertise_data'  => [
                            'AL (Axial Length)' => 'OD 23.45 mm, OS 23.60 mm',
                            'K1 / K2'           => 'OD 43.50 / 44.00, OS 43.25 / 43.75',
                            'Target IOL'        => 'OD +21.0 D, OS +20.5 D (SRK/T)',
                            'kesimpulan'        => 'Biometri layak untuk implantasi IOL, target emetropia.',
                        ],
                        'notes'          => 'Hasil biometri telah diverifikasi.',
                        'result_status'  => 'REVIEWED',
                        'uploaded_at'    => now()->subHour(),
                        'reviewed_by_id' => $employee->id,
                        'reviewed_at'    => now()->subMinutes(30),
                    ]
                );
            }
        }

        $this->enqueueDokter($visit);
    }

    /** Enqueue ke antrean DOKTER hari ini (idempoten via visit+station). */
    private function enqueueDokter(Visit $visit): void
    {
        if (Queue::where('visit_id', $visit->id)->where('station', 'DOKTER')->exists()) {
            return;
        }
        $seq = (int) (Queue::where('station', 'DOKTER')->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;
        Queue::create([
            'visit_id'       => $visit->id,
            'station'        => 'DOKTER',
            'queue_prefix'   => 'D',
            'queue_sequence' => $seq,
            'queue_number'   => 'D-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'status'         => 'WAITING',
        ]);
    }
}
