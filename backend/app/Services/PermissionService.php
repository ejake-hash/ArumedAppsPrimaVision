<?php

namespace App\Services;

use App\Models\Permission;

class PermissionService
{
    /**
     * Return semua permission, grouped by module.
     * Output format match dengan frontend matrix:
     * [
     *   { module: 'admisi', label: 'Admisi & Antrean', permissions: [{id, key, action, label}] },
     *   ...
     * ]
     */
    public function getAllGrouped(): array
    {
        $all = Permission::active()->orderBy('module')->orderBy('action')->get();

        $moduleLabels = [
            'admisi'       => 'Admisi & Antrean',
            'antrian_tv'   => 'Antrean TV',
            'perawat'      => 'Stasiun Perawat',
            'refraksionis' => 'Stasiun Refraksionis',
            'rme_dokter'   => 'RME Dokter',
            'bedah'        => 'Unit Bedah',
            'farmasi'      => 'Farmasi Unit',
            'gudang'       => 'Gudang Sentral',
            'kasir'        => 'Kasir & Billing',
            'bpjs'         => 'BPJS & Klaim',
            'laporan'      => 'Laporan & Analitik',
            'role_akses'   => 'Role & Hak Akses',
            'audit'        => 'Audit Log',
            'pengaturan'   => 'Pengaturan Sistem',
        ];

        $grouped = [];
        foreach ($all as $p) {
            $grouped[$p->module] ??= [
                'module' => $p->module,
                'label'  => $moduleLabels[$p->module] ?? $p->module,
                'permissions' => [],
            ];
            $grouped[$p->module]['permissions'][] = [
                'id'     => $p->id,
                'key'    => $p->key,
                'action' => $p->action,
                'label'  => $p->label,
            ];
        }

        return array_values($grouped);
    }

    /**
     * Flat list of all permissions (untuk lookup).
     */
    public function getAll(): array
    {
        return Permission::active()
            ->orderBy('module')->orderBy('action')
            ->get(['id', 'key', 'module', 'action', 'label'])
            ->toArray();
    }
}
