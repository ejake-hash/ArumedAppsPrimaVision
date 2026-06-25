<?php

namespace App\Services;

/**
 * Parser file XML hasil alat Quantel Compact Touch (USG/Biometry + hitung IOL).
 *
 * Alat ini TIDAK bicara DICOM; tiap pemeriksaan ditulis ke folder GUID di
 * `c:\Compact Touch\Data\` berisi 1 file .jpg (gambar) + 1 file .xml (data).
 * Watcher di sisi RS hanya meneruskan kedua file mentah ke /ingest; SEMUA
 * logika parsing ada di sini (sisi Arumed) supaya bisa di-version & di-test.
 *
 * Output = array terstruktur netral (No.RM, ExamKey, biometri per mata, dan
 * tabel hitung IOL power per formula & per implan/A-constant) yang dipakai
 * PenunjangIngestService untuk mengisi expertise_data + men-seed keputusan IOL.
 *
 * Catatan eye-side: Quantel (Prancis) memakai "Od" = oculus dexter (mata KANAN,
 * OD) dan "Og" = oculus gauche (mata KIRI, OS). Beberapa firmware memakai "Os".
 */
class QuantelXmlParser
{
    public const SOURCE = 'QUANTEL_COMPACT_TOUCH';

    /**
     * Parse string XML mentah → array terstruktur. Mengembalikan null bila bukan
     * XML Quantel yang dikenali (mis. file rusak / tipe lain).
     */
    public function parse(string $xml): ?array
    {
        if (trim($xml) === '') {
            return null;
        }

        $xml = $this->normalizeEncoding($xml);

        // Quantel menulis encoding utf-16; muat aman tanpa melempar warning.
        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);

        if ($doc === false || $doc->getName() !== 'C_Exam') {
            return null;
        }

        $patient = $this->parsePatient($doc->Patient ?? null);

        return [
            'source'    => self::SOURCE,
            'exam_kind' => $this->examKind($doc),   // BIOMETRY | USG | UNKNOWN
            'exam_key'  => $this->str($doc->ExamKey),
            'exam_date' => $this->str($doc->ExamDate),
            'patient'   => $patient,
            'no_rm'     => $patient['id_number'],
            'physician' => $this->str($doc->Physician->PhysicianLastName ?? null),
            'eyes'      => $this->parseEyes($doc),
        ];
    }

    /**
     * Jenis pemeriksaan dari atribut `xsi:type` akar:
     *   C_ExamBioIol → BIOMETRY (A-scan + hitung IOL)
     *   C_ExamB      → USG (B-scan)
     *   selain itu   → UNKNOWN
     * Dipakai ingest untuk mengarahkan ke order yang tepat (Biometri vs USG).
     */
    private function examKind(\SimpleXMLElement $doc): string
    {
        $xsi = $doc->attributes('http://www.w3.org/2001/XMLSchema-instance');
        $type = $xsi !== null ? (string) ($xsi['type'] ?? '') : '';

        return match ($type) {
            'C_ExamBioIol' => 'BIOMETRY',
            'C_ExamB'      => 'USG',
            default        => 'UNKNOWN',
        };
    }

    /**
     * Samakan encoding ke UTF-8 supaya libxml tak menolak file Quantel (yang
     * deklarasinya utf-16, kadang ber-BOM). Konversi byte bila BOM utf-16,
     * lalu tulis ulang atribut encoding di deklarasi XML menjadi UTF-8.
     */
    private function normalizeEncoding(string $xml): string
    {
        $bom2 = substr($xml, 0, 2);
        if ($bom2 === "\xFF\xFE" || $bom2 === "\xFE\xFF") {
            $xml = mb_convert_encoding($xml, 'UTF-8', 'UTF-16');
        }

        // Buang BOM UTF-8 bila ada.
        if (substr($xml, 0, 3) === "\xEF\xBB\xBF") {
            $xml = substr($xml, 3);
        }

        // Paksa deklarasi encoding ke UTF-8 (byte sudah UTF-8 di titik ini).
        $xml = preg_replace(
            '/(<\?xml[^>]*encoding=["\'])[^"\']+(["\'])/i',
            '${1}UTF-8${2}',
            ltrim($xml),
            1
        );

        return $xml;
    }

    private function parsePatient(?\SimpleXMLElement $p): array
    {
        if ($p === null) {
            return ['id_number' => null, 'last_name' => null, 'first_name' => null, 'birth_date' => null];
        }

        return [
            'id_number'  => $this->str($p->PatientIdNumber),
            'last_name'  => $this->str($p->PatientLastName),
            'first_name' => $this->str($p->PatientFirstName),
            'birth_date' => $this->str($p->PatientBirthDate),
        ];
    }

    /**
     * Gabungkan data Bio (ukuran) + Iol (konsolidasi + tabel power) per mata.
     */
    private function parseEyes(\SimpleXMLElement $doc): array
    {
        $eyes = [];

        // Map node-suffix Quantel → kode mata Arumed.
        $sides = ['Od' => 'OD', 'Og' => 'OS', 'Os' => 'OS'];

        foreach ($sides as $suffix => $code) {
            $bioNode = $doc->ExamBio->{"ExamBio{$suffix}"} ?? null;
            $iolNode = $doc->ExamIol->{"ExamIol{$suffix}"} ?? null;

            if ($bioNode === null && $iolNode === null) {
                continue;
            }

            $biometry = $this->parseBiometry($iolNode, $bioNode);
            $iolCalc  = $this->parseIolCalc($iolNode);

            // Lewati mata "hantu": saat hanya satu mata diperiksa, Quantel tetap
            // menulis node ExamBio{side} kosong untuk mata lainnya (tanpa ExamIol).
            // Tanpa filter ini, mata itu masuk dengan SEMUA nilai null → panel
            // menampilkan kolom OD/OS penuh "—". Sertakan hanya bila ada minimal
            // satu nilai biometri inti atau ada hitung IOL.
            $hasCoreBio = array_filter(
                [$biometry['axial_length'], $biometry['acd'], $biometry['lens_thickness'],
                 $biometry['vitreous'], $biometry['k1'], $biometry['k2'], $biometry['kcor']],
                fn ($v) => $v !== null,
            );
            if (! $hasCoreBio && ! $iolCalc) {
                continue;
            }

            $eyes[$code] = [
                'biometry' => $biometry,
                'iol_calc' => $iolCalc,
            ];
        }

        return $eyes;
    }

    /**
     * Nilai biometri inti. Sumber utama = ExamIol{side} (sudah dikonsolidasi:
     * AL=LenghtLt, ACD=LengthAc, lens=LenghtL, vitreous=LenghtV, K dari <Kerato>).
     */
    private function parseBiometry(?\SimpleXMLElement $iol, ?\SimpleXMLElement $bio): array
    {
        $k = $iol->Kerato ?? null;

        return [
            'axial_length'   => $this->num($iol->LenghtLt ?? null),   // AL (mm)
            'acd'            => $this->num($iol->LengthAc ?? null),    // anterior chamber depth (mm)
            'lens_thickness' => $this->num($iol->LenghtL ?? null),    // (mm)
            'vitreous'       => $this->num($iol->LenghtV ?? null),    // (mm)
            'k1'             => $this->num($k->K1Post ?? null),       // dioptri
            'k2'             => $this->num($k->K2Post ?? null),
            'kcor'           => $this->num($k->KCor ?? null),
            'technique'      => $this->str($bio->ExamComment ?? null) ?: $this->str($bio->ProbeName ?? null),
        ];
    }

    /**
     * Tabel hitung IOL: 1 entri per implan/A-constant, masing-masing punya power
     * untuk emetropia + daftar (power → prediksi refraksi) per formula.
     *
     * @return array<int,array>
     */
    private function parseIolCalc(?\SimpleXMLElement $iol): array
    {
        $tab = $iol->ExamIolCalOeilTab ?? null;
        if ($tab === null) {
            return [];
        }

        $out = [];
        foreach ($tab->C_ExamIolCalcOeil as $calc) {
            $implant = $calc->Implant ?? null;

            $results = [];
            foreach (($calc->TabResult->S_IolResult ?? []) as $r) {
                $results[] = [
                    'power'         => $this->num($r->Ame),
                    'predicted_ref' => $this->num($r->Ref),
                    'median'        => $this->bool($r->MedianLine),
                ];
            }

            $out[] = [
                'implant_designation' => $this->str($implant->ImplantDesignation ?? null),
                'a_constant'          => $this->num($implant->A ?? null),
                'implant_type'        => $this->str($implant->TypeImplant ?? null),
                'formula'             => $this->normalizeFormula($this->str($calc->Formula)),
                'emmetropia_power'    => $this->num($calc->Emme ?? $calc->IolAme ?? null),
                'results'             => $results,
            ];
        }

        return $out;
    }

    /** "IolFormulaSrkT" → "SRK/T", dst. Nilai mentah dikembalikan bila tak dikenali. */
    private function normalizeFormula(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $map = [
            'IolFormulaSrkT'  => 'SRK/T',
            'IolFormulaSrk2'  => 'SRK II',
            'IolFormulaHolla' => 'Holladay',
            'IolFormulaHoff'  => 'Hoffer Q',
            'IolFormulaHaigis' => 'Haigis',
            'IolFormulaBink'  => 'Binkhorst',
        ];

        return $map[$raw] ?? $raw;
    }

    private function str($node): ?string
    {
        if ($node === null) {
            return null;
        }
        $v = trim((string) $node);
        return $v === '' ? null : $v;
    }

    private function num($node): ?float
    {
        $v = $this->str($node);
        return ($v === null || !is_numeric($v)) ? null : (float) $v;
    }

    private function bool($node): bool
    {
        return $this->str($node) === 'true';
    }
}
