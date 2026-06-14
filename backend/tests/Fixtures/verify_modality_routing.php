<?php
// Verifikasi enhancement modality + routing Quantel. Jalankan: php tests/Fixtures/verify_modality_routing.php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Patient;
use App\Models\Visit;
use App\Models\DiagnosticOrder;
use App\Services\MasterDataService;
use App\Services\AccessionService;
use App\Services\PenunjangIngestService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$acc = app(AccessionService::class);
$svc = app(MasterDataService::class);
$ing = app(PenunjangIngestService::class);
$bioXml = file_get_contents(base_path('tests/Fixtures/quantel_sample.xml'));
$usgXml = file_get_contents(base_path('tests/Fixtures/quantel_usg_sample.xml'));

Storage::fake('public');
DB::beginTransaction();
try {
    // (1) Master modality: buat jenis OCT (OPT) & USG (US)
    $oct = $svc->storeDiagnosticTestType(['code' => 'ZZOCT1', 'name' => 'ZZ OCT Fundus Test', 'modality' => 'OPT']);
    echo "[A] OCT jenis  code={$oct->code} modality={$oct->modality} modalityFor=" . $acc->modalityFor($oct->code) . " (expect OPT)\n";
    $svc->updateDiagnosticTestType($oct->id, ['modality' => null]);
    echo "[A] OCT modality=null -> modalityFor=" . $acc->modalityFor($oct->code) . " (expect OT/fallback)\n";
    $usg = $svc->storeDiagnosticTestType(['code' => 'ZZUSG1', 'name' => 'ZZ USG Mata Test', 'modality' => 'US']);
    echo "[A] USG jenis  code={$usg->code} modalityFor=" . $acc->modalityFor($usg->code) . " (expect US)\n";
    // modality '' (kosong) -> harus jadi null (simulasi ConvertEmptyStringsToNull sudah di HTTP; di service kirim null)
    $empty = $svc->storeDiagnosticTestType(['code' => 'ZZNONE1', 'name' => 'ZZ Tanpa Modalitas', 'modality' => null]);
    echo "[A] jenis tanpa modality -> col=" . var_export($empty->modality, true) . " (expect NULL)\n";

    // (2) Regresi: ingest BIOMETRI cocok ke order BIOM
    $p1 = Patient::firstOrCreate(['no_rm' => '018307'], ['name' => 'ZZ Bio', 'gender' => 'P', 'is_active' => true]);
    $v1 = Visit::create(['patient_id' => $p1->id, 'visit_date' => today(), 'classification' => 'UMUM', 'guarantor_type' => 'UMUM', 'current_station' => 'PENUNJANG']);
    $oB = DiagnosticOrder::create(['visit_id' => $v1->id, 'test_type' => 'BIOM', 'status' => 'REQUESTED', 'accession_number' => $acc->next()]);
    $r1 = $ing->ingest(UploadedFile::fake()->image('b.jpg'), ['source' => 'QUANTEL_WATCHER', 'xml_content' => $bioXml]);
    echo "[B] BIO ingest matched=" . ($r1['matched'] ? 'true' : 'false') . " order=" . ($r1['order_id'] ?? '-') . " (expect order={$oB->id})\n";

    // (3) Routing USG: pasien punya DUA order (BIOM + USG) → exam USG harus pilih order USG
    $p2 = Patient::firstOrCreate(['no_rm' => '260201427'], ['name' => 'ZZ Usg', 'gender' => 'L', 'is_active' => true]);
    $v2 = Visit::create(['patient_id' => $p2->id, 'visit_date' => today(), 'classification' => 'UMUM', 'guarantor_type' => 'UMUM', 'current_station' => 'PENUNJANG']);
    $oB2 = DiagnosticOrder::create(['visit_id' => $v2->id, 'test_type' => 'BIOM', 'status' => 'REQUESTED', 'accession_number' => $acc->next()]);
    $oU2 = DiagnosticOrder::create(['visit_id' => $v2->id, 'test_type' => $usg->code, 'status' => 'REQUESTED', 'accession_number' => $acc->next()]);
    $r2 = $ing->ingest(UploadedFile::fake()->image('u.jpg'), ['source' => 'QUANTEL_WATCHER', 'xml_content' => $usgXml]);
    $hit = $r2['order_id'] ?? null;
    echo "[C] USG ingest matched=" . ($r2['matched'] ? 'true' : 'false') . " order=" . ($hit ?? '-') . "\n";
    echo "[C] -> " . ($hit === $oU2->id ? 'BENAR (order USG)' : ($hit === $oB2->id ? 'SALAH (kena BIOM!)' : 'ke Inbox/none')) . " | USG={$oU2->id} BIOM={$oB2->id}\n";

    // (4) Biometri saat ada 2 order → harus pilih BIOM (pasien p2 dipakai ulang? buat pasien baru)
    $p3 = Patient::firstOrCreate(['no_rm' => '999111'], ['name' => 'ZZ Both', 'gender' => 'P', 'is_active' => true]);
    $v3 = Visit::create(['patient_id' => $p3->id, 'visit_date' => today(), 'classification' => 'UMUM', 'guarantor_type' => 'UMUM', 'current_station' => 'PENUNJANG']);
    $oB3 = DiagnosticOrder::create(['visit_id' => $v3->id, 'test_type' => 'BIOM', 'status' => 'REQUESTED', 'accession_number' => $acc->next()]);
    $oU3 = DiagnosticOrder::create(['visit_id' => $v3->id, 'test_type' => $usg->code, 'status' => 'REQUESTED', 'accession_number' => $acc->next()]);
    // pakai biometri XML tapi override no_rm ke 999111 + ExamKey unik (hindari idempotensi tes)
    $bioXml3 = str_replace('018307', '999111', $bioXml);
    $bioXml3 = str_replace('7a41bd83-3bd7-4c19-b45a-bbe91be93801', '00000000-0000-4000-8000-0000000000d4', $bioXml3);
    $r3 = $ing->ingest(UploadedFile::fake()->image('b3.jpg'), ['source' => 'QUANTEL_WATCHER', 'xml_content' => $bioXml3]);
    $hit3 = $r3['order_id'] ?? null;
    echo "[D] BIO(2 order) matched order=" . ($hit3 ?? '-') . " -> " . ($hit3 === $oB3->id ? 'BENAR (BIOM)' : 'SALAH/Inbox') . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " @ " . basename($e->getFile()) . ":" . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "ROLLED BACK\n";
}
