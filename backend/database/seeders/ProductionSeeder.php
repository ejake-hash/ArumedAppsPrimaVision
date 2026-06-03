<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * ProductionSeeder — seeding untuk GO-LIVE / produksi.
 *
 * Berbeda dari DatabaseSeeder (dev), seeder ini HANYA mengisi data
 * master/sistem + 1 akun superadmin. TIDAK ada akun pegawai/user demo,
 * TIDAK ada data pasien/visit/bedah/kasir dummy.
 *
 * Yang lain (pegawai, user stasiun, pasien, tarif, dst) diisi dengan
 * DATA ASLI — lewat UI (menu Data Pengguna, Master Data) atau lewat
 * command migrasi data lama: `php artisan migrasi:primavision` (+master).
 *
 * Diagnosa (ICD-10/ICD-9) DIPERTAHANKAN dari seeder bawaan: base +
 * set oftalmologi lengkap (H00-H59 / prosedur mata 08-16).
 *
 * Template Form Registry (FormTemplateSeeder) SENGAJA TIDAK di-seed:
 * klinik membuat template surat/form sendiri via UI.
 *
 * Jalankan:  php artisan db:seed --class=ProductionSeeder --force
 *
 * Idempotent: aman dijalankan ulang (semua seeder di bawah pakai
 * updateOrCreate / firstOrCreate).
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // --- RBAC inti (WAJIB) ---
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,

            // --- Master sistem (WAJIB) ---
            ClinicProfileSeeder::class,
            InsurerSystemSeeder::class,          // UMUM / BPJS / SOSIAL (immutable)
            IntegrationConfigSeeder::class,
            DocumentTypeSeeder::class,
            DocumentNumberConfigSeeder::class,   // format penomoran RME/INVOICE/SEP/dst
            StationMappingSeeder::class,
            TvDisplaySettingSeeder::class,
            // FormTemplateSeeder::class,        // SENGAJA TIDAK di-seed di produksi:
            //   klinik membuat template surat/form sendiri via menu Form Registry.
            //   File seeder tetap ada untuk dev/E2E (jalankan manual bila perlu:
            //   php artisan db:seed --class=FormTemplateSeeder).
            RefractionOptionSeeder::class,       // master opsi dropdown refraksi

            // --- Data diagnosa (DIPERTAHANKAN) ---
            ICD10Seeder::class,                  // base ICD-10 favorit
            ICD9Seeder::class,                   // base ICD-9 favorit
            Icd10OftalmologiSeeder::class,       // master ICD-10 mata lengkap (H00-H59)
            Icd9OftalmologiSeeder::class,        // master ICD-9 prosedur mata (08-16)
        ]);

        // --- Hanya superadmin (data user lain diisi manual via UI) ---
        $this->seedSuperadmin();
    }

    /**
     * Buat/perbarui HANYA akun superadmin. employee_id null (superadmin
     * bukan pegawai klinis). Password real "Superadmin@123" (auto-hash
     * via cast di model User).
     */
    private function seedSuperadmin(): void
    {
        $role = Role::where('name', 'superadmin')->first();

        User::updateOrCreate(
            ['email' => 'superadmin@arumed.id'],
            [
                'username'    => 'superadmin',
                'name'        => 'Superadmin',
                'role_id'     => $role?->id,
                'employee_id' => null,
                'password'    => 'Superadmin@123',
                'is_active'   => true,
            ]
        );
    }
}
