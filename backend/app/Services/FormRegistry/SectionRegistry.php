<?php

namespace App\Services\FormRegistry;

/**
 * Daftar section valid per station.
 *
 * Dipakai oleh:
 *   - UI admin (master) untuk dropdown saat assign template ke station+section
 *   - Backend untuk validasi station_assignments saat store/update template
 *   - Endpoint runtime `/rekam-medis/forms?station=X&section=Y` untuk filter
 */
final class SectionRegistry
{
    public static function map(): array
    {
        return [
            'admisi'       => ['identitas', 'dokumen_admisi'],
            'perawat'      => ['asesmen_input', 'dokumen_perawat'],
            'refraksionis' => ['hasil_refraksi'],
            'dokter'       => ['asesmen_input', 'resume_output', 'surat', 'consent'],
            'penunjang'    => ['hasil_penunjang'],
            // laporan_operasi = daftar terpadu laporan operasi + Resume Medis Bedah
            // (picker search BedahView tab Laporan). checklist_kesiapan = modal Pra-Bedah.
            'bedah'        => ['laporan_operasi', 'checklist_kesiapan', 'laporan_bedah', 'consent_operasi'],
            'kasir'        => ['invoice_dokumen'],
            'farmasi'      => ['resep_dokumen'],
            'ranap'        => ['pengantar_dirawat', 'pengkajian_awal', 'asuhan_keperawatan', 'keselamatan', 'edukasi', 'obat', 'transfer', 'ringkasan_pulang', 'consent_ranap'],
        ];
    }

    public static function stations(): array
    {
        return array_keys(self::map());
    }

    public static function sectionsFor(string $station): array
    {
        return self::map()[$station] ?? [];
    }

    public static function isValid(string $station, string $section): bool
    {
        return in_array($section, self::sectionsFor($station), true);
    }
}
