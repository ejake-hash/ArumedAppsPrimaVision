<?php

namespace Database\Seeders;

use App\Models\DoctorExamination;
use App\Models\Employee;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\SurgeryPackage;
use App\Models\SurgeryRecord;
use App\Models\SurgerySchedule;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * FormulirBedahDemoSeeder — SATU pasien demo bedah LENGKAP untuk menguji
 * Formulir Bedah (Fase 0-3): laporan operasi search-driven + form kondisional.
 *
 * Pasien: GLAUKOMA + anestesi TIVA, operasi SELESAI (ada SurgeryRecord dgn
 * operation_report + safety_checklist terisi). Dengan begitu di BedahView:
 *   - Jenis Laporan dikonfirmasi GLAUKOMA → RM 8.10 Trabekulektomi "Disarankan".
 *   - requires_anesthesia (TIVA) → RM 4.3 Consent + RM 4.4 Penilaian Anestesi "Disarankan".
 *   - WHO SSC (RM 4.9) ter-render dari safety_checklist (reuse, K6).
 *   - Site Marking (RM 1.9) Mata = OD (operative_eye).
 *   - Peri-operatif (RM 1.10), Laporan Pembedahan generik (RM 2.2) tetap muncul.
 *
 * IDEMPOTEN: patient via NIK, schedule via (package,date,time,room), visit via
 * (schedule,patient), record via visit, queue via (visit,station).
 *
 * Jalankan: php artisan db:seed --class=FormulirBedahDemoSeeder
 */
class FormulirBedahDemoSeeder extends Seeder
{
    public function run(): void
    {
        $package = SurgeryPackage::query()->first();
        if (! $package) {
            $this->command?->warn('FormulirBedahDemoSeeder: tidak ada SurgeryPackage. Jalankan BedahDemoSeeder dulu. Lewati.');
            return;
        }
        $surgeon     = Employee::query()->first();
        $surgeonName = $surgeon?->name ?? 'dr. Operator Demo, Sp.M';
        $umum        = Insurer::where('type', 'UMUM')->value('id');

        DB::transaction(function () use ($package, $surgeon, $surgeonName, $umum) {
            $patient = Patient::firstOrCreate(
                ['nik' => '3275099009000099'],
                [
                    'no_rm'         => 'RM-FB-DEMO',
                    'identity_type' => 'KTP',
                    'name'          => 'Demo Formulir Bedah',
                    'gender'        => 'L',
                    'date_of_birth' => '1962-05-09',
                    'address'       => 'Medan',
                    'is_active'     => true,
                ],
            );

            $date = today()->toDateString();
            $schedule = SurgerySchedule::firstOrCreate(
                [
                    'surgery_package_id' => $package->id,
                    'scheduled_date'     => $date,
                    'scheduled_time'     => '09:00:00',
                    'operation_room'     => 'OK 1',
                ],
                [
                    'lead_surgeon_id' => $surgeon?->id,
                    'status'          => 'SCHEDULED',
                    'notes'           => 'Demo FormulirBedahDemoSeeder (GLAUKOMA + TIVA).',
                ],
            );

            $visit = Visit::firstOrCreate(
                ['surgery_schedule_id' => $schedule->id, 'patient_id' => $patient->id],
                [
                    'insurer_id'      => $umum,
                    'visit_date'      => $date,
                    'classification'  => 'Pre-Op',
                    'visit_type'      => 'PREOP_BEDAH',
                    'current_station' => 'BEDAH',
                    'guarantor_type'  => 'UMUM',
                ],
            );

            DoctorExamination::firstOrCreate(
                ['visit_id' => $visit->id],
                [
                    'doctor_id'           => $surgeon?->id,
                    'anamnese'            => 'Glaukoma sudut terbuka primer OD, TIO tidak terkontrol dengan obat. Rencana trabekulektomi.',
                    'diagnosis_utama'     => 'H40.1',
                    'planning'            => 'BEDAH',
                    'surgery_package_id'  => $package->id,
                    'surgery_schedule_id' => $schedule->id,
                    'is_finalized'        => true,
                    'finalized_at'        => now(),
                ],
            );

            // SurgeryRecord LENGKAP — sumber proyeksi semua form (operation_report +
            // safety_checklist). report_types=GLAUKOMA & anesthesia_type=TIVA.
            SurgeryRecord::updateOrCreate(
                ['visit_id' => $visit->id],
                [
                    'surgery_schedule_id' => $schedule->id,
                    'time_in'             => now()->subMinutes(75),
                    'time_out'            => now()->subMinutes(10),
                    'has_complication'    => false,
                    'post_op_disposition' => 'PULANG',
                    'operation_notes'     => "[Teknik Operasi]\nTrabekulektomi standar dengan flap sklera, iridektomi perifer.\n\n[Temuan Intraoperatif]\nBleb terbentuk baik, TIO turun.",
                    'safety_checklist'    => [
                        'sign_in'  => ['identitas' => true, 'sisi_mata' => 'OD', 'consent' => true, 'anestesi_siap' => true, 'alergi_dikonfirmasi' => true],
                        'time_out' => ['tim_lengkap' => true, 'identitas_prosedur' => true, 'sisi_mata' => true, 'antibiotik' => true, 'iol_benar' => true],
                        'sign_out' => ['prosedur_dikonfirmasi' => true, 'hitung_kasa' => true, 'hitung_instrumen' => true, 'spesimen' => true, 'iol_dicatat' => false, 'rencana_pemulihan' => true],
                    ],
                    'operation_report'    => [
                        'report_types'      => ['GLAUKOMA'],
                        'operative_eye'     => 'OD',
                        'operator'          => $surgeonName,
                        'asisten'           => ['Ns. Rina', 'Ns. Doni'],
                        'anesthesiologist'  => 'dr. Anita Anestesi, Sp.An',
                        'anesthesia_type'   => 'TIVA',
                        'procedure_name'    => 'Trabekulektomi OD',
                        'diagnosis_pre'     => 'H40.1',
                        'diagnosis_post'    => 'H40.1 — Glaukoma sudut terbuka primer',
                        'technique'         => 'Trabekulektomi standar dengan flap sklera, iridektomi perifer.',
                        'findings'          => 'Bleb terbentuk baik, TIO terkontrol pasca-operasi.',
                        'complication'      => ['ada' => false, 'type' => null, 'management' => null],
                        'estimated_blood_loss' => 'Minimal',
                    ],
                ],
            );

            $existing = Queue::where('visit_id', $visit->id)->where('station', 'BEDAH')->first();
            if (! $existing) {
                $seq = (int) (Queue::where('station', 'BEDAH')->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;
                Queue::create([
                    'visit_id'       => $visit->id,
                    'station'        => 'BEDAH',
                    'queue_prefix'   => 'B',
                    'queue_sequence' => $seq,
                    'queue_number'   => 'B-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
                    'status'         => 'IN_PROGRESS',
                    'called_at'      => now()->subMinutes(80),
                    'started_at'     => now()->subMinutes(75),
                ]);
            }
        });

        $this->command?->info('FormulirBedahDemoSeeder: pasien "Demo Formulir Bedah" (NIK 3275099009000099) — GLAUKOMA + TIVA, operasi selesai, di antrean BEDAH hari ini.');
    }
}
