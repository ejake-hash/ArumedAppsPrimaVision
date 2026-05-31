<?php

namespace Database\Seeders;

use App\Models\DoctorExamination;
use App\Models\Employee;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\SurgeryPackage;
use App\Models\SurgerySchedule;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * BedahQueueDemoSeeder — data demo untuk modul Bedah:
 *   A. ANTREAN BEDAH HARI INI (BedahView /bedah/antrian):
 *      3 pasien di station BEDAH hari ini dgn SurgerySchedule (SCHEDULED hari ini),
 *      status antrean bervariasi (MENUNGGU/BERLANGSUNG), penjamin UMUM/BPJS.
 *   B. PASIEN TERJADWAL MENDATANG (BedahTerjadwalView /bedah/jadwal?upcoming=1):
 *      3 pasien dgn SurgerySchedule SCHEDULED di tanggal mendatang (besok, +3, +7 hari),
 *      visit PREOP_BEDAH — TANPA antrean hari ini (memang belum masuk OK).
 *
 * Catatan teknis:
 *   - getPatientQueue(): Queue station=BEDAH + whereDate(created_at, today) + whereHas(visit).
 *   - getScheduledSurgeries(upcoming): SurgerySchedule status=SCHEDULED + scheduled_date > today;
 *     patient di-resolve via SurgerySchedule->visit (hasOne Visit, FK surgery_schedule_id).
 *
 * IDEMPOTEN: pasien via NIK; schedule via (package, date, time); visit via (schedule, patient);
 * queue via (visit, station).
 *
 * Jalankan: php artisan db:seed --class=BedahQueueDemoSeeder
 * (Butuh minimal 1 SurgeryPackage — disediakan BedahDemoSeeder/seeder paket bedah.)
 */
class BedahQueueDemoSeeder extends Seeder
{
    public function run(): void
    {
        $package = SurgeryPackage::query()->first();
        if (! $package) {
            $this->command?->warn('BedahQueueDemoSeeder: tidak ada SurgeryPackage. Jalankan BedahDemoSeeder dulu. Lewati.');
            return;
        }

        $surgeon = Employee::query()->value('id');
        $umum    = Insurer::where('type', 'UMUM')->value('id');
        $bpjs    = Insurer::where('type', 'BPJS')->value('id');

        // ── A. Antrean BEDAH hari ini ──────────────────────────────────────────
        $antrean = [
            [
                'nik' => '3275099001000001', 'rm' => 'RM-OK-001', 'name' => 'Sugianto Wijaya',
                'gender' => 'L', 'dob' => '1959-03-14', 'guarantor' => 'UMUM', 'insurer' => $umum,
                'bpjs' => null, 'time' => '08:00:00', 'room' => 'OK 1', 'queue_status' => 'WAITING',
                'diag' => 'H25.1', 'anamnese' => 'Katarak senilis matur OD. Rencana Phaco + IOL.',
            ],
            [
                'nik' => '3275099001000002', 'rm' => 'RM-OK-002', 'name' => 'Kartika Sari',
                'gender' => 'P', 'dob' => '1963-07-28', 'guarantor' => 'BPJS', 'insurer' => $bpjs,
                'bpjs' => '0002345678901', 'time' => '09:30:00', 'room' => 'OK 1', 'queue_status' => 'IN_PROGRESS',
                'diag' => 'H25.1', 'anamnese' => 'Katarak senilis OS. Rencana Phaco + IOL, sedang berlangsung.',
            ],
            [
                'nik' => '3275099001000003', 'rm' => 'RM-OK-003', 'name' => 'Hendra Gunawan',
                'gender' => 'L', 'dob' => '1971-11-05', 'guarantor' => 'UMUM', 'insurer' => $umum,
                'bpjs' => null, 'time' => '11:00:00', 'room' => 'OK 2', 'queue_status' => 'WAITING',
                'diag' => 'H40.1', 'anamnese' => 'Glaukoma sudut terbuka OD, rencana trabekulektomi.',
            ],
        ];

        // ── B. Pasien terjadwal mendatang ──────────────────────────────────────
        $terjadwal = [
            [
                'nik' => '3275099001000011', 'rm' => 'RM-OK-011', 'name' => 'Wati Suryani',
                'gender' => 'P', 'dob' => '1968-02-20', 'guarantor' => 'UMUM', 'insurer' => $umum,
                'bpjs' => null, 'days' => 1, 'time' => '08:30:00', 'room' => 'OK 1',
                'diag' => 'H25.1', 'anamnese' => 'Katarak OD, dijadwalkan Phaco + IOL.',
            ],
            [
                'nik' => '3275099001000012', 'rm' => 'RM-OK-012', 'name' => 'Agus Salim',
                'gender' => 'L', 'dob' => '1955-09-09', 'guarantor' => 'BPJS', 'insurer' => $bpjs,
                'bpjs' => '0003456789012', 'days' => 3, 'time' => '10:00:00', 'room' => 'OK 1',
                'diag' => 'H25.9', 'anamnese' => 'Katarak senilis OS, terjadwal Phaco + IOL.',
            ],
            [
                'nik' => '3275099001000013', 'rm' => 'RM-OK-013', 'name' => 'Lina Marlina',
                'gender' => 'P', 'dob' => '1980-12-01', 'guarantor' => 'UMUM', 'insurer' => $umum,
                'bpjs' => null, 'days' => 7, 'time' => '09:00:00', 'room' => 'OK 2',
                'diag' => 'H33.0', 'anamnese' => 'Ablasio retina OD, terjadwal vitrektomi.',
            ],
        ];

        DB::transaction(function () use ($antrean, $terjadwal, $package, $surgeon) {
            foreach ($antrean as $p) {
                $visit = $this->makeScheduledVisit($p, today()->toDateString(), $package->id, $surgeon, 'BEDAH');
                $this->enqueueBedah($visit, $p['queue_status']);
            }
            foreach ($terjadwal as $p) {
                $date = today()->copy()->addDays($p['days'])->toDateString();
                // current_station tetap BEDAH (sudah dijadwalkan) tapi TIDAK di-enqueue hari ini.
                $this->makeScheduledVisit($p, $date, $package->id, $surgeon, 'BEDAH');
            }
        });

        $this->command?->info('BedahQueueDemoSeeder: ' . count($antrean) . ' pasien antrean BEDAH hari ini + ' . count($terjadwal) . ' pasien terjadwal mendatang.');
    }

    /** Buat Patient + SurgerySchedule + Visit (PREOP_BEDAH) + DoctorExamination final. */
    private function makeScheduledVisit(array $p, string $date, string $packageId, ?string $surgeon, string $station): Visit
    {
        $patient = Patient::firstOrCreate(
            ['nik' => $p['nik']],
            [
                'no_rm'         => $p['rm'],
                'identity_type' => 'KTP',
                'name'          => $p['name'],
                'gender'        => $p['gender'],
                'date_of_birth' => $p['dob'],
                'bpjs_number'   => $p['bpjs'],
                'address'       => 'Medan',
                'is_active'     => true,
            ],
        );

        $schedule = SurgerySchedule::firstOrCreate(
            [
                'surgery_package_id' => $packageId,
                'scheduled_date'     => $date,
                'scheduled_time'     => $p['time'],
                'operation_room'     => $p['room'],
            ],
            [
                'lead_surgeon_id' => $surgeon,
                'status'          => 'SCHEDULED',
                'notes'           => 'Demo BedahQueueDemoSeeder.',
            ],
        );

        $visit = Visit::firstOrCreate(
            ['surgery_schedule_id' => $schedule->id, 'patient_id' => $patient->id],
            [
                'insurer_id'      => $p['insurer'],
                'visit_date'      => $date,
                'classification'  => 'Pre-Op',
                'visit_type'      => 'PREOP_BEDAH',
                'current_station' => $station,
                'guarantor_type'  => $p['guarantor'],
            ],
        );

        DoctorExamination::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'doctor_id'           => $surgeon,
                'anamnese'            => $p['anamnese'],
                'diagnosis_utama'     => $p['diag'],
                'planning'            => 'BEDAH',
                'surgery_package_id'  => $packageId,
                'surgery_schedule_id' => $schedule->id,
                'is_finalized'        => true,
                'finalized_at'        => now(),
            ],
        );

        return $visit;
    }

    /** Enqueue ke antrean BEDAH hari ini (idempoten via visit+station). */
    private function enqueueBedah(Visit $visit, string $status): void
    {
        $existing = Queue::where('visit_id', $visit->id)->where('station', 'BEDAH')->first();
        if ($existing) {
            if ($existing->status !== $status) {
                $existing->update($this->statusTimestamps($status));
            }
            return;
        }

        $seq = (int) (Queue::where('station', 'BEDAH')->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;
        Queue::create(array_merge([
            'visit_id'       => $visit->id,
            'station'        => 'BEDAH',
            'queue_prefix'   => 'B',
            'queue_sequence' => $seq,
            'queue_number'   => 'B-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
        ], $this->statusTimestamps($status)));
    }

    private function statusTimestamps(string $status): array
    {
        return [
            'status'       => $status,
            'called_at'    => in_array($status, ['CALLED', 'IN_PROGRESS', 'COMPLETED'], true) ? now()->subMinutes(20) : null,
            'started_at'   => in_array($status, ['IN_PROGRESS', 'COMPLETED'], true) ? now()->subMinutes(15) : null,
            'completed_at' => $status === 'COMPLETED' ? now()->subMinutes(2) : null,
        ];
    }
}
