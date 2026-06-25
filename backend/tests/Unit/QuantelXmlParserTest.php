<?php

namespace Tests\Unit;

use App\Services\QuantelXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Parser XML Quantel Compact Touch — pure logic (tanpa DB/Laravel).
 *
 * Fixture:
 *  - quantel_sample.xml                  : firmware 2020, biometri OD.
 *  - quantel_usg_sample.xml              : firmware 2026, B-scan (USG).
 *  - quantel_biometry_2026_single_eye.xml: firmware V.5.0.3 (2026), HANYA mata OS
 *    diperiksa — node ExamBioOd kosong tetap ditulis alat. Regresi untuk bug
 *    "kolom mata hantu" (mata tanpa nilai biometri/IOL tak boleh ikut).
 */
class QuantelXmlParserTest extends TestCase
{
    private function parse(string $fixture): ?array
    {
        return (new QuantelXmlParser())->parse(file_get_contents(__DIR__ . "/../Fixtures/{$fixture}"));
    }

    public function test_parses_2026_firmware_biometry_and_iol_table(): void
    {
        $out = $this->parse('quantel_biometry_2026_single_eye.xml');

        $this->assertNotNull($out, 'XML biometri 2026 harus dikenali');
        $this->assertSame('BIOMETRY', $out['exam_kind']);
        $this->assertSame('2026060065', $out['no_rm']);
        $this->assertSame('Besta', $out['physician']);

        // Nilai biometri inti = persis laporan PDF alat (A.C. 3.18, T.L. 24.34, K1/K2).
        $os = $out['eyes']['OS']['biometry'];
        $this->assertSame(24.34, $os['axial_length']);
        $this->assertSame(3.18, $os['acd']);
        $this->assertSame(44.5, $os['k1']);
        $this->assertSame(45.75, $os['k2']);
        $this->assertSame(45.125, $os['kcor']);

        // Tabel hitung IOL: 4 A-constant, semuanya SRK/T (sesuai PDF).
        $calc = $out['eyes']['OS']['iol_calc'];
        $this->assertCount(4, $calc);
        $this->assertSame('SRK/T', $calc[0]['formula']);
        $this->assertSame(118.0, $calc[0]['a_constant']);
        $this->assertEqualsWithDelta(15.91, $calc[0]['emmetropia_power'], 0.01);
        $this->assertSame([118.0, 118.7, 118.3, 118.4], array_column($calc, 'a_constant'));
    }

    /**
     * Bug "kolom mata hantu": saat hanya 1 mata diperiksa, alat tetap menulis
     * node ExamBio{side} kosong untuk mata lain. Mata kosong itu TIDAK boleh
     * masuk hasil (kalau masuk, panel menampilkan kolom penuh "—").
     */
    public function test_skips_empty_eye_when_only_one_eye_examined(): void
    {
        $out = $this->parse('quantel_biometry_2026_single_eye.xml');

        $this->assertSame(['OS'], array_keys($out['eyes']), 'Hanya OS yang punya data; OD kosong harus dilewati');
    }

    public function test_parses_legacy_2020_biometry(): void
    {
        $out = $this->parse('quantel_sample.xml');

        $this->assertSame('BIOMETRY', $out['exam_kind']);
        $this->assertArrayHasKey('OD', $out['eyes']);
        $this->assertSame(22.95, $out['eyes']['OD']['biometry']['axial_length']);
        $this->assertCount(2, $out['eyes']['OD']['iol_calc']);
    }

    public function test_classifies_bscan_as_usg_without_eyes(): void
    {
        $out = $this->parse('quantel_usg_sample.xml');

        $this->assertSame('USG', $out['exam_kind']);
        $this->assertEmpty($out['eyes'], 'B-scan tak punya tabel biometri/IOL per mata');
    }

    public function test_returns_null_for_unrecognized_xml(): void
    {
        $this->assertNull((new QuantelXmlParser())->parse('<NotQuantel/>'));
        $this->assertNull((new QuantelXmlParser())->parse(''));
    }
}
