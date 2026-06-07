<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Generator accession number order penunjang (kunci pencocokan DICOM Worklist/ingest).
 *
 * Format: `A` + base36(nextval) zero-pad 7 → mis. A0000001, A000000A, …
 * Pakai PG sequence (atomik) — JANGAN pola max()+1 yang rawan race.
 * Maks 16 char (batas DICOM AccessionNumber); 7 digit base36 ≈ 78 miliar kapasitas.
 */
class AccessionService
{
    public function next(): string
    {
        $n = (int) DB::selectOne("SELECT nextval('diagnostic_order_accession_seq') AS v")->v;

        return 'A' . str_pad(strtoupper(base_convert((string) $n, 10, 36)), 7, '0', STR_PAD_LEFT);
    }

    /**
     * Peta test-type code → DICOM modality (dari config, editable agar cocok dgn alat).
     * Mis. OCT→OPT, USG→US, default OT.
     */
    public function modalityFor(?string $testTypeCode): string
    {
        $map = (array) config('penunjang_dicom.modality_map', []);

        return $map[$testTypeCode] ?? (string) config('penunjang_dicom.modality_default', 'OT');
    }
}
