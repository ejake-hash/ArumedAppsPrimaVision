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

            // RM-2.0 — Checklist Kesiapan Bedah (form resmi RS: RM 2.0/CKB/22).
            // Diisi perawat kamar bedah pra-op. Slot RM-2.0 sebelumnya kosong (tak
            // bentrok). Template: CHECKLIST_KESIAPAN_BEDAH (FormTemplateSeeder).
            // Signer perawat (opsional — fase transisi belum ada tablet, lihat
            // Docs/KATALOG-FORMULIR-RM.md). Bukan TTD dokter → tak masuk antrean TtdView.
            [
                'code'                => 'RM-2.0',
                'name'                => 'Checklist Kesiapan Bedah',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [['role' => 'PERAWAT', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 10,
            ],

            // RM-10.1 — Laporan Operasi Vitreo Retina (form resmi RS: RM 10.1/LOVR/22).
            // Diisi tim bedah saat operasi VITREORETINA (pemicu surgery_packages.surgery_type
            // = VITREORETINA). Template: LAPORAN_OPERASI_VITREO_RETINA (FormTemplateSeeder).
            // Signer DPJP Bedah (DOKTER) opsional — fase transisi belum ada tablet.
            [
                'code'                => 'RM-10.1',
                'name'                => 'Laporan Operasi Vitreo Retina',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 30,
            ],

            // RM-2.3-COK — Catatan Operasi (form resmi RS: RM 2.3/COK/22). Catatan
            // operasi terstruktur KHAS KATARAK/FAKO (capsulotomy/IOL/komplikasi PCR-ECCE).
            // Kode 'RM-2.3' asli = "Pemeriksaan Dokter Mata" → pakai suffix -COK (nomor
            // resmi RM 2.3/COK/22 dicetak di layout). Slug: CATATAN_OPERASI_KATARAK.
            // Diisi tim bedah saat operasi KATARAK; TTD DPJP (DOKTER) opsional.
            [
                'code'                => 'RM-2.3-COK',
                'name'                => 'Catatan Operasi (Katarak)',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 11,
            ],

            // RM-2.2-LP — Laporan Pembedahan (form resmi RS: RM 2.2/LP/22). Laporan
            // operasi GENERIK nasional (Permenkes) — berlaku SEMUA jenis operasi.
            // Kode 'RM-2.2' asli = "Rekam Refraksi" → pakai suffix -LP. Slug:
            // LAPORAN_PEMBEDAHAN. Menggantikan RM-5.3/RM_BEDAH_LAPORAN yg dimatikan
            // (auto-publish lama → kini FormDocsBrowser). TTD DPJP (DOKTER) opsional.
            [
                'code'                => 'RM-2.2-LP',
                'name'                => 'Laporan Pembedahan',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 12,
            ],

            // ── Laporan operasi subspesialis (Fase 1) — form resmi RS, section
            // terpadu `laporan_operasi` (picker search BedahView), 'recommended' saat
            // operator konfirmasi jenis prosedur (operation_report.report_types).
            // Slug template di FormTemplateSeeder. TTD DPJP/Operator (DOKTER) opsional.

            // RM-8.10-LOT — Laporan Operasi Trabekulektomi (RM 8.10/LOT/22). Jenis GLAUKOMA.
            [
                'code'                => 'RM-8.10-LOT',
                'name'                => 'Laporan Operasi Trabekulektomi',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 13,
            ],
            // RM-9.0-LOP — Laporan Operasi Pterygium (RM 9.0/LOP/22). Jenis PTERYGIUM.
            [
                'code'                => 'RM-9.0-LOP',
                'name'                => 'Laporan Operasi Pterygium',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 14,
            ],
            // RM-8.8-LIAV — Laporan Injeksi Anti VEGF (RM 8.8/LIAV/22). Jenis INJEKSI.
            [
                'code'                => 'RM-8.8-LIAV',
                'name'                => 'Laporan Injeksi Anti VEGF',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 15,
            ],

            // ── Anestesi (Fase 2) — consent + penilaian pra-anestesi. INPUT (diisi tim
            // anestesi/pasien). Section laporan_operasi, conditions requires_anesthesia
            // (soft: 'recommended' saat ada anestesi). Laporan Anestesi RM 5.2 sendiri =
            // wizard bespoke (AnesthesiaReportWizard), bukan template Form Registry.

            // RM-4.3-PTA — Persetujuan Tindakan Anestesi (consent). TTD pasien+dokter+saksi.
            [
                'code'                => 'RM-4.3-PTA',
                'name'                => 'Persetujuan Tindakan Anestesi',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [
                    ['role' => 'PASIEN', 'sign_type' => 'manual', 'is_required' => false],
                    ['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => false],
                ],
                'show_in_rme'         => true,
                'sort_order'          => 16,
            ],
            // RM-4.4-PPA — Penilaian Pra Anestesi & Sedasi (diisi pasien + dokter anestesi).
            [
                'code'                => 'RM-4.4-PPA',
                'name'                => 'Penilaian Pra Anestesi dan Sedasi',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [
                    ['role' => 'PASIEN', 'sign_type' => 'manual', 'is_required' => false],
                    ['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => false],
                ],
                'show_in_rme'         => true,
                'sort_order'          => 17,
            ],

            // ── Safety & peri-operatif (Fase 3) — section checklist_kesiapan (modal
            // Pra-Bedah). RM 4.9 WHO SSC = REUSE data sign-in/time-out/sign-out BedahView
            // (K6). RM 1.9 Site Marking (SKP 4). RM 1.10 Proses Peri-operatif (perawat).

            // RM-1.9-SM — Site Marking / Penandaan Operasi (SKP 4). TTD pasien+dokter+perawat.
            [
                'code'                => 'RM-1.9-SM',
                'name'                => 'Site Marking (Penandaan Operasi)',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [
                    ['role' => 'PASIEN',  'sign_type' => 'manual',  'is_required' => false],
                    ['role' => 'DOKTER',  'sign_type' => 'digital', 'is_required' => false],
                    ['role' => 'PERAWAT', 'sign_type' => 'digital', 'is_required' => false],
                ],
                'show_in_rme'         => true,
                'sort_order'          => 18,
            ],
            // RM-4.9-SSC — Checklist Keselamatan Pasien Operasi (WHO SSC 3 fase).
            [
                'code'                => 'RM-4.9-SSC',
                'name'                => 'Checklist Keselamatan Pasien Operasi',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [
                    ['role' => 'DOKTER',  'sign_type' => 'digital', 'is_required' => false],
                    ['role' => 'PERAWAT', 'sign_type' => 'digital', 'is_required' => false],
                ],
                'show_in_rme'         => true,
                'sort_order'          => 19,
            ],
            // RM-1.10-PPO — Proses Perawatan Peri-operatif (perawat ruangan + kamar bedah).
            [
                'code'                => 'RM-1.10-PPO',
                'name'                => 'Proses Perawatan Peri-Operatif',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [
                    ['role' => 'PERAWAT', 'sign_type' => 'digital', 'is_required' => false],
                ],
                'show_in_rme'         => true,
                'sort_order'          => 20,
            ],

            // ── RANAP (Rawat Inap) — Phase 1, 3 form nakes-only/PIN (TTD pasien
            // ditunda sampai PSrE). Kode disambiguasi (-RI/-PAM/-AAKRI) karena nomor
            // resmi RM 3.5/7.7/7.8 bentrok dengan slot kode internal lama. Stasiun
            // RANAP (RawatInapView tab Dokumen). Slug template di FormTemplateSeeder:
            // RESUME_MEDIS_RANAP / PENGKAJIAN_AWAL_MEDIS / ASESMEN_AWAL_KEPERAWATAN_RI.

            // RM-3.5-RI — Resume Medis Rawat Inap (discharge summary). DPJP, auto-buka
            // saat discharge (mirip Resume RJ). TTD dokter opsional (fase transisi).
            [
                'code'                => 'RM-3.5-RI',
                'name'                => 'Resume Medis Rawat Inap',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 40,
            ],
            // RM-3.5-LB — Resume Medis Bedah (ringkasan pulang pasien BEDAH rawat
            // jalan / day-surgery). Menggantikan RM 1.7 (RESUME_MEDIS) untuk pasien
            // bedah. Template RESUME_MEDIS_BEDAH, station bedah:laporan_operasi. Draft
            // dibuat otomatis saat finalisasi laporan operasi. TTD DPJP/operator wajib.
            [
                'code'                => 'RM-3.5-LB',
                'name'                => 'Resume Medis Bedah',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 48,
            ],
            // RM-7.7-PAM — Pengkajian Awal Medis Rawat Inap (asesmen medis ≤24 jam).
            // DPJP. TTD dokter opsional.
            [
                'code'                => 'RM-7.7-PAM',
                'name'                => 'Pengkajian Awal Medis Rawat Inap',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 41,
            ],
            // RM-7.8-AAKRI — Asesmen Awal Keperawatan Rawat Inap (perawat ≤24 jam;
            // skor Norton + skrining gizi MST via ScoringEngine). TTD perawat opsional.
            [
                'code'                => 'RM-7.8-AAKRI',
                'name'                => 'Asesmen Awal Keperawatan Rawat Inap',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'PERAWAT', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 42,
            ],

            // ── RANAP Phase 2 (Tier 2 — keselamatan/kepatuhan akreditasi) ─────
            // Kode disambiguasi (-JTH/-EDU/-REK) karena nomor resmi RM 2.9/2.4/2.7
            // bentrok dgn slot kode internal RAJAL. TTD nakes (perawat/apoteker) opsional.
            // RM-2.9-JTH — Pencegahan Pasien Jatuh (SKP 6). Perawat.
            [
                'code'                => 'RM-2.9-JTH',
                'name'                => 'Pelaksanaan Pencegahan Pasien Jatuh',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'PERAWAT', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 43,
            ],
            // RM-2.4-EDU — Edukasi Terintegrasi (MKE). Perawat/edukator. TTD pasien
            // DITUNDA (butuh PSrE) → hanya TTD nakes.
            [
                'code'                => 'RM-2.4-EDU',
                'name'                => 'Edukasi Terintegrasi Rawat Inap',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'PERAWAT', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 44,
            ],
            // RM-2.7-REK — Rekonsiliasi Obat (PKPO/SKP 3). Farmasi/perawat.
            [
                'code'                => 'RM-2.7-REK',
                'name'                => 'Rekonsiliasi Obat',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'FARMASI',
                'required_signatures' => [['role' => 'APOTEKER', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 45,
            ],

            // ── RANAP Phase 3 (Tier 3 — ARK: akses & kontinuitas) ────────────
            // Kode disambiguasi (-SPD/-TRF). TTD nakes; TTD pasien/keluarga ditunda (PSrE).
            // RM-2.5-SPD — Surat Pengantar Untuk Dirawat Inap. Dokter IGD/poli.
            [
                'code'                => 'RM-2.5-SPD',
                'name'                => 'Surat Pengantar Untuk Dirawat Inap',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 46,
            ],
            // RM-2.6-TRF — Transfer Pasien (antar ruang/unit). DPJP/perawat.
            [
                'code'                => 'RM-2.6-TRF',
                'name'                => 'Formulir Transfer Pasien',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'PERAWAT', 'sign_type' => 'digital', 'is_required' => false]],
                'show_in_rme'         => true,
                'sort_order'          => 47,
            ],
        ];

        // PEMBERSIHAN 7 Jun 2026 (atas permintaan): katalog jenis dokumen dibangun
        // ulang dari form RESMI. Hanya RM-6.1 (Resume Medis Rawat Jalan — terkait
        // template RESUME_MEDIS) yang di-seed. Definisi 24 jenis lain DIPERTAHANKAN
        // di array $types sebagai referensi; tambahkan kembali code-nya ke filter
        // ini saat jenisnya dibangun dari PDF resmi.
        $types = array_values(array_filter($types, fn ($t) => in_array($t['code'] ?? null, [
            'RM-6.1', 'RM-2.0', 'RM-10.1', 'RM-2.3-COK', 'RM-2.2-LP', 'RM-3.5-RI', 'RM-3.5-LB', 'RM-7.7-PAM', 'RM-7.8-AAKRI', 'RM-2.9-JTH', 'RM-2.4-EDU', 'RM-2.7-REK', 'RM-2.5-SPD', 'RM-2.6-TRF',
            // Fase 1-3 laporan operasi subspesialis + anestesi + safety (form resmi RS).
            'RM-8.10-LOT', 'RM-9.0-LOP', 'RM-8.8-LIAV', 'RM-4.3-PTA', 'RM-4.4-PPA', 'RM-1.9-SM', 'RM-4.9-SSC', 'RM-1.10-PPO',
        ], true)));

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
