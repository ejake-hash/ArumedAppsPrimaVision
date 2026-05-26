<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // RM-1 — Administrasi
            [
                'code'                => 'RM-1.1',
                'name'                => 'Formulir Persetujuan Umum (General Consent)',
                'fill_frequency'      => 'ONCE_LIFETIME',
                'generate_type'       => 'MANUAL',
                'category'            => 'ADMINISTRASI',
                'required_signatures' => null,
                'show_in_rme'         => true,
                'sort_order'          => 1,
            ],
            [
                'code'                => 'RM-1.2',
                'name'                => 'Surat Keterangan Rawat Jalan',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'AUTO',
                'category'            => 'ADMINISTRASI',
                'required_signatures' => null,
                'show_in_rme'         => true,
                'sort_order'          => 2,
            ],
            [
                'code'                => 'RM-1.3',
                'name'                => 'Identitas Pasien',
                'fill_frequency'      => 'ONCE_LIFETIME',
                'generate_type'       => 'AUTO',
                'category'            => 'ADMINISTRASI',
                'required_signatures' => null,
                'show_in_rme'         => true,
                'sort_order'          => 3,
            ],

            // RM-2 — Asesmen Klinis
            [
                'code'                => 'RM-2.1',
                'name'                => 'Asesmen Keperawatan',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'MANUAL',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'PERAWAT', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 4,
            ],
            [
                'code'                => 'RM-2.2',
                'name'                => 'Rekam Refraksi',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'MANUAL',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'REFRAKSIONIS', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 5,
            ],
            [
                'code'                => 'RM-2.3',
                'name'                => 'Pemeriksaan Dokter Mata',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'MANUAL',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 6,
            ],

            // RM-3 — Penunjang
            [
                'code'                => 'RM-3.1',
                'name'                => 'Formulir Order Penunjang',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'MANUAL',
                'category'            => 'PENUNJANG',
                'required_signatures' => null,
                'show_in_rme'         => true,
                'sort_order'          => 7,
            ],
            [
                'code'                => 'RM-3.2',
                'name'                => 'Hasil Pemeriksaan Penunjang',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'MANUAL',
                'category'            => 'PENUNJANG',
                'required_signatures' => null,
                'show_in_rme'         => true,
                'sort_order'          => 8,
            ],

            // RM-4 — Farmasi
            [
                'code'                => 'RM-4.1',
                'name'                => 'Resep Obat',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'MANUAL',
                'category'            => 'FARMASI',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 9,
            ],
            [
                'code'                => 'RM-4.2',
                'name'                => 'Resep Kacamata',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'MANUAL',
                'category'            => 'FARMASI',
                'required_signatures' => [['role' => 'REFRAKSIONIS', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 10,
            ],

            // RM-5 — Tindakan & Bedah
            [
                'code'                => 'RM-5.1',
                'name'                => 'Persetujuan Tindakan Medis (Informed Consent Umum)',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 11,
            ],
            [
                'code'                => 'RM-5.2',
                'name'                => 'Persetujuan Operasi (Informed Consent Bedah)',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 12,
            ],
            [
                'code'                => 'RM-5.3',
                'name'                => 'Laporan Operasi',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 13,
            ],
            [
                'code'                => 'RM-5.4',
                'name'                => 'Catatan Anestesi',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => null,
                'show_in_rme'         => true,
                'sort_order'          => 14,
            ],

            // RM-6 — Resume & Surat
            [
                'code'                => 'RM-6.1',
                'name'                => 'Resume Medis Rawat Jalan',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'AUTO',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 15,
            ],
            [
                'code'                => 'RM-6.2',
                'name'                => 'Surat Kontrol Ulang',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'AUTO',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 16,
            ],
            [
                'code'                => 'RM-6.3',
                'name'                => 'Surat Rujukan',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'MANUAL',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 17,
            ],
            [
                'code'                => 'RM-6.4',
                'name'                => 'Surat Keterangan Sakit',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'MANUAL',
                'category'            => 'ADMINISTRASI',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 18,
            ],
            [
                'code'                => 'RM-6.5',
                'name'                => 'Surat Keterangan Sehat',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'MANUAL',
                'category'            => 'ADMINISTRASI',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 19,
            ],

            // RM-7 — BPJS
            [
                'code'                => 'RM-7.1',
                'name'                => 'Surat Eligibilitas Peserta (SEP)',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'AUTO',
                'category'            => 'BILLING',
                'required_signatures' => null,
                'show_in_rme'         => false,
                'sort_order'          => 20,
            ],
            [
                'code'                => 'RM-7.2',
                'name'                => 'Formulir Klaim BPJS',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'AUTO',
                'category'            => 'BILLING',
                'required_signatures' => null,
                'show_in_rme'         => false,
                'sort_order'          => 21,
            ],
            [
                'code'                => 'RM-7.3',
                'name'                => 'Surat Kontrol BPJS (Surat Kontrol vClaim)',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'AUTO',
                'category'            => 'BILLING',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => false,
                'sort_order'          => 22,
            ],

            // RM-8 — Informed Consent Khusus (children of RM-5.2)
            [
                'code'                => 'RM-8.1',
                'name'                => 'Informed Consent Operasi Katarak',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 23,
            ],
            [
                'code'                => 'RM-8.2',
                'name'                => 'Informed Consent Operasi Pterigium',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 24,
            ],
        ];

        foreach ($types as $type) {
            DocumentType::updateOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['is_active' => true])
            );
        }

        // Set parent_id: RM-8.1 and RM-8.2 are sub-types of RM-5.2 (IC Bedah)
        $icBedah = DocumentType::where('code', 'RM-5.2')->first();
        if ($icBedah) {
            DocumentType::whereIn('code', ['RM-8.1', 'RM-8.2'])
                ->update(['parent_id' => $icBedah->id]);
        }
    }
}
