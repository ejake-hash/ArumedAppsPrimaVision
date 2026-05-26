<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'superadmin',   'display_name' => 'Superadmin'],
            ['name' => 'dokter',       'display_name' => 'Dokter'],
            ['name' => 'perawat',      'display_name' => 'Perawat'],
            ['name' => 'refraksionis', 'display_name' => 'Refraksionis'],
            ['name' => 'penunjang',    'display_name' => 'Penunjang'],
            ['name' => 'farmasi',      'display_name' => 'Farmasi'],
            ['name' => 'kasir',        'display_name' => 'Kasir'],
            ['name' => 'verifikator',  'display_name' => 'Verifikator'],
            ['name' => 'admisi',       'display_name' => 'Admisi'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name']],
                [
                    'display_name' => $role['display_name'],
                    'guard_name'   => 'api',
                    'is_active'    => true,
                ]
            );
        }
    }
}
