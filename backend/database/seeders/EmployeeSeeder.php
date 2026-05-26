<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $employees = [
            [
                'name'       => 'dr. Ahmad Fauzi Sp.M',
                'nip'        => 'EMP-DOK-001',
                'profession' => 'Dokter Spesialis Mata',
                'sip'        => 'SIP/001/KEMENKES/2024',
                'str'        => 'STR/001/KKI/2024',
                'phone'      => '081200000001',
                'email'      => 'dr.ahmad@arumed.id',
                'is_active'  => true,
            ],
            [
                'name'       => 'dr. Bunga Lestari Sp.M',
                'nip'        => 'EMP-DOK-002',
                'profession' => 'Dokter Spesialis Mata',
                'sip'        => 'SIP/005/KEMENKES/2024',
                'str'        => 'STR/005/KKI/2024',
                'phone'      => '081200000005',
                'email'      => 'dr.bunga@arumed.id',
                'is_active'  => true,
            ],
            [
                'name'       => 'dr. Candra Wijaya Sp.M(K)',
                'nip'        => 'EMP-DOK-003',
                'profession' => 'Dokter Spesialis Mata Konsultan',
                'sip'        => 'SIP/006/KEMENKES/2024',
                'str'        => 'STR/006/KKI/2024',
                'phone'      => '081200000006',
                'email'      => 'dr.candra@arumed.id',
                'is_active'  => true,
            ],
            [
                'name'       => 'Siti Rahayu Amd.Kep',
                'nip'        => 'EMP-PER-001',
                'profession' => 'Perawat',
                'sip'        => 'SIP/002/KEMENKES/2024',
                'str'        => 'STR/002/MTKI/2024',
                'phone'      => '081200000002',
                'email'      => 'perawat@arumed.id',
                'is_active'  => true,
            ],
            [
                'name'       => 'Budi Santoso Amd.RO',
                'nip'        => 'EMP-REF-001',
                'profession' => 'Refraksionis Optisien',
                'sip'        => 'SIP/003/KEMENKES/2024',
                'str'        => 'STR/003/MTKI/2024',
                'phone'      => '081200000003',
                'email'      => 'refraksionis@arumed.id',
                'is_active'  => true,
            ],
            [
                'name'       => 'Rina Wulandari',
                'nip'        => 'EMP-ADM-001',
                'profession' => 'Administrasi',
                'sip'        => null,
                'str'        => null,
                'phone'      => '081200000004',
                'email'      => 'admin@arumed.id',
                'is_active'  => true,
            ],
        ];

        foreach ($employees as $employee) {
            Employee::updateOrCreate(
                ['nip' => $employee['nip']],
                $employee
            );
        }
    }
}
