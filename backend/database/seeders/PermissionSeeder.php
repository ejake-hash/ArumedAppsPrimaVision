<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Modul × 3 aksi (R/W/D) permission baris. Tambah modul = tambah satu entri
     * di $modules (mis. 'penunjang'). Key format: "{module}.{action_lower}".
     * Frontend DataPenggunaView.vue ambil modul dari store.permissionGroups
     * (otomatis sinkron dengan seeder ini).
     */
    public function run(): void
    {
        $modules = [
            'admisi'             => 'Admisi & Antrean',
            'antrian_tv'         => 'Antrean TV',
            'perawat'            => 'Stasiun Perawat',
            'refraksionis'       => 'Stasiun Refraksionis',
            'penunjang'          => 'Stasiun Penunjang',
            'rme_dokter'         => 'RME Dokter',
            'rekam_medis'        => 'Rekam Medis & TTD Dokumen',
            'bedah'              => 'Unit Bedah',
            'farmasi'            => 'Farmasi Unit',
            'kasir'              => 'Kasir & Billing',
            'rawat_inap'         => 'Rawat Inap (RANAP)',
            'igd'                => 'Instalasi Gawat Darurat (IGD)',
            'bpjs'               => 'BPJS & Klaim',
            'laporan'            => 'Laporan & Analitik',
            'tarif_paket'        => 'Tarif & Paket Bedah',
            'inventori_farmasi'  => 'Inventori Farmasi (Obat/BHP/IOL)',
            'supplier'           => 'Master Supplier',
            'pembelian'          => 'Pembelian (Purchase Order)',
            'penerimaan'         => 'Penerimaan Barang (GRN)',
            'request_unit'       => 'Request & Retur dari Unit',
            'master_obat'        => 'Master Obat',
            'master_bhp'         => 'Master BHP',
            'master_iol'         => 'Master IOL',
            'master_icd'         => 'Master ICD (10 & 9)',
            'role_akses'         => 'Role & Hak Akses',
            'audit'              => 'Audit Log',
            'pengaturan'         => 'Pengaturan Sistem',
            'form_template'      => 'Form Template (Rekam Medis)',
            'integrasi'          => 'Bridging BPJS (VClaim/Antrean)',
        ];

        $actions = [
            'R' => ['key' => 'read',   'label' => 'Lihat'],
            'W' => ['key' => 'write',  'label' => 'Tambah/Ubah'],
            'D' => ['key' => 'delete', 'label' => 'Hapus'],
        ];

        foreach ($modules as $moduleId => $moduleLabel) {
            foreach ($actions as $actionCode => $actionMeta) {
                $key = "{$moduleId}.{$actionMeta['key']}";

                Permission::updateOrCreate(
                    ['key' => $key],
                    [
                        'module'      => $moduleId,
                        'action'      => $actionCode,
                        'label'       => "{$actionMeta['label']} — {$moduleLabel}",
                        'description' => null,
                        'is_active'   => true,
                    ]
                );
            }
        }
    }
}
