<?php
// Verifikasi E2E ingest Quantel. Jalankan: tail -n +2 tests/Fixtures/verify_quantel_ingest.php | php artisan tinker
// Selalu rollback — tak meninggalkan data di arumed_dev. (Tanpa `use`: tinker sudah auto-alias model & facade.)

Storage::fake('public');
DB::beginTransaction();
try {
    $p = Patient::create(['no_rm' => '018307', 'name' => 'Dolly Test', 'gender' => 'P', 'is_active' => true]);
    $v = Visit::create(['patient_id' => $p->id, 'visit_date' => today(), 'classification' => 'UMUM', 'guarantor_type' => 'UMUM', 'current_station' => 'PENUNJANG']);
    $o = DiagnosticOrder::create(['visit_id' => $v->id, 'test_type' => 'BIOM', 'status' => 'REQUESTED', 'accession_number' => 'ATESTQ1']);

    $xml = file_get_contents(base_path('tests/Fixtures/quantel_sample.xml'));
    $svc = app(\App\Services\PenunjangIngestService::class);

    $r1 = $svc->ingest(\Illuminate\Http\UploadedFile::fake()->image('hasil.jpg'), ['source' => 'QUANTEL_WATCHER', 'xml_content' => $xml]);
    echo "INGEST1: " . json_encode($r1) . "\n";

    $res = DiagnosticResult::where('diagnostic_order_id', $o->id)->first();
    $exp = $res->expertise_data ?? [];
    echo "source            = " . ($exp['source'] ?? '-') . "\n";
    echo "exam_key          = " . ($exp['biometry']['exam_key'] ?? '-') . "\n";
    echo "AL_OD             = " . ($exp['biometry']['eyes']['OD']['biometry']['axial_length'] ?? '-') . "\n";
    echo "K1_OD             = " . ($exp['biometry']['eyes']['OD']['biometry']['k1'] ?? '-') . "\n";
    echo "iol_calc_count_OD = " . count($exp['biometry']['eyes']['OD']['iol_calc'] ?? []) . "\n";
    echo "attachment        = " . ($res->attachment_path ?: '-') . "\n";
    echo "order_status      = " . $o->fresh()->status . "\n";

    $r2 = $svc->ingest(\Illuminate\Http\UploadedFile::fake()->image('hasil.jpg'), ['source' => 'QUANTEL_WATCHER', 'xml_content' => $xml]);
    echo "INGEST2(dup)      = " . json_encode($r2) . "\n";
    echo "result_count      = " . DiagnosticResult::where('diagnostic_order_id', $o->id)->count() . "\n";

    $r3 = $svc->ingest(\Illuminate\Http\UploadedFile::fake()->image('x.jpg'), ['source' => 'QUANTEL_WATCHER', 'no_rm' => '999999', 'external_ref' => 'EXAMKEY-ASING-1']);
    echo "INGEST3(no-match) = " . json_encode($r3) . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " @ " . basename($e->getFile()) . ":" . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "ROLLED BACK (data dev bersih)\n";
}
