<?php

namespace Database\Seeders;

use App\Models\DoctorExamination;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\SurgeryPackage;
use App\Models\SurgerySchedule;
use App\Models\Visit;
use Illuminate\Database\Seeder;

/**
 * Demo: 1 pasien terjadwal bedah MENDATANG (scheduled_date > today).
 *
 * Membangun rantai data nyata yang dibaca BedahTerjadwalView lewat /bedah/jadwal?upcoming=1:
 *   patient → visit (visit_type=PREOP_BEDAH, surgery_schedule_id)
 *           → doctor_examination (planning=BEDAH, diagnosis_utama)
 *           → surgery_schedule (status=SCHEDULED, tanggal H+3)
 *
 * Jalankan manual: php artisan db:seed --class=BedahTerjadwalSeeder
 */
class BedahTerjadwalSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Paket bedah — pakai yang ada, atau buat 1 paket Phaco minimal.
        $package = SurgeryPackage::query()->where('is_active', true)->first()
            ?? SurgeryPackage::create([
                'name'        => 'Fakoemulsifikasi (Phaco) + IOL Standar',
                'code'        => 'PKB-DEMO-001',
                'category'    => 'KATARAK',
                'description' => 'Paket demo bedah katarak (auto-seed).',
                'price'       => 0,
                'is_active'   => true,
            ]);

        // 2) Operator — ambil pegawai dokter mana pun (boleh null jika belum ada).
        $surgeon = Employee::query()
            ->where('profession', 'like', '%dokter%')
            ->orWhere('profession', 'like', '%Sp.M%')
            ->first()
            ?? Employee::query()->first();

        // 3) Pasien demo (idempotent via NIK).
        $patient = Patient::firstOrCreate(
            ['nik' => '1271065208600099'],
            [
                'no_rm'         => now()->format('Ym') . '9099',
                'name'          => 'Tuti Handayani',
                'gender'        => 'P',
                'date_of_birth' => '1960-08-12',
                'phone'         => '0812-9000-0099',
                'province'      => 'Sumatera Utara',
                'bpjs_number'   => '0009099000099',
                'is_active'     => true,
            ]
        );

        // 4) Jadwal operasi mendatang (H+3).
        $schedule = SurgerySchedule::firstOrCreate(
            [
                'surgery_package_id' => $package->id,
                'scheduled_date'     => today()->addDays(3)->toDateString(),
                'scheduled_time'     => '08:00:00',
            ],
            [
                'lead_surgeon_id' => $surgeon?->id,
                'operation_room'  => 'OK 1',
                'status'          => 'SCHEDULED',
                'notes'           => 'Jadwal demo (auto-seed BedahTerjadwalSeeder).',
            ]
        );

        // 5) Kunjungan PREOP_BEDAH yang menunjuk ke jadwal.
        $visit = Visit::firstOrCreate(
            ['surgery_schedule_id' => $schedule->id],
            [
                'patient_id'            => $patient->id,
                'visit_date'            => today(),
                'classification'        => 'Pre-Op',
                'visit_type'            => 'PREOP_BEDAH',
                'current_station'       => 'DOKTER',
                'guarantor_type'        => 'BPJS',
                'satusehat_sync_status' => 'PENDING',
            ]
        );

        // 6) Pemeriksaan dokter dengan diagnosa + planning BEDAH.
        DoctorExamination::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'doctor_id'          => $surgeon?->id,
                'anamnese'           => 'Penglihatan buram progresif OD ± 6 bulan.',
                'diagnosis_utama'    => 'H25.9',
                'planning'           => 'BEDAH',
                'surgery_package_id' => $package->id,
                'surgery_schedule_id'=> $schedule->id,
                'is_finalized'       => true,
                'finalized_at'       => now(),
            ]
        );

        $this->command?->info("Bedah terjadwal demo: {$patient->name} → {$schedule->scheduled_date} ({$package->name}).");
    }
}
