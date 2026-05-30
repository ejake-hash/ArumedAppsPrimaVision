<?php

namespace Database\Seeders;

use App\Models\DoctorSchedule;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DoctorScheduleSeeder extends Seeder
{
    public function run(): void
    {
        // week_start = Senin minggu berjalan (WIB). Kolom NOT NULL sejak Jadwal
        // Dokter v2 — wajib diisi eksplisit, tidak punya default DB.
        $weekStart = Carbon::now('Asia/Jakarta')->startOfWeek(Carbon::MONDAY)->toDateString();

        // day_of_week: 1=Senin … 7=Minggu (ISO-8601)
        // `room` = nomor ruangan (derive queue_prefix D1/D2/D3 di model)
        // `poliklinik` = nama poli (sub-spesialisasi)
        $schedules = [
            'EMP-DOK-001' => [                         // dr. Ahmad Fauzi Sp.M
                ['day' => 1, 'start' => '08:00', 'end' => '12:00', 'room' => '1', 'poliklinik' => 'Poli Mata Umum'],
                ['day' => 2, 'start' => '08:00', 'end' => '12:00', 'room' => '1', 'poliklinik' => 'Poli Mata Umum'],
                ['day' => 3, 'start' => '08:00', 'end' => '12:00', 'room' => '1', 'poliklinik' => 'Poli Mata Umum'],
                ['day' => 4, 'start' => '13:00', 'end' => '17:00', 'room' => '4', 'poliklinik' => 'Poli Katarak'],
                ['day' => 5, 'start' => '08:00', 'end' => '12:00', 'room' => '1', 'poliklinik' => 'Poli Mata Umum'],
            ],
            'EMP-DOK-002' => [                         // dr. Bunga Lestari Sp.M
                ['day' => 1, 'start' => '13:00', 'end' => '17:00', 'room' => '2', 'poliklinik' => 'Poli Glaukoma'],
                ['day' => 2, 'start' => '13:00', 'end' => '17:00', 'room' => '2', 'poliklinik' => 'Poli Glaukoma'],
                ['day' => 4, 'start' => '08:00', 'end' => '12:00', 'room' => '2', 'poliklinik' => 'Poli Glaukoma'],
                ['day' => 5, 'start' => '13:00', 'end' => '17:00', 'room' => '2', 'poliklinik' => 'Poli Glaukoma'],
                ['day' => 6, 'start' => '08:00', 'end' => '12:00', 'room' => '2', 'poliklinik' => 'Poli Glaukoma'],
            ],
            'EMP-DOK-003' => [                         // dr. Candra Wijaya Sp.M(K)
                ['day' => 2, 'start' => '08:00', 'end' => '11:00', 'room' => '3', 'poliklinik' => 'Poli Retina'],
                ['day' => 3, 'start' => '13:00', 'end' => '17:00', 'room' => '3', 'poliklinik' => 'Poli Retina'],
                ['day' => 5, 'start' => '08:00', 'end' => '11:00', 'room' => '3', 'poliklinik' => 'Poli Retina'],
                ['day' => 6, 'start' => '13:00', 'end' => '17:00', 'room' => '4', 'poliklinik' => 'Poli Katarak'],
            ],
        ];

        foreach ($schedules as $nip => $rows) {
            $employee = Employee::where('nip', $nip)->first();

            if (! $employee) {
                continue;
            }

            foreach ($rows as $row) {
                DoctorSchedule::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'day_of_week' => $row['day'],
                        'week_start'  => $weekStart,
                    ],
                    [
                        'start_time'   => $row['start'],
                        'end_time'     => $row['end'],
                        'room'         => $row['room'],
                        'poliklinik'   => $row['poliklinik'],
                        'service_type' => 'BPJS',
                        'is_active'    => true,
                    ]
                );
            }
        }
    }
}
