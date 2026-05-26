<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            EmployeeSeeder::class,
            UserSeeder::class,
            ClinicProfileSeeder::class,
            InsurerSystemSeeder::class,
            IntegrationConfigSeeder::class,
            ICD10Seeder::class,
            ICD9Seeder::class,
            DocumentTypeSeeder::class,
            StationMappingSeeder::class,
            DoctorScheduleSeeder::class,
            TvDisplaySettingSeeder::class,
            // PatientVisitSeeder::class, // demo only — run manually: php artisan db:seed --class=PatientVisitSeeder
        ]);
    }
}
