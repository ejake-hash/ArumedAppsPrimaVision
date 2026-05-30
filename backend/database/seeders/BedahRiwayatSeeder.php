<?php

namespace Database\Seeders;

use App\Models\DoctorExamination;
use App\Models\Employee;
use App\Models\IolItem;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\SurgeryIolUsage;
use App\Models\SurgeryPackage;
use App\Models\SurgeryRecord;
use App\Models\SurgerySchedule;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * BedahRiwayatSeeder — operasi yang SUDAH/SEDANG berjalan, lengkap dengan
 * surgery_records (laporan operasi) + surgery_iol_usage. Mengisi gap yang
 * tidak disentuh seeder bedah lain (semua hanya membuat jadwal SCHEDULED):
 *
 *   • 2 operasi status DONE (kemarin & 3 hari lalu) — laporan lengkap:
 *       time_in/time_out, operation_notes, instruksi pasca-op, followup_date,
 *       IOL terpakai. Satu DENGAN komplikasi, satu bersih.
 *   • 1 operasi status IN_PROGRESS (hari ini) — time_in terisi, time_out null,
 *       antrean BEDAH IN_PROGRESS → uji tampilan "sedang dioperasi".
 *
 * Membangun rantai yang dibaca BedahView (tab Laporan/Pasca via /bedah/record)
 * & panel Jadwal BedahTerjadwalView (status DONE/IN_PROGRESS, weekpicker):
 *   patient → visit (PREOP_BEDAH, surgery_schedule_id)
 *           → doctor_examination (planning=BEDAH)
 *           → surgery_schedule (DONE/IN_PROGRESS)
 *           → surgery_record (time_in/out, notes) → surgery_iol_usage
 *
 * Memakai paket bedah & IOL yang sudah ada (jalankan BedahDemoSeeder dulu agar
 * master terisi). IDEMPOTEN: aman dijalankan berulang.
 *
 * Jalankan: php artisan db:seed --class=BedahRiwayatSeeder
 */
class BedahRiwayatSeeder extends Seeder
{
    public function run(): void
    {
        $package = SurgeryPackage::query()->where('is_active', true)->first();
        if (! $package) {
            $this->command?->warn('BedahRiwayatSeeder dilewati — belum ada paket bedah. Jalankan BedahDemoSeeder dulu.');
            return;
        }

        $surgeon = Employee::query()
            ->where('profession', 'like', '%dokter%')
            ->orWhere('profession', 'like', '%Sp.M%')
            ->first()
            ?? Employee::query()->first();

        $iol = IolItem::query()->where('is_active', true)->first();

        DB::transaction(function () use ($package, $surgeon, $iol) {
            // 1) Operasi BERSIH (DONE, 3 hari lalu).
            $this->seedCompletedSurgery(
                package: $package,
                surgeon: $surgeon,
                iol: $iol,
                nik: '1271065208600101',
                noRmSuffix: '1101',
                name: 'Hartono Wibowo (Demo Riwayat Bedah)',
                gender: 'L',
                dob: '1952-04-10',
                phone: '0812-1000-0101',
                daysAgo: 3,
                time: '09:00:00',
                room: 'OK 1',
                eyeSide: 'OD',
                operationNotes: 'Fakoemulsifikasi OD lancar. Kapsulotomi posterior utuh, IOL terpasang in-the-bag, sentris. Insisi self-sealing.',
                hasComplication: false,
                complicationDetail: null,
                postOpInstructions: 'Tetes mata antibiotik 4x/hari 7 hari, steroid tapering 2 minggu. Hindari mengucek mata, jaga kebersihan, pakai pelindung saat tidur.',
                followupDaysAfter: 1,
            );

            // 2) Operasi DENGAN komplikasi (DONE, kemarin).
            $this->seedCompletedSurgery(
                package: $package,
                surgeon: $surgeon,
                iol: $iol,
                nik: '1271065208600102',
                noRmSuffix: '1102',
                name: 'Sumarni (Demo Riwayat Komplikasi)',
                gender: 'P',
                dob: '1949-11-22',
                phone: '0812-1000-0102',
                daysAgo: 1,
                time: '11:30:00',
                room: 'OK 2',
                eyeSide: 'OS',
                operationNotes: 'Fakoemulsifikasi OS. Pupil miosis intraoperatif, dipasang iris hook. Robekan kapsul posterior kecil saat aspirasi korteks, anterior vitrektomi dilakukan, IOL ditempatkan di sulkus.',
                hasComplication: true,
                complicationDetail: 'Robekan kapsul posterior (PCR) ± 1 mm dengan prolaps vitreus minimal. Ditangani anterior vitrektomi; IOL sulkus.',
                postOpInstructions: 'Pantau TIO 24 jam. Antibiotik + steroid intensif. Kontrol ketat funduskopi untuk evaluasi retina. Edukasi tanda endoftalmitis.',
                followupDaysAfter: 1,
            );

            // 3) Operasi SEDANG berjalan (IN_PROGRESS, hari ini) — time_in saja.
            $this->seedInProgressSurgery(
                package: $package,
                surgeon: $surgeon,
                nik: '1271065208600103',
                noRmSuffix: '1103',
                name: 'Kasimin (Demo Sedang Operasi)',
                gender: 'L',
                dob: '1956-02-18',
                phone: '0812-1000-0103',
                time: '08:00:00',
                room: 'OK 1',
            );
        });

        $this->command?->info('BedahRiwayatSeeder selesai — 2 operasi DONE (1 komplikasi) + 1 IN_PROGRESS, lengkap dengan surgery_record + IOL usage.');
    }

    /** Operasi sudah selesai: schedule DONE + surgery_record + IOL usage + antrean FARMASI hilir. */
    private function seedCompletedSurgery(
        SurgeryPackage $package,
        ?Employee $surgeon,
        ?IolItem $iol,
        string $nik,
        string $noRmSuffix,
        string $name,
        string $gender,
        string $dob,
        string $phone,
        int $daysAgo,
        string $time,
        string $room,
        string $eyeSide,
        string $operationNotes,
        bool $hasComplication,
        ?string $complicationDetail,
        string $postOpInstructions,
        int $followupDaysAfter,
    ): void {
        $opDate = today()->subDays($daysAgo);

        [$patient, $schedule, $visit] = $this->seedChain(
            package: $package,
            surgeon: $surgeon,
            nik: $nik,
            noRmSuffix: $noRmSuffix,
            name: $name,
            gender: $gender,
            dob: $dob,
            phone: $phone,
            scheduledDate: $opDate->toDateString(),
            scheduledTime: $time,
            room: $room,
            status: 'DONE',
            currentStation: 'SELESAI',
            notes: 'Operasi selesai (demo riwayat BedahRiwayatSeeder).',
        );

        // Laporan operasi (idempoten via unique surgery_schedule_id).
        $timeIn  = $opDate->copy()->setTimeFromTimeString($time);
        $timeOut = $timeIn->copy()->addMinutes(45);

        $record = SurgeryRecord::firstOrCreate(
            ['surgery_schedule_id' => $schedule->id],
            [
                'visit_id'             => $visit->id,
                'time_in'              => $timeIn,
                'time_out'             => $timeOut,
                'operation_notes'      => $operationNotes,
                'has_complication'     => $hasComplication,
                'complication_detail'  => $hasComplication ? $complicationDetail : null,
                'post_op_instructions' => $postOpInstructions,
                'followup_date'        => $opDate->copy()->addDays($followupDaysAfter)->toDateString(),
            ]
        );

        // IOL terpakai (idempoten via record + eye_side).
        if ($iol && ! SurgeryIolUsage::where('surgery_record_id', $record->id)->where('eye_side', $eyeSide)->exists()) {
            SurgeryIolUsage::create([
                'surgery_record_id' => $record->id,
                'iol_item_id'       => $iol->id,
                'eye_side'          => $eyeSide,
                'brand'             => $iol->brand,
                'model'             => $iol->model,
                'power'             => $iol->power,
                'lot_number'        => 'LOT-' . $opDate->format('Ymd'),
                'serial_number'     => 'SN-' . strtoupper(substr(md5($record->id), 0, 8)),
            ]);
        }
    }

    /** Operasi sedang berjalan: schedule IN_PROGRESS + surgery_record (time_in saja) + antrean BEDAH IN_PROGRESS. */
    private function seedInProgressSurgery(
        SurgeryPackage $package,
        ?Employee $surgeon,
        string $nik,
        string $noRmSuffix,
        string $name,
        string $gender,
        string $dob,
        string $phone,
        string $time,
        string $room,
    ): void {
        [$patient, $schedule, $visit] = $this->seedChain(
            package: $package,
            surgeon: $surgeon,
            nik: $nik,
            noRmSuffix: $noRmSuffix,
            name: $name,
            gender: $gender,
            dob: $dob,
            phone: $phone,
            scheduledDate: today()->toDateString(),
            scheduledTime: $time,
            room: $room,
            status: 'IN_PROGRESS',
            currentStation: 'BEDAH',
            notes: 'Operasi sedang berjalan (demo BedahRiwayatSeeder).',
        );

        SurgeryRecord::firstOrCreate(
            ['surgery_schedule_id' => $schedule->id],
            [
                'visit_id'         => $visit->id,
                'time_in'          => now(),
                'time_out'         => null,
                'operation_notes'  => null,
                'has_complication' => false,
            ]
        );

        // Antrean BEDAH dalam status IN_PROGRESS (idempoten via visit+station).
        if (! Queue::where('visit_id', $visit->id)->where('station', 'BEDAH')->exists()) {
            $seq = (int) (Queue::where('station', 'BEDAH')->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;
            Queue::create([
                'visit_id'       => $visit->id,
                'station'        => 'BEDAH',
                'queue_prefix'   => 'B',
                'queue_sequence' => $seq,
                'queue_number'   => 'B-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
                'status'         => 'IN_PROGRESS',
                'started_at'     => now(),
            ]);
        }
    }

    /**
     * Rantai dasar patient → schedule → visit → doctor_examination.
     *
     * @return array{0: Patient, 1: SurgerySchedule, 2: Visit}
     */
    private function seedChain(
        SurgeryPackage $package,
        ?Employee $surgeon,
        string $nik,
        string $noRmSuffix,
        string $name,
        string $gender,
        string $dob,
        string $phone,
        string $scheduledDate,
        string $scheduledTime,
        string $room,
        string $status,
        string $currentStation,
        string $notes,
    ): array {
        $patient = Patient::firstOrCreate(
            ['nik' => $nik],
            [
                'no_rm'         => now()->format('Ym') . $noRmSuffix,
                'name'          => $name,
                'gender'        => $gender,
                'date_of_birth' => $dob,
                'phone'         => $phone,
                'province'      => 'Sumatera Utara',
                'is_active'     => true,
            ]
        );

        $schedule = SurgerySchedule::firstOrCreate(
            [
                'surgery_package_id' => $package->id,
                'scheduled_date'     => $scheduledDate,
                'scheduled_time'     => $scheduledTime,
            ],
            [
                'lead_surgeon_id' => $surgeon?->id,
                'operation_room'  => $room,
                'status'          => $status,
                'notes'           => $notes,
            ]
        );
        // Pastikan status sesuai walau jadwal sudah ada dari run sebelumnya.
        if ($schedule->status !== $status) {
            $schedule->update(['status' => $status]);
        }

        $visit = Visit::firstOrCreate(
            ['surgery_schedule_id' => $schedule->id],
            [
                'patient_id'      => $patient->id,
                'visit_date'      => $scheduledDate,
                'classification'  => 'Pre-Op',
                'visit_type'      => 'PREOP_BEDAH',
                'current_station' => $currentStation,
                'guarantor_type'  => 'UMUM',
            ]
        );

        DoctorExamination::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'doctor_id'           => $surgeon?->id,
                'anamnese'            => 'Katarak matur, rencana fakoemulsifikasi + IOL.',
                'diagnosis_utama'     => 'H25.9',
                'planning'            => 'BEDAH',
                'surgery_package_id'  => $package->id,
                'surgery_schedule_id' => $schedule->id,
                'is_finalized'        => true,
                'finalized_at'        => now(),
            ]
        );

        return [$patient, $schedule, $visit];
    }
}
