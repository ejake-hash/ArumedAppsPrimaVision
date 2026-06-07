<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * RBAC realign — selaraskan permission key dengan modul/stasiun nyata.
 *
 * GABUNG: inventori_farmasi menyerap master_obat/master_bhp/master_iol +
 * supplier + pembelian + penerimaan; master_data menyerap pengaturan +
 * form_template + master_icd; tarif_paket menyerap kategori tagihan (+delete).
 * PISAH: jadwal_dokter (eks admisi), ttd_dokumen (eks rekam_medis → dokter),
 * asuransi (eks kasir). request_unit & farmasi tetap berdiri sendiri.
 *
 * Additive & idempoten (aman utk prod): tambah key baru, re-attach grant tanpa
 * detach (preserve kustomisasi UI), lalu hapus key usang (cascade role_permissions).
 * Middleware route & RolePermissionSeeder sudah diselaraskan ke key baru.
 */
return new class extends Migration
{
    private array $newModules = [
        'master_data'   => 'Master Data (Profil, ICD, Penjamin, Template Form)',
        'jadwal_dokter' => 'Jadwal Dokter',
        'ttd_dokumen'   => 'Tanda Tangan Dokumen (Dokter)',
        'asuransi'      => 'Asuransi & Klaim TPA',
    ];

    private array $obsoleteModules = [
        'master_obat', 'master_bhp', 'master_iol',
        'supplier', 'pembelian', 'penerimaan',
        'master_icd', 'pengaturan', 'form_template',
    ];

    private array $actions = ['R' => 'read', 'W' => 'write', 'D' => 'delete'];
    private array $actionLabels = ['R' => 'Lihat', 'W' => 'Tambah/Ubah', 'D' => 'Hapus'];

    public function up(): void
    {
        // 1. Tambah permission key baru (idempoten).
        foreach ($this->newModules as $module => $label) {
            $this->ensureModulePermissions($module, $label);
        }

        // 2. Grant key baru ke role (preserve perilaku lama). syncWithoutDetaching =
        //    tidak menghapus grant lain → aman utk kustomisasi via UI.
        //    master_data.write/delete sengaja TIDAK diberikan ke role mana pun
        //    (admin = superadmin bypass); manajemen cukup read.
        $grants = [
            'jadwal_dokter' => ['admisi' => ['read', 'write'], 'manajemen' => ['read']],
            'ttd_dokumen'   => ['dokter' => ['read', 'write']],
            'asuransi'      => ['kasir' => ['read', 'write'], 'manajemen' => ['read']],
            'master_data'   => ['manajemen' => ['read']],
            // Rapikan izin .delete yatim: inventori (punya tarif_paket R,W) dapat delete.
            'tarif_paket'   => ['inventori' => ['delete']],
            // Defensif: farmasi/inventori/manajemen umumnya SUDAH punya inventori_farmasi,
            // tapi pastikan grant ada SEBELUM key supplier/pembelian/penerimaan/master_*
            // dihapus (langkah 3) — antisipasi DB prod yang role-nya pernah diedit manual.
            'inventori_farmasi' => [
                'farmasi'   => ['read', 'write', 'delete'],
                'inventori' => ['read', 'write', 'delete'],
                'manajemen' => ['read'],
            ],
        ];
        foreach ($grants as $module => $roleActions) {
            foreach ($roleActions as $roleName => $acts) {
                $role = Role::where('name', $roleName)->first();
                if (! $role) continue;
                $keys = array_map(fn ($a) => "{$module}.{$a}", $acts);
                $ids = Permission::whereIn('key', $keys)->pluck('id')->all();
                if ($ids) $role->permissions()->syncWithoutDetaching($ids);
            }
        }

        // 3. Hapus permission key usang — FK role_permissions cascade on delete.
        Permission::whereIn('module', $this->obsoleteModules)->delete();
    }

    public function down(): void
    {
        // Lepas grant + hapus key baru.
        Permission::whereIn('module', array_keys($this->newModules))->delete();

        // Pulihkan baris permission key usang (struktur saja; grant role lama tidak
        // dibangun ulang — jalankan PermissionSeeder + RolePermissionSeeder bila perlu).
        $legacyLabels = [
            'master_obat' => 'Master Obat', 'master_bhp' => 'Master BHP',
            'master_iol' => 'Master IOL', 'supplier' => 'Master Supplier',
            'pembelian' => 'Pembelian (Purchase Order)', 'penerimaan' => 'Penerimaan Barang (GRN)',
            'master_icd' => 'Master ICD (10 & 9)', 'pengaturan' => 'Pengaturan Sistem',
            'form_template' => 'Form Template (Rekam Medis)',
        ];
        foreach ($legacyLabels as $module => $label) {
            $this->ensureModulePermissions($module, $label);
        }
    }

    private function ensureModulePermissions(string $module, string $label): void
    {
        foreach ($this->actions as $code => $action) {
            Permission::updateOrCreate(
                ['key' => "{$module}.{$action}"],
                [
                    'module'    => $module,
                    'action'    => $code,
                    'label'     => "{$this->actionLabels[$code]} — {$label}",
                    'is_active' => true,
                ],
            );
        }
    }
};
