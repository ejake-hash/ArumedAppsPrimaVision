<?php
/**
 * apply-kfa.php — Tulis kandidat #1 KFA ke DB sebagai DRAFT, dengan filter
 * kewaspadaan bentuk sediaan untuk obat mata.
 *
 * Aturan:
 *   - Hanya proses obat status=MATCH yang kfa_code-nya MASIH KOSONG di DB.
 *   - Kalau nama obat menandakan sediaan MATA (tetes/salep) tapi cand1_form
 *     BUKAN Tetes/Salep Mata → SKIP (mismatch, kemungkinan salah seperti Floxa).
 *     Obat ini dibiarkan kosong → kamu isi/koreksi manual di UI.
 *   - Sisanya: tulis cand1_kfa ke medications.kfa_code.
 *
 * Idempotent: jalan ulang tidak menimpa yang sudah terisi.
 * Output ringkasan + daftar yang di-SKIP (untuk dicek manual).
 *
 * Pakai (dari folder backend):  php ../Docs/kfa-match/apply-kfa.php
 *                               php ../Docs/kfa-match/apply-kfa.php --dry   (simulasi, tak tulis)
 */

require __DIR__ . '/../../backend/vendor/autoload.php';
$app = require __DIR__ . '/../../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Medication;

$dry = in_array('--dry', $argv, true);

$rows = array_map(fn ($l) => str_getcsv($l, ',', '"', '\\'),
    file(__DIR__ . '/kfa-candidates.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$h = array_shift($rows);

// Apakah nama obat menandakan sediaan mata?
function isEyeForm(string $name): bool {
    $n = strtolower($name);
    foreach (['eye drop','eye oint','tetes mata','salep mata','minidose','strip',
              'drops',' ed',' md','oint','ophtal','opth'] as $w) {
        if (str_contains($n, $w)) return true;
    }
    return false;
}
// Apakah cand1_form termasuk sediaan mata?
function isEyeKfaForm(string $form): bool {
    $f = strtolower($form);
    return str_contains($f, 'tetes mata') || str_contains($f, 'salep mata');
}

$applied = 0; $skipMismatch = []; $skipFilled = 0; $skipNoCand = 0;

foreach ($rows as $r) {
    $rr = array_combine($h, $r);
    if ($rr['status'] !== 'MATCH') continue;

    $kfa  = trim($rr['cand1_kfa']);
    $form = trim($rr['cand1_form']);
    if ($kfa === '') { $skipNoCand++; continue; }

    // Cari obat di DB by name (case-insensitive), yang kfa_code masih kosong.
    $med = Medication::whereNull('deleted_at')
        ->whereRaw('LOWER(name) = ?', [strtolower($rr['med_name'])])
        ->where(function ($w) { $w->whereNull('kfa_code')->orWhere('kfa_code', ''); })
        ->first();
    if (! $med) { $skipFilled++; continue; } // sudah terisi atau tak ketemu

    // Filter kewaspadaan: obat mata tapi kandidat #1 bukan sediaan mata → skip.
    if (isEyeForm($rr['med_name']) && ! isEyeKfaForm($form)) {
        $skipMismatch[] = "{$rr['med_name']}  →  #1: {$rr['cand1_name']} ({$form}) [DILEWATI]";
        continue;
    }

    if (! $dry) {
        $med->kfa_code = $kfa;
        $med->save();
    }
    $applied++;
}

echo ($dry ? "[DRY-RUN] " : "") . "=== APPLY KFA (kandidat #1) ===\n";
echo "Ditulis ke DB           : {$applied}\n";
echo "Skip (mata mismatch)    : " . count($skipMismatch) . "  → cek/isi manual di UI\n";
echo "Skip (sudah terisi/tdk ada di DB): {$skipFilled}\n";
echo "Skip (tanpa kandidat)   : {$skipNoCand}\n";

if ($skipMismatch) {
    echo "\n--- Obat mata yang DILEWATI (kandidat #1 bentuk sediaan tak cocok) ---\n";
    foreach ($skipMismatch as $s) echo "  • {$s}\n";
}
