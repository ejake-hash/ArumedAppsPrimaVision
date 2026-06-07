<?php
// Verifikasi E2E keputusan IOL dokter — standalone bootstrap.
// Jalankan: php tests/Fixtures/verify_iol_decision.php   (selalu rollback)

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Patient;
use App\Models\Visit;
use App\Models\DiagnosticOrder;
use App\Models\IolItem;
use App\Models\IolRecommendation;
use App\Models\User;
use App\Services\PenunjangIngestService;
use App\Services\DokterService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$su = User::all()->first(fn ($u) => method_exists($u, 'isSuperadmin') && $u->isSuperadmin());
echo "superadmin       = " . ($su ? $su->username : 'NONE') . "\n";
Auth::guard('api')->setUser($su);

Storage::fake('public');
DB::beginTransaction();
try {
    $p = Patient::create(['no_rm' => '018307', 'name' => 'Dolly Test', 'gender' => 'P', 'is_active' => true]);
    $v = Visit::create(['patient_id' => $p->id, 'visit_date' => today(), 'classification' => 'UMUM', 'guarantor_type' => 'UMUM', 'current_station' => 'DOKTER']);
    $o = DiagnosticOrder::create(['visit_id' => $v->id, 'test_type' => 'BIOM', 'status' => 'IN_PROGRESS', 'accession_number' => 'ATESTQ2']);

    $xml = file_get_contents(base_path('tests/Fixtures/quantel_sample.xml'));
    app(PenunjangIngestService::class)
        ->ingest(UploadedFile::fake()->image('h.jpg'), ['source' => 'QUANTEL_WATCHER', 'xml_content' => $xml]);

    $iol = IolItem::create(['brand' => 'Alcon', 'model' => 'SN60WF', 'iol_type' => 'MONOFOCAL', 'power' => 21.5, 'a_constant' => 118.0, 'is_active' => true]);

    $svc = app(DokterService::class);

    $screen = $svc->getBiometriIol($v->id);
    echo "biometry?        = " . ($screen['biometry'] ? 'YES' : 'no') . "\n";
    echo "exam_key         = " . ($screen['biometry']['exam_key'] ?? '-') . "\n";
    echo "OD AL            = " . ($screen['biometry']['eyes']['OD']['biometry']['axial_length'] ?? '-') . "\n";
    echo "OD iol_calc rows = " . count($screen['biometry']['eyes']['OD']['iol_calc'] ?? []) . "\n";
    $hit = collect($screen['iol_masters'])->firstWhere('a_constant', 118.0);
    echo "master A=118 hit = " . ($hit['label'] ?? '-') . "\n";

    $rec = $svc->decideIol($v->id, [
        'eye_side' => 'OD', 'iol_item_id' => $iol->id, 'recommended_power' => 21.5,
        'formula' => 'SRK/T', 'a_constant' => 118.0, 'target_refraction' => 0, 'predicted_refraction' => 0.092,
    ]);
    echo "DECISION saved   = eye {$rec->eye_side}, power {$rec->recommended_power}, formula {$rec->formula}, "
        . "iol_item " . ($rec->iol_item_id ? 'SET' : 'null') . ", is_final " . ($rec->is_final ? '1' : '0')
        . ", brand " . ($rec->brand ?? '-') . ", iol_type " . ($rec->iol_type ?? '-') . "\n";

    $svc->decideIol($v->id, ['eye_side' => 'OD', 'iol_item_id' => $iol->id, 'recommended_power' => 22.0, 'formula' => 'SRK/T']);
    $odRec = IolRecommendation::where('visit_id', $v->id)->where('eye_side', 'OD')->get();
    echo "OD rec count     = " . $odRec->count() . " (power now " . $odRec->first()->recommended_power . ")\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " @ " . basename($e->getFile()) . ":" . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "ROLLED BACK\n";
}
