<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * 1 akun per alur stasiun, plus Superadmin (sesuai permintaan).
     * Superadmin pakai password real: "Superadmin@123".
     * Sisa user dev pakai password sederhana "888888".
     */
    public function run(): void
    {
        $roles    = Role::all()->keyBy('name');
        $dokter1  = Employee::where('nip', 'EMP-DOK-001')->first();
        $dokter2  = Employee::where('nip', 'EMP-DOK-002')->first();
        $dokter3  = Employee::where('nip', 'EMP-DOK-003')->first();
        $perawat  = Employee::where('nip', 'EMP-PER-001')->first();
        $refraksi = Employee::where('nip', 'EMP-REF-001')->first();
        $admin    = Employee::where('nip', 'EMP-ADM-001')->first();

        $users = [
            [
                'username'    => 'superadmin',
                'name'        => 'Superadmin',
                'email'       => 'superadmin@arumed.id',
                'role_name'   => 'superadmin',
                'employee_id' => null,
                'password'    => 'Superadmin@123',
            ],
            [
                'username'    => 'dokter',
                'name'        => 'dr. Ahmad Fauzi Sp.M',
                'email'       => 'dokter@arumed.id',
                'role_name'   => 'dokter',
                'employee_id' => $dokter1?->id,
                'password'    => '888888',
            ],
            [
                'username'    => 'dokter2',
                'name'        => 'dr. Bunga Lestari Sp.M',
                'email'       => 'dokter2@arumed.id',
                'role_name'   => 'dokter',
                'employee_id' => $dokter2?->id,
                'password'    => '888888',
            ],
            [
                'username'    => 'dokter3',
                'name'        => 'dr. Candra Wijaya Sp.M(K)',
                'email'       => 'dokter3@arumed.id',
                'role_name'   => 'dokter',
                'employee_id' => $dokter3?->id,
                'password'    => '888888',
            ],
            [
                'username'    => 'perawat',
                'name'        => 'Siti Rahayu Amd.Kep',
                'email'       => 'perawat@arumed.id',
                'role_name'   => 'perawat',
                'employee_id' => $perawat?->id,
                'password'    => '888888',
            ],
            [
                'username'    => 'refraksionis',
                'name'        => 'Budi Santoso Amd.RO',
                'email'       => 'refraksionis@arumed.id',
                'role_name'   => 'refraksionis',
                'employee_id' => $refraksi?->id,
                'password'    => '888888',
            ],
            [
                'username'    => 'penunjang',
                'name'        => 'Penunjang Diagnostik',
                'email'       => 'penunjang@arumed.id',
                'role_name'   => 'penunjang',
                'employee_id' => null,
                'password'    => '888888',
            ],
            [
                'username'    => 'farmasi',
                'name'        => 'Staf Farmasi',
                'email'       => 'farmasi@arumed.id',
                'role_name'   => 'farmasi',
                'employee_id' => null,
                'password'    => '888888',
            ],
            [
                'username'    => 'kasir',
                'name'        => 'Staf Kasir',
                'email'       => 'kasir@arumed.id',
                'role_name'   => 'kasir',
                'employee_id' => null,
                'password'    => '888888',
            ],
            [
                'username'    => 'verifikator',
                'name'        => 'Verifikator Klaim',
                'email'       => 'verifikator@arumed.id',
                'role_name'   => 'verifikator',
                'employee_id' => null,
                'password'    => '888888',
            ],
            [
                'username'    => 'admisi',
                'name'        => 'Rina Wulandari',
                'email'       => 'admisi@arumed.id',
                'role_name'   => 'admisi',
                'employee_id' => $admin?->id,
                'password'    => '888888',
            ],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'username'    => $data['username'],
                    'name'        => $data['name'],
                    'role_id'     => $roles[$data['role_name']]?->id,
                    'employee_id' => $data['employee_id'],
                    'password'    => $data['password'],   // auto-hashed via cast
                    'is_active'   => true,
                ]
            );
        }
    }
}
