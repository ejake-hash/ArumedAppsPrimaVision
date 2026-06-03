<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Matriks default mengikuti mockup DataPenggunaView.vue.
     * Key role di sini = field `name` di RoleSeeder (lowercase).
     * "audit" hanya boleh R (read), tidak ada W/D.
     */
    public function run(): void
    {
        $matrix = [
            // Superadmin di-skip — bypass via Role::isSuperadmin().
            'dokter' => [
                'admisi'        => ['R'],
                'antrian_tv'    => ['R'],
                'perawat'       => ['R'],
                'refraksionis'  => ['R'],
                'penunjang'     => ['R','W'],
                'rme_dokter'    => ['R','W'],
                'rekam_medis'   => ['R','W'],
                'bedah'         => ['R','W','C'],
                'ruang_tindakan' => ['R','W'],
                'rawat_inap'    => ['R','W'],
                'farmasi'       => ['R'],
                // Unit Bedah minta/retur BHP-IOL ke gudang (BedahTerjadwalView, station=BEDAH).
                'request_unit'  => ['R','W'],
                'bpjs'          => ['R'],
                'laporan'       => ['R'],
                'form_template' => ['R','W'],
            ],
            'perawat' => [
                'admisi'       => ['R'],
                'antrian_tv'   => ['R'],
                'perawat'      => ['R','W'],
                'refraksionis' => ['R'],
                'rme_dokter'   => ['R'],
                'rekam_medis'  => ['R'],
                'bedah'        => ['R','C'],
                'ruang_tindakan' => ['R','W'],
                'rawat_inap'   => ['R','W'],
                'farmasi'      => ['R'],
                'bpjs'         => ['R'],
            ],
            'refraksionis' => [
                'admisi'       => ['R'],
                'antrian_tv'   => ['R'],
                'perawat'      => ['R'],
                'refraksionis' => ['R','W'],
                'rme_dokter'   => ['R'],
                'rekam_medis'  => ['R'],
            ],
            'farmasi' => [
                'admisi'            => ['R'],
                'rme_dokter'        => ['R'],
                'rekam_medis'       => ['R'],
                'bedah'             => ['R'],
                'farmasi'           => ['R','W'],
                'rawat_inap'        => ['R','W'],
                'inventori_farmasi' => ['R','W','D'],
                'supplier'          => ['R','W','D'],
                'pembelian'         => ['R','W','D'],
                'penerimaan'        => ['R','W','D'],
                'request_unit'      => ['R','W','D'],
                'kasir'             => ['R'],
                'bpjs'              => ['R'],
                'laporan'           => ['R'],
            ],
            'kasir' => [
                'admisi'       => ['R','W'],
                'antrian_tv'   => ['R'],
                'rme_dokter'   => ['R'],
                'rekam_medis'  => ['R'],
                'farmasi'      => ['R'],
                'kasir'        => ['R','W'],
                'rawat_inap'   => ['R'],
                'bpjs'         => ['R','W'],
                'integrasi'    => ['R','W'],
                'laporan'      => ['R'],
            ],
            'admisi' => [
                'admisi'       => ['R','W'],
                'antrian_tv'   => ['R'],
                'perawat'      => ['R'],
                'refraksionis' => ['R'],
                'rme_dokter'   => ['R'],
                'rekam_medis'  => ['R'],
                'rawat_inap'   => ['R','W'],
                'bpjs'         => ['R','W'],
                'integrasi'    => ['R','W'],
                'kasir'        => ['R'],
                'laporan'      => ['R'],
            ],
            'penunjang' => [
                'admisi'       => ['R'],
                'antrian_tv'   => ['R'],
                'perawat'      => ['R'],
                'penunjang'    => ['R','W'],
                'rme_dokter'   => ['R'],
                'rekam_medis'  => ['R'],
                'bedah'        => ['R'],
            ],
            'verifikator' => [
                'admisi'       => ['R'],
                'rme_dokter'   => ['R'],
                'rekam_medis'  => ['R','W'],
                'farmasi'      => ['R'],
                'kasir'        => ['R'],
                'bpjs'         => ['R','W'],
                'integrasi'    => ['R','W'],
                'laporan'      => ['R'],
                'audit'        => ['R'],
            ],
            // Manajemen — oversight read-only lintas modul + laporan penuh + audit.
            'manajemen' => [
                'admisi'            => ['R'],
                'antrian_tv'        => ['R'],
                'perawat'           => ['R'],
                'refraksionis'      => ['R'],
                'penunjang'         => ['R'],
                'rme_dokter'        => ['R'],
                'rekam_medis'       => ['R'],
                'bedah'             => ['R'],
                'ruang_tindakan'    => ['R'],
                'farmasi'           => ['R'],
                'kasir'             => ['R'],
                'rawat_inap'        => ['R'],
                'bpjs'              => ['R'],
                'inventori_farmasi' => ['R'],
                'supplier'          => ['R'],
                'pembelian'         => ['R'],
                'penerimaan'        => ['R'],
                'request_unit'      => ['R'],
                'tarif_paket'       => ['R'],
                'integrasi'         => ['R'],
                'laporan'           => ['R','W'],
                'marketing'         => ['R'],
                'audit'             => ['R'],
                'form_template'     => ['R'],
            ],
            // Inventori — rantai gudang/inventori farmasi penuh + master terkait.
            'inventori' => [
                'farmasi'           => ['R'],
                'inventori_farmasi' => ['R','W','D'],
                'supplier'          => ['R','W','D'],
                'pembelian'         => ['R','W','D'],
                'penerimaan'        => ['R','W','D'],
                'request_unit'      => ['R','W','D'],
                'master_obat'       => ['R','W','D'],
                'master_bhp'        => ['R','W','D'],
                'master_iol'        => ['R','W','D'],
                'tarif_paket'       => ['R','W'],
                'laporan'           => ['R'],
            ],
        ];

        // 'C' = checklist (permission khusus bedah.checklist di luar pola R/W/D).
        $actionMap = ['R' => 'read', 'W' => 'write', 'D' => 'delete', 'C' => 'checklist'];
        $permissions = Permission::all()->keyBy('key');

        foreach ($matrix as $roleName => $modules) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) continue;

            $ids = [];
            foreach ($modules as $moduleId => $actions) {
                foreach ($actions as $action) {
                    $key = "{$moduleId}." . ($actionMap[$action] ?? strtolower($action));
                    if ($perm = $permissions->get($key)) {
                        $ids[] = $perm->id;
                    }
                }
            }
            $role->permissions()->sync($ids);
        }
    }
}
