<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\Queue;
use App\Models\User;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PatientVisitSeeder extends Seeder
{
    private array $patients = [
        ['name' => 'Siti Rahayu',       'nik' => '1271065208810001', 'gender' => 'P', 'dob' => '1981-08-12', 'phone' => '0812-1111-2222', 'province' => 'Sumatera Utara',   'bpjs' => '0001234567890', 'guarantor' => 'BPJS',  'station' => 'DOKTER',       'class' => 'Baru',    'sep' => null],
        ['name' => 'Budi Santoso',       'nik' => '1271065208620002', 'gender' => 'L', 'dob' => '1962-05-20', 'phone' => '0813-2222-3333', 'province' => 'Sumatera Utara',   'bpjs' => null,           'guarantor' => 'UMUM',  'station' => 'FARMASI',      'class' => 'Kontrol', 'sep' => null],
        ['name' => 'Dewi Kusuma Sari',   'nik' => '1271065208880003', 'gender' => 'P', 'dob' => '1988-03-07', 'phone' => '0821-3333-4444', 'province' => 'Jawa Barat',       'bpjs' => '0002345678901', 'guarantor' => 'BPJS',  'station' => 'TRIASE',       'class' => 'Baru',    'sep' => null],
        ['name' => 'Ahmad Fauzi',        'nik' => '1271065208710004', 'gender' => 'L', 'dob' => '1971-11-25', 'phone' => '0852-4444-5555', 'province' => 'DKI Jakarta',      'bpjs' => null,           'guarantor' => 'UMUM',  'station' => 'KASIR',        'class' => 'Post-Op', 'sep' => null],
        ['name' => 'Rina Wulandari',     'nik' => '1271065208970005', 'gender' => 'P', 'dob' => '1997-07-14', 'phone' => '0817-5555-6666', 'province' => 'Sumatera Utara',   'bpjs' => null,           'guarantor' => 'UMUM',  'station' => 'ADMISI',       'class' => 'Baru',    'sep' => null],
        ['name' => 'Hendra Pratama',     'nik' => '1271065208550006', 'gender' => 'L', 'dob' => '1955-09-30', 'phone' => '0819-6666-7777', 'province' => 'Sumatera Utara',   'bpjs' => '0003456789012', 'guarantor' => 'BPJS',  'station' => 'REFRAKSIONIS', 'class' => 'Kontrol', 'sep' => null],
        ['name' => 'Yuni Astuti',        'nik' => '1271065208740007', 'gender' => 'P', 'dob' => '1974-01-18', 'phone' => '0811-7777-8888', 'province' => 'Jawa Tengah',      'bpjs' => null,           'guarantor' => 'UMUM',  'station' => 'DOKTER',       'class' => 'Pre-Op',  'sep' => null],
        ['name' => 'Mochammad Iqbal',    'nik' => '1271065208930008', 'gender' => 'L', 'dob' => '1993-06-22', 'phone' => '0822-8888-9999', 'province' => 'Sumatera Utara',   'bpjs' => '0004567890123', 'guarantor' => 'BPJS',  'station' => 'ADMISI',       'class' => 'Baru',    'sep' => null],
        ['name' => 'Lestari Ningrum',    'nik' => '1271065208680009', 'gender' => 'P', 'dob' => '1968-04-05', 'phone' => '0815-9999-0001', 'province' => 'DKI Jakarta',      'bpjs' => null,           'guarantor' => 'UMUM',  'station' => 'SELESAI',      'class' => 'Kontrol', 'sep' => null],
        ['name' => 'Teguh Wibowo',       'nik' => '1271065208590010', 'gender' => 'L', 'dob' => '1959-12-03', 'phone' => '0816-0001-1112', 'province' => 'Sumatera Utara',   'bpjs' => '0005678901234', 'guarantor' => 'BPJS',  'station' => 'PENUNJANG',    'class' => 'Post-Op', 'sep' => null],
    ];

    public function run(): void
    {
        $adminEmployee = User::whereHas('role', fn ($q) => $q->where('name', 'admisi'))
            ->first()
            ?->employee_id;

        $clinicCode = \App\Models\ClinicProfile::value('clinic_code') ?? 'KMA';
        $yearMonth  = now()->format('Ym');
        $baseTime   = Carbon::today()->setHour(7)->setMinute(30);

        foreach ($this->patients as $i => $data) {
            $patient = Patient::firstOrCreate(
                ['nik' => $data['nik']],
                [
                    'no_rm'         => $yearMonth . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                    'name'          => $data['name'],
                    'gender'        => $data['gender'],
                    'date_of_birth' => $data['dob'],
                    'phone'         => $data['phone'],
                    'province'      => $data['province'],
                    'bpjs_number'   => $data['bpjs'],
                    'is_active'     => true,
                ]
            );

            $visitTime = $baseTime->copy()->addMinutes($i * 18);

            $visit = Visit::create([
                'patient_id'            => $patient->id,
                'registered_by_id'      => $adminEmployee,
                'visit_date'            => today(),
                'classification'        => $data['class'],
                'current_station'       => $data['station'],
                'guarantor_type'        => $data['guarantor'],
                'satusehat_sync_status' => 'PENDING',
                'created_at'            => $visitTime,
                'updated_at'            => $visitTime,
            ]);

            $seq = $i + 1;
            $queueStatus = $data['station'] === 'ADMISI' ? 'WAITING' : 'COMPLETED';

            Queue::create([
                'visit_id'       => $visit->id,
                'station'        => 'ADMISI',
                'queue_prefix'   => 'A',
                'queue_sequence' => $seq,
                'queue_number'   => 'A-' . str_pad($seq, 3, '0', STR_PAD_LEFT),
                'status'         => $queueStatus,
                'completed_at'   => $queueStatus === 'COMPLETED' ? $visitTime->addMinutes(10) : null,
                'created_at'     => $visitTime,
                'updated_at'     => $visitTime,
            ]);
        }
    }
}
