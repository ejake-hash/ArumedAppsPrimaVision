<?php
/**
 * match-kfa.php — Batch-match kode KFA Kemenkes untuk master Obat.
 *
 * TUJUAN: untuk tiap obat yang kfa_code-nya kosong, cari kandidat kode KFA via
 * API Satu Sehat (KFA v2), pakai keyword yang DIBERSIHKAN + fallback ke zat aktif.
 * Hasil ditulis ke CSV kandidat untuk DIREVIEW MANUSIA — TIDAK menulis ke DB.
 *
 * SYARAT: Bridging Satu Sehat sudah aktif (Client ID/Secret terisi + is_enabled).
 *   Tanpa itu searchKfa() balikan success=false "belum diaktifkan".
 *
 * CARA PAKAI (dari folder backend):
 *   php ../Docs/kfa-match/match-kfa.php                 # semua obat kfa_code kosong
 *   php ../Docs/kfa-match/match-kfa.php --limit=20      # uji 20 obat dulu
 *   php ../Docs/kfa-match/match-kfa.php --only-active   # hanya obat is_active=1
 *
 * OUTPUT: Docs/kfa-match/kfa-candidates.csv
 *   kolom: med_name, generic_name, composition, keyword_dipakai, status,
 *          cand1_kfa, cand1_name, cand1_form, cand2_kfa, cand2_name, cand2_form, ...
 *   status: MATCH (ada kandidat) | NO_MATCH (API jalan, 0 hasil) | API_ERROR (gagal)
 *
 * Review: buka CSV, untuk tiap baris MATCH pilih kandidat yang BENAR (kekuatan &
 * bentuk sediaan harus pas), salin kfa-nya ke kolom kfa_code di CSV master obat,
 * lalu import ulang. Baris NO_MATCH → cari manual di kfa.kemkes.go.id atau biarkan kosong.
 */

require __DIR__ . '/../../backend/vendor/autoload.php';
$app = require __DIR__ . '/../../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Medication;
use App\Services\SatusehatService;

// ── Args ────────────────────────────────────────────────────────────────────
$limit      = null;
$onlyActive = false;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int) $m[1];
    if ($a === '--only-active') $onlyActive = true;
}

$svc = app(SatusehatService::class);

// ── Pre-flight: pastikan API aktif (uji 1 query murah) ───────────────────────
$probe = $svc->searchKfa('paracetamol');
if (! ($probe['success'] ?? false)) {
    fwrite(STDERR, "API KFA belum bisa dipakai: " . ($probe['message'] ?? 'unknown') . "\n");
    fwrite(STDERR, "Aktifkan Bridging Satu Sehat dulu (Client ID/Secret + is_enabled), lalu ulangi.\n");
    exit(1);
}
echo "Pre-flight OK — API KFA merespons (probe 'paracetamol' = " . count($probe['items']) . " item)\n";

// ── Helper: bersihkan nama jadi keyword pencarian ────────────────────────────
// Buang penanda non-zat: penjamin, kemasan, bentuk lokal, satuan ukuran.
function cleanKeyword(string $name): string {
    $s = ' ' . strtolower($name) . ' ';
    // kata-kata yang memperburuk match di KFA
    $noise = ['bpjs','inhealth','minidose','strip','botol','tube','vial','ampul',
              'md','ed','eye drop','eye drops','tetes mata','salep','oint','ointment',
              'kaplet','kapsul','tablet','tab','syr','syrup','sirup','injeksi','inj',
              'infus','drops','drop','pdf','pen','single dose','respules','nebules','nebu'];
    foreach ($noise as $w) {
        $s = str_replace(' ' . $w . ' ', ' ', $s);
    }
    // buang ukuran/dosis: 500mg, 0,5%, 5ml, 2.5ml, 40mg/ml, 100ml, dst.
    $s = preg_replace('#\b\d+([.,]\d+)?\s*(mg|mcg|ml|cc|g|gr|iu|%|mg/ml|mg/\d+ml)?\b#u', ' ', $s);
    $s = preg_replace('#[()/\-+]#', ' ', $s);     // simbol pemisah
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

// Zat aktif: ambil dari composition (token sebelum koma) lalu generic_name.
function activeIngredient(?string $composition, ?string $generic): string {
    foreach ([$composition, $generic] as $src) {
        $v = trim((string) $src);
        if ($v === '' || strtolower($v) === 'obat' || $v === '-') continue;
        // ambil zat pertama (sebelum koma) — KFA cari per zat lebih akurat
        $first = trim(explode(',', $v)[0]);
        if ($first !== '') return strtolower($first);
    }
    return '';
}

// ── Kumpulkan obat tanpa kfa_code ────────────────────────────────────────────
$q = Medication::query()->whereNull('deleted_at')
    ->where(function ($w) { $w->whereNull('kfa_code')->orWhere('kfa_code', ''); });
if ($onlyActive) $q->where('is_active', true);
$q->orderBy('name');
if ($limit) $q->limit($limit);
$meds = $q->get(['id', 'name', 'generic_name', 'composition']);

echo "Obat tanpa kfa_code: " . $meds->count() . ($limit ? " (dibatasi --limit=$limit)" : '') . "\n";
echo "Mulai query KFA (jeda 250ms antar obat)…\n\n";

// ── Match per obat ───────────────────────────────────────────────────────────
$out = fopen(__DIR__ . '/kfa-candidates.csv', 'w');
$header = ['med_name','generic_name','composition','keyword_dipakai','status'];
for ($i = 1; $i <= 5; $i++) { $header[] = "cand{$i}_kfa"; $header[] = "cand{$i}_name"; $header[] = "cand{$i}_form"; }
fputcsv($out, $header, ',', '"', '\\');

$stat = ['MATCH' => 0, 'NO_MATCH' => 0, 'API_ERROR' => 0];

foreach ($meds as $idx => $med) {
    // Bangun daftar keyword berlapis (urut prioritas), buang duplikat & kosong.
    $candidatesKw = array_values(array_unique(array_filter([
        cleanKeyword($med->name),                                  // 1: nama bersih
        activeIngredient($med->composition, $med->generic_name),   // 2: zat aktif
        strtolower(trim(preg_replace('/\d.*/', '', $med->name))),  // 3: nama tanpa angka
    ], fn ($k) => $k !== '' && strlen($k) >= 3)));

    $items = [];
    $usedKw = '';
    $apiErr = false;

    foreach ($candidatesKw as $kw) {
        $res = $svc->searchKfa($kw);
        if (! ($res['success'] ?? false)) { $apiErr = true; continue; }
        if (! empty($res['items'])) { $items = $res['items']; $usedKw = $kw; break; }
        $usedKw = $kw; // tetap catat keyword terakhir yang dicoba
        usleep(150_000);
    }

    $status = ! empty($items) ? 'MATCH' : ($apiErr && empty($items) ? 'API_ERROR' : 'NO_MATCH');
    $stat[$status]++;

    $rowOut = [$med->name, $med->generic_name, $med->composition, $usedKw, $status];
    for ($k = 0; $k < 5; $k++) {
        $it = $items[$k] ?? null;
        $rowOut[] = $it['kfa_code']    ?? '';
        $rowOut[] = $it['name']        ?? '';
        $rowOut[] = $it['dosage_form'] ?? '';
    }
    fputcsv($out, $rowOut, ',', '"', '\\');

    printf("[%3d/%d] %-45s → %s%s\n",
        $idx + 1, $meds->count(), mb_substr($med->name, 0, 45),
        $status, $usedKw ? " (kw: $usedKw)" : '');

    usleep(250_000); // jeda antar obat — sopan ke API
}

fclose($out);

echo "\n=== SELESAI ===\n";
echo "MATCH     : {$stat['MATCH']}\n";
echo "NO_MATCH  : {$stat['NO_MATCH']}\n";
echo "API_ERROR : {$stat['API_ERROR']}\n";
echo "Output    : Docs/kfa-match/kfa-candidates.csv (review manual sebelum pakai)\n";
