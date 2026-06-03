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
use App\Models\VisitService;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ResumeTestSeeder - 1 pasien SIAP-FINALISASI per dokter on-duty hari ini, khusus
 * untuk menguji fitur "Preview & Terbitkan Resume Medis" pasca-Finalisasi.
 *
 * Setiap pasien:
 *   - WAITING di antrean DOKTER hari ini (nama "(Resume Test)" supaya mudah dikenali).
 *   - Semua tab terisi (Pemeriksaan/Tindakan/Resep) + DoctorExamination DRAFT lengkap
 *     dengan anamnese + SOAP S/O/A/P + diagnosis_utama + tindakan_codes (sumber yang
 *     dibaca generateMedicalResume untuk auto-isi resume).
 *   - planning PULANG (PULANG_BEROBAT_JALAN) -> finalize langsung ke KASIR, TIDAK ada
 *     penunjang open yang menghalangi finalize.
 *   - Penunjang sengaja COMPLETED + hasil biometri (tampil di bagian "Hasil Penunjang"
 *     pada modal resume).
 *
 * Run manual (tidak terdaftar di DatabaseSeeder):
 *   php artisan db:seed --class=ResumeTestSeeder
 *
 * Idempoten: firstOrCreate / firstOrNew per (patient, visit_date, station).
 *
 * Cara tes di UI:
 *   1. Login sebagai dokter on-duty (mis. dokter/888888), buka layar Dokter.
 *   2. Pilih pasien "... (Resume Test)" -> semua tab sudah terisi.
 *   3. Tanda tangani (PIN) lalu klik Finalisasi.
 *   4. Modal "Resume Medis Pasien" muncul -> tinjau / edit S/O/A/P ->
 *      "Setuju & Terbitkan" -> toast "Resume medis pasien diterbitkan".
 */
class ResumeTestSeeder extends Seeder
{
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
            $this->command?->warn("ResumeTestSeeder: tidak ada jadwal dokter aktif hari ini (dow={$dow}). Lewati.");
            return;
        }

        $procedures  = $this->resolveProcedures();
        $medications = $this->resolveMedications();
        $penunjang   = $this->resolvePenunjangType();

        $made = 0;

        DB::transaction(function () use ($schedulesToday, $procedures, $medications, $penunjang, &$made) {
            $docIndex = 0;
            foreach ($schedulesToday as $sched) {
                $docIndex++;
                $employee = $sched->employee;
                if (! $employee) {
                    continue;
                }

                $suffix  = str_pad((string) $docIndex, 2, '0', STR_PAD_LEFT) . '90';
                $empHash = str_pad((string) crc32($employee->id), 8, '0', STR_PAD_LEFT);
                $nik     = substr('3288' . $suffix . $empHash, 0, 16);

                $patient = Patient::firstOrCreate(
                    ['nik' => $nik],
                    [
                        'no_rm'         => 'RT' . $suffix . substr($empHash, 0, 4),
                        'name'          => 'Andi Saputra (Resume Test)',
                        'gender'        => 'L',
                        'date_of_birth' => '1962-05-14',
                        'phone'         => '0813-' . $suffix . '-0001',
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
            "ResumeTestSeeder selesai - {$made} pasien '(Resume Test)' SIAP difinalisasi di antrean DOKTER hari ini."
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
                'td_sistol'       => 125,
                'td_diastol'      => 82,
                'nadi'            => 76,
                'suhu'            => 36.5,
                'respirasi'       => 18,
                'spo2'            => 99,
                'kgd'             => 108,
                'has_allergy'     => false,
                'allergy_detail'  => null,
                'chief_complaint' => 'Penglihatan mata kanan buram perlahan sejak 2 bulan.',
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
                    'visus_awal_od'    => '6/24',
                    'visus_awal_os'    => '6/9',
                    'pinhole_od'       => '6/12',
                    'pinhole_os'       => '6/6',
                    'visus_akhir_od'   => '6/12',
                    'visus_akhir_os'   => '6/6',
                    'iop_od'           => 15,
                    'iop_os'           => 14,
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
                'anamnese'       => 'Penglihatan mata kanan buram perlahan sejak 2 bulan, silau saat siang.',
                'slitlamp_notes' => 'Konjungtiva tenang, kornea jernih. Lensa OD keruh.',
                // Segmen: HANYA enum valid 'Normal'/'Tidak Normal'/'Tidak Dapat Dinilai'
                // (divalidasi DokterController::segmenRules in:). Lensa OD katarak -> Tidak Normal.
                'sa_kornea_od' => 'Normal',       'sa_kornea_os' => 'Normal',
                'sa_coa_od'    => 'Normal',       'sa_coa_os'    => 'Normal',
                'sa_iris_od'   => 'Normal',       'sa_iris_os'   => 'Normal',
                'sa_pupil_od'  => 'Normal',       'sa_pupil_os'  => 'Normal',
                'sa_lensa_od'  => 'Tidak Normal', 'sa_lensa_os'  => 'Normal',
                'sp_papil_od'    => 'Normal',     'sp_papil_os'    => 'Normal',
                'sp_macula_od'   => 'Normal',     'sp_macula_os'   => 'Normal',
                'sp_retina_od'   => 'Normal',     'sp_retina_os'   => 'Normal',
                'sp_vitreous_od' => 'Normal',     'sp_vitreous_os' => 'Normal',
                'soap_subjective' => 'Penglihatan mata kanan makin buram sejak 2 bulan, silau.',
                'soap_objective'  => 'VOD 6/24 VOS 6/9; TIO 15/14 mmHg; Lensa OD keruh.',
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

        // Tab Penunjang: COMPLETED + hasil biometri (muncul di modal resume).
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
                        'AL (Axial Length)' => 'OD 23.40 mm, OS 23.55 mm',
                        'K1 / K2'           => 'OD 43.50 / 44.00, OS 43.25 / 43.75',
                        'Target IOL'        => 'OD +21.0 D (SRK/T)',
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
