<?php

namespace App\Services;

use App\Models\ModuleLabelSetting;
use App\Models\Permission;

class PermissionService
{
    /**
     * Label default per modul (hardcoded). Override admin disimpan di
     * module_label_settings dan menimpa nilai ini lewat getAllGrouped().
     */
    public function defaultLabels(): array
    {
        return [
            'admisi'             => 'Admisi & Antrean',
            'jadwal_dokter'      => 'Jadwal Dokter',
            'antrian_tv'         => 'Antrean TV',
            'perawat'            => 'Stasiun Perawat',
            'refraksionis'       => 'Stasiun Refraksionis',
            'penunjang'          => 'Stasiun Penunjang',
            'rme_dokter'         => 'RME Dokter',
            'rekam_medis'        => 'Rekam Medis',
            'ttd_dokumen'        => 'Tanda Tangan Dokumen (Dokter)',
            'bedah'              => 'Unit Bedah',
            'anestesi'           => 'Anestesi (Laporan & Monitoring)',
            'ruang_tindakan'     => 'Ruang Tindakan (Laser/PRP)',
            'farmasi'            => 'Farmasi Unit',
            'kasir'              => 'Kasir & Billing',
            'asuransi'           => 'Asuransi & Klaim TPA',
            'rawat_inap'         => 'Rawat Inap (RANAP)',
            'igd'                => 'Instalasi Gawat Darurat (IGD)',
            'bpjs'               => 'BPJS & Klaim',
            'laporan'            => 'Laporan & Analitik',
            'marketing'          => 'Laporan Marketing',
            'tarif_paket'        => 'Tarif, Paket Bedah & Kategori Tagihan',
            'inventori_farmasi'  => 'Inventori Farmasi (Obat/BHP/IOL/Supplier/PO/GRN)',
            'request_unit'       => 'Request & Retur dari Unit',
            'master_data'        => 'Master Data (Profil, ICD, Penjamin, Template Form)',
            'role_akses'         => 'Role & Hak Akses',
            'audit'              => 'Audit Log',
            'integrasi'          => 'Bridging BPJS (VClaim/Antrean)',
        ];
    }

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

        // Default labels, lalu timpa dengan override admin (DB).
        $moduleLabels = array_merge($this->defaultLabels(), ModuleLabelSetting::overrides());

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

    /**
     * Set/ubah override label untuk satu modul (UI-only).
     * Hanya menerima modul yang punya permission terdaftar.
     */
    public function updateLabel(string $module, string $label): void
    {
        if (! Permission::where('module', $module)->exists()) {
            abort(404, 'Modul tidak ditemukan.');
        }

        ModuleLabelSetting::updateOrCreate(
            ['module' => $module],
            ['label'  => $label],
        );
    }

    /** Hapus override → label modul kembali ke default. */
    public function resetLabel(string $module): void
    {
        ModuleLabelSetting::where('module', $module)->delete();
    }
}
