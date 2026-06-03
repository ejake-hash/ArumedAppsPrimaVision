<?php

namespace Database\Seeders;

use App\Models\DiagnosticOrder;
use App\Models\DiagnosticResult;
use App\Models\DiagnosticTestType;
use App\Models\DoctorExamination;
use App\Models\DoctorSchedule;
use App\Models\Medication;
use App\Models\NurseAssessment;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Procedure;
use App\Models\Queue;
use App\Models\RefractionRecord;
use App\Models\User;
use App\Models\VisitService;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TtdResumeTestSeeder - menyiapkan tes TANDA TANGAN PIN -> Finalisasi -> modal Resume.
 *
 * Mengatur PIN tanda tangan tiap dokter on-duty ke '123456' (PLAINTEXT, sesuai
 * DokterController::verifyPin yg pakai hash_equals plaintext), lalu membuat 1
 * pasien "(TTD Test)" siap-finalisasi per dokter (RME DRAFT lengkap, segmen enum
 * valid, planning PULANG, penunjang COMPLETED).
 *
 * CATATAN BUG (existing, di luar lingkup seeder): DokterService::signDocument
 * memverifikasi PIN dgn Hash::check padahal PIN plaintext -> TTD dokumen (jalur
 * itu) akan 401. TAPI alur "tandatangani RME -> Finalisasi -> modal Resume" pakai
 * verifyPin (plaintext), jadi tes ini BERFUNGSI dgn PIN 123456.
 *
 * Run manual (tidak terdaftar di DatabaseSeeder):
 *   php artisan db:seed --class=TtdResumeTestSeeder
 *
 * Cara tes:
 *   1. Login dokter on-duty (mis. dokter/888888), buka layar Dokter.
 *   2. Pilih pasien "... (TTD Test)" -> semua tab terisi.
 *   3. Klik Tanda Tangan -> masukkan PIN 123456 -> klik Finalisasi.
 *   4. Modal "Resume Medis Rawat Jalan" muncul -> Setuju & Terbitkan.
 */
class TtdResumeTestSeeder extends Seeder
{
    private const PIN = '123456';

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
            $this->command?->warn("TtdResumeTestSeeder: tidak ada jadwal dokter aktif hari ini (dow={$dow}). Lewati.");
            return;
        }

        $procedures  = $this->resolveProcedures();
        $medications = $this->resolveMedications();
        $penunjang   = $this->resolvePenunjangType();

        $made = 0;
        $pinSet = 0;

        DB::transaction(function () use ($schedulesToday, $procedures, $medications, $penunjang, &$made, &$pinSet) {
            $docIndex = 0;
            foreach ($schedulesToday as $sched) {
                $docIndex++;
                $employee = $sched->employee;
                if (! $employee) {
                    continue;
                }

                // Set PIN tanda tangan dokter (plaintext) agar bisa TTD saat tes.
                $user = User::where('employee_id', $employee->id)->first();
                if ($user) {
                    $user->pin = self::PIN;
                    $user->save();
                    $pinSet++;
                }

                $suffix  = str_pad((string) $docIndex, 2, '0', STR_PAD_LEFT) . '80';
                $empHash = str_pad((string) crc32($employee->id), 8, '0', STR_PAD_LEFT);
                $nik     = substr('3277' . $suffix . $empHash, 0, 16);

                $patient = Patient::firstOrCreate(
                    ['nik' => $nik],
                    [
                        'no_rm'         => 'TT' . $suffix . substr($empHash, 0, 4),
                        'name'          => 'Budi Santoso (TTD Test)',
                        'gender'        => 'L',
                        'date_of_birth' => '1960-08-09',
                        'phone'         => '0814-' . $suffix . '-0001',
                        'province'      => 'Sumatera Utara',
                        'allergy_notes' => null,
                        'is_active'     => true,
                    ]
                );

                $this->seedReadyVisit($patient, $employee, $sched, $procedures, $medications, $penunjang);
                $made++;
            }
        });

        $this->command?->info(
            "TtdResumeTestSeeder selesai - {$made} pasien '(TTD Test)' siap difinalisasi; "
            . "PIN tanda tangan {$pinSet} dokter di-set ke '" . self::PIN . "'."
        );
    }

    // ---------------------------------------------------------------------

    private function seedReadyVisit(Patient $patient, $employee, DoctorSchedule $sched, $procedures, $medications, ?DiagnosticTestType $penunjang): void
    {
        $visit = Visit::firstOrNew([
            'patient_id'      => $patient->id,
            'visit_date'      => today()->toDateString(),
            'current_station' => 'DOKTER',
        ]);
        if (! $visit->exists) {
            $visit->fill([
                'insurer_id'         => null,
                'doctor_schedule_id' => $sched->id,
                'classification'     => 'Baru',
                'guarantor_type'     => 'UMUM',
            ]);
            $visit->save();
        }

        // Tab Pemeriksaan: vitals (Triase).
        NurseAssessment::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'assessed_by_id'  => null,
                'td_sistol'       => 128,
                'td_diastol'      => 84,
                'nadi'            => 78,
                'suhu'            => 36.6,
                'respirasi'       => 18,
                'spo2'            => 98,
                'kgd'             => 112,
                'has_allergy'     => false,
                'allergy_detail'  => null,
                'chief_complaint' => 'Penglihatan kedua mata buram, lebih berat di kanan.',
                'rps'             => 'Buram progresif tanpa nyeri, mata tidak merah.',
                'pain_scale'      => 0,
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
                    'visus_awal_od'    => '6/30',
                    'visus_awal_os'    => '6/12',
                    'pinhole_od'       => '6/15',
                    'pinhole_os'       => '6/9',
                    'visus_akhir_od'   => '6/15',
                    'visus_akhir_os'   => '6/9',
                    'iop_od'           => 16,
                    'iop_os'           => 15,
                    'clinical_notes'   => 'Visus dasar untuk konsultasi dokter.',
                    'is_finalized'     => true,
                ]
            );
        }

        // Tab Pemeriksaan Mata + Tab SOAP: DoctorExamination (DRAFT, planning PULANG).
        DoctorExamination::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'doctor_id'      => $employee->id,
                'anamnese'       => 'Penglihatan kedua mata buram perlahan, lebih berat di kanan, silau.',
                'slitlamp_notes' => 'Konjungtiva tenang, kornea jernih. Lensa OD keruh.',
                // Segmen: HANYA enum valid 'Normal'/'Tidak Normal'/'Tidak Dapat Dinilai'.
                'sa_kornea_od' => 'Normal',       'sa_kornea_os' => 'Normal',
                'sa_coa_od'    => 'Normal',       'sa_coa_os'    => 'Normal',
                'sa_iris_od'   => 'Normal',       'sa_iris_os'   => 'Normal',
                'sa_pupil_od'  => 'Normal',       'sa_pupil_os'  => 'Normal',
                'sa_lensa_od'  => 'Tidak Normal', 'sa_lensa_os'  => 'Normal',
                'sp_papil_od'    => 'Normal',     'sp_papil_os'    => 'Normal',
                'sp_macula_od'   => 'Normal',     'sp_macula_os'   => 'Normal',
                'sp_retina_od'   => 'Normal',     'sp_retina_os'   => 'Normal',
                'sp_vitreous_od' => 'Normal',     'sp_vitreous_os' => 'Normal',
                'soap_subjective' => 'Penglihatan kedua mata makin buram, kanan lebih berat, silau.',
                'soap_objective'  => 'VOD 6/30 VOS 6/12; TIO 16/15 mmHg; Lensa OD keruh.',
                'soap_assessment' => 'Katarak senilis imatur OD (H25.1)',
                'soap_plan'       => 'Air mata buatan, kontrol 1 bulan, edukasi rencana operasi bila visus menurun.',
                'diagnosis_utama'    => 'H25.1',
                'diagnosis_sekunder' => ['H52.4'],
                'tindakan_codes'     => $procedures->pluck('icd9_code')->filter()->values()->all(),
                'planning'           => 'PULANG_BEROBAT_JALAN',
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

        // Tab Resep: Prescription + item.
        $presc = Prescription::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'prescribed_by_id' => $employee->id,
                'status'           => 'DRAFT',
                'notes'            => 'Tetes mata sesuai aturan, kontrol bila keluhan bertambah.',
            ]
        );
        if (! $presc->items()->exists()) {
            foreach ($medications as $med) {
                PrescriptionItem::create([
                    'prescription_id' => $presc->id,
                    'medication_id'   => $med->id,
                    'quantity'        => 1,
                    'dose'            => '1 tetes',
                    'frequency'       => '4x/hari',
                    'route'           => 'Tetes mata',
                    'duration_days'   => 14,
                    'notes'           => 'ODS',
                ]);
            }
        }

        // Tab Penunjang: COMPLETED + hasil biometri.
        if ($penunjang) {
            $order = DiagnosticOrder::firstOrCreate(
                ['visit_id' => $visit->id, 'test_type' => $penunjang->code],
                [
                    'ordered_by_id' => $employee->id,
                    'eye_side'      => 'ods',
                    'notes'         => 'Pra-bedah katarak - biometri IOL.',
                    'status'        => 'COMPLETED',
                ]
            );
            DiagnosticResult::firstOrCreate(
                ['diagnostic_order_id' => $order->id],
                [
                    'performed_by_id' => null,
                    'expertise_data'  => [
                        'AL (Axial Length)' => 'OD 23.50 mm, OS 23.65 mm',
                        'K1 / K2'           => 'OD 43.60 / 44.10, OS 43.30 / 43.80',
                        'Target IOL'        => 'OD +20.5 D (SRK/T)',
                        'kesimpulan'        => 'Biometri layak untuk implantasi IOL.',
                    ],
                    'notes'          => 'Hasil biometri telah diverifikasi.',
                    'result_status'  => 'REVIEWED',
                    'uploaded_at'    => now()->subHour(),
                    'reviewed_by_id' => $employee->id,
                    'reviewed_at'    => now()->subMinutes(30),
                ]
            );
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
}
