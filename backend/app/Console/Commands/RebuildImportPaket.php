<?php

namespace App\Console\Commands;

use App\Models\SurgeryPackage;
use App\Models\SurgeryPackageItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import struktur PAKET BEDAH dari "PAKET BEDAH.xlsx" (34 sheet) ke arumed_dev.
 *
 * Tiap sheet = 1 paket bedah. Baris-1-sel = section; section dipetakan ke item_type:
 *   - Administrasi / Perawatan / Tindakan / Sewa Peralatan Medik / Sewa Kamar → PROCEDURE
 *   - Obat Tindakan                                                            → MEDICATION
 *   - Bahan Habis Pakai / CSSD Supplies                                        → BHP
 *  (Tidak ada section IOL di file — IOL dipilih saat operasi, bukan baris paket.)
 *
 * Tiap baris item di-resolve BY-NAMA (exact-normalized) ke master katalog yang
 * sudah diimpor (procedures / medications / bhp_items). Yang TIDAK cocok ditulis
 * ke CSV review (+ saran master terdekat) dan DILEWATI — paket tetap dibuat dengan
 * item yang cocok, total_base_price dihitung dari item yang dibuat.
 *
 * STRUKTUR saja: `price` (harga jual) = 0 → ditetapkan nanti. `default_price` per
 * item = snapshot Harga dari file (HPP), sehingga total_base_price = ΣSub Total file.
 *
 * DEV / REHEARSAL ONLY. Dry-run by default; --force untuk eksekusi. Idempoten
 * (lewati paket yang legacy_uuid-nya sudah ada).
 */
class RebuildImportPaket extends Command
{
    protected $signature = 'rebuild:import-paket
        {--force : Apply (default: dry-run preview only)}
        {--refresh : Sinkronkan ulang item paket yang sudah ada (pertahankan id/code/price jual)}
        {--file= : PAKET BEDAH xlsx path}
        {--alias= : CSV peta terkurasi (item_type,nama_file,master_name) override resolusi}
        {--review= : Path CSV unmatched (default: Docs/migrasi data/paket-REVIEW-unmatched.csv)}';

    protected $description = 'Import struktur paket bedah (34 sheet) dari PAKET BEDAH.xlsx → surgery_packages+items. DEV ONLY.';

    /** Nama section (kolom-0) → item_type. */
    private array $sectionType = [
        'administrasi' => SurgeryPackageItem::TYPE_PROCEDURE,
        'perawatan' => SurgeryPackageItem::TYPE_PROCEDURE,
        'tindakan' => SurgeryPackageItem::TYPE_PROCEDURE,
        'sewa peralatan medik' => SurgeryPackageItem::TYPE_PROCEDURE,
        'sewa kamar' => SurgeryPackageItem::TYPE_PROCEDURE,
        'obat tindakan' => SurgeryPackageItem::TYPE_MEDICATION,
        'bahan habis pakai' => SurgeryPackageItem::TYPE_BHP,
        'cssd supplies' => SurgeryPackageItem::TYPE_BHP,
    ];

    /** norm(name) => ['id'=>, 'name'=>] per item_type. */
    private array $maps = [];

    /** Peta alias terkurasi: [type][norm(nama_file)] => ['id'=>,'name'=>]. */
    private array $alias = [];

    /** Daftar (name,id) per type untuk saran terdekat. */
    private array $cands = [];

    private string $now;

    private string $umumId;

    private array $codeSeq = [];

    public function handle(): int
    {
        $db = DB::connection()->getDatabaseName();
        if (app()->environment('production') || $db === 'arumed_primavision') {
            $this->error("REFUSED: must not run on production / arumed_primavision (db={$db}).");

            return self::FAILURE;
        }

        $file = $this->option('file') ?: base_path('../Docs/migrasi data/PAKET BEDAH.xlsx');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $this->now = now()->toDateTimeString();

        // Insurer sistem UMUM → baris harga jual baseline paket (surgery_package_tariffs).
        // Konvensi: tarif UMUM di insurer UMUM (bukan baris SEMUA/null) → UI-editable.
        $umum = DB::table('insurers')->where('is_system', true)->where('type', 'UMUM')->first();
        if (! $umum) {
            $this->error('Insurer sistem UMUM tidak ditemukan — tidak bisa set harga jual paket.');

            return self::FAILURE;
        }
        $this->umumId = $umum->id;

        $this->buildMasterMaps();
        $this->loadAlias();

        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $ss = $reader->load($file);

        $this->info("DB={$db}  MODE=" . ($force ? 'FORCE' : 'DRY-RUN'));
        $this->info('Master: procedures=' . count($this->cands['PROCEDURE'])
            . '  medications=' . count($this->cands['MEDICATION'])
            . '  bhp=' . count($this->cands['BHP']));
        $this->newLine();

        $unmatched = [];   // baris review
        $totalPkg = 0;
        $totalCreatedPkg = 0;
        $totalItems = 0;
        $totalMatched = 0;

        foreach ($ss->getSheetNames() as $sheetName) {
            $totalPkg++;
            $rows = $ss->getSheetByName($sheetName)->toArray(null, true, false, false);
            $title = trim((string) ($rows[0][0] ?? ''));
            if ($title === '') {
                $this->warn("  [{$sheetName}] judul kosong — dilewati");

                continue;
            }
            $category = $this->categoryOf($sheetName);
            $legacy = 'PAKET_XLSX::' . $sheetName;

            // Parse baris item per section.
            $parsed = $this->parseSheet($rows);

            // Resolve item ke master.
            $items = [];
            $matched = 0;
            foreach ($parsed as $line) {
                $type = $line['type'];
                $hit = $this->resolve($type, $line['name']);
                // Fallback: sebagian IOL ditaruh di section "Bahan Habis Pakai" pada file —
                // bila tak ada di bhp_items tapi cocok iol_items, perlakukan sebagai IOL.
                if (! $hit && $type === SurgeryPackageItem::TYPE_BHP) {
                    $iol = $this->resolve(SurgeryPackageItem::TYPE_IOL, $line['name']);
                    if ($iol) {
                        $type = SurgeryPackageItem::TYPE_IOL;
                        $hit = $iol;
                    }
                }
                if ($hit) {
                    $items[] = [
                        'item_type' => $type,
                        'item_id' => $hit['id'],
                        'quantity' => $line['qty'],
                        'default_price' => $line['harga'],
                        'notes' => $this->norm($hit['name']) === $this->norm($line['name']) ? null : ('file: ' . $line['name']),
                    ];
                    $matched++;
                } else {
                    [$sugName, $sugScore] = $this->closest($type, $line['name']);
                    $unmatched[] = [
                        $sheetName, $title, $line['section'], $type, $line['name'],
                        $line['qty'], $line['harga'], $sugName, $sugScore,
                    ];
                }
            }
            $totalItems += count($parsed);
            $totalMatched += $matched;

            // Dedupe per (type,item_id) — UNIQUE(package,type,item). Bila dua baris file
            // me-resolve ke master yang sama, jumlahkan qty (harga satuan diasumsikan sama).
            $items = $this->dedupeItems($items);

            // total_base_price & harga jual baseline = Σ Sub Total SEMUA baris file (cocok +
            // unmatched) → LENGKAP & independen dari coverage matching. (matched-only di-skip:
            // tak dipakai krn akan understate harga; lihat plan.)
            $fullTotal = 0;
            foreach ($parsed as $line) {
                $fullTotal += $line['subtotal'];
            }

            $existing = DB::table('surgery_packages')->where('legacy_uuid', $legacy)->first();
            $refresh = (bool) $this->option('refresh');
            if ($existing && ! $refresh) {
                $this->line(sprintf(
                    '  %-32s %-9s items %2d/%-2d  harga Rp %s  [%s]',
                    $sheetName, 'SKIP(ada)', $matched, count($parsed), number_format($fullTotal), $category
                ));

                continue;
            }
            $tag = $existing ? 'REFRESH' : 'NEW';
            $this->line(sprintf(
                '  %-32s %-9s items %2d/%-2d  harga Rp %s  [%s]',
                $sheetName, $tag, $matched, count($parsed), number_format($fullTotal), $category
            ));
            $totalCreatedPkg++;

            if ($force) {
                if ($existing) {
                    // Refresh: sinkronkan ulang item & total (struktur), pertahankan identitas
                    // paket (id/code). total_base_price & price = total komponen penuh (list price).
                    $pid = $existing->id;
                    DB::table('surgery_package_items')->where('surgery_package_id', $pid)->delete();
                    DB::table('surgery_packages')->where('id', $pid)->update([
                        'name' => $title,
                        'category' => $category,
                        'surgery_type' => $this->surgeryType($title, $category),
                        'price' => $fullTotal,
                        'total_base_price' => $fullTotal,
                        'updated_at' => $this->now,
                    ]);
                } else {
                    $pid = $this->ins('surgery_packages', [
                        'legacy_uuid' => $legacy,
                        'name' => $title,
                        'code' => $this->nextCode(),
                        'package_type' => SurgeryPackage::TYPE_BEDAH,
                        'category' => $category,
                        'surgery_type' => $this->surgeryType($title, $category),
                        'description' => null,
                        'price' => $fullTotal,
                        'total_base_price' => $fullTotal,
                        'is_active' => true,
                    ]);
                }
                foreach ($items as $it) {
                    $this->ins('surgery_package_items', array_merge($it, [
                        'surgery_package_id' => $pid,
                    ]));
                }
                // Harga jual baseline UMUM = Σ Sub Total komponen (no diskon bundel). Membuat
                // paket AMAN di Kasir: discount = basis − sell ≤ 0 → tak ada baris DISKON_PAKET
                // (paket TANPA tarif = sell 0 → diskon 100%). discount_percent NULL agar
                // recalcTotalBasePrice tak menimpa sell_price. Editable/override di UI nanti.
                $this->upsertUmumTariff($pid, $fullTotal);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Sheets=%d  paket diproses=%d (SKIP ada=%d)  item lines=%d  matched=%d  unmatched=%d',
            $totalPkg, $totalCreatedPkg, $totalPkg - $totalCreatedPkg, $totalItems,
            $totalMatched, count($unmatched)
        ));

        if ($unmatched) {
            $reviewPath = $this->option('review') ?: base_path('../Docs/migrasi data/paket-REVIEW-unmatched.csv');
            $this->writeReview($reviewPath, $unmatched);
            $this->warn('Unmatched ditulis ke: ' . $reviewPath);
        }

        if (! $force) {
            $this->newLine();
            $this->warn('DRY-RUN — belum ada perubahan. Jalankan dgn --force untuk eksekusi.');
        }

        return self::SUCCESS;
    }

    // ── Parsing ──────────────────────────────────────────────────────────────

    /** @return list<array{section:string,type:string,name:string,qty:int,harga:int}> */
    private function parseSheet(array $rows): array
    {
        $out = [];
        $curSection = null;
        $curType = null;
        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $c0 = trim((string) ($r[0] ?? ''));
            $c1 = trim((string) ($r[1] ?? ''));
            $c2 = trim((string) ($r[2] ?? ''));
            $c3 = trim((string) ($r[3] ?? ''));
            if ($c0 === '' && $c1 === '' && $c2 === '' && $c3 === '') {
                continue;
            }
            if (in_array(strtolower($c0), ['deskripsi', 'total', 'sub total', 'subtotal', 'grand total'], true)) {
                continue; // header / baris total — bukan item
            }
            // Section header: hanya kolom-0 terisi.
            if ($c0 !== '' && $c1 === '' && $c2 === '' && $c3 === '') {
                $key = strtolower($c0);
                $curSection = $c0;
                $curType = $this->sectionType[$key] ?? null;
                if (! $curType) {
                    $this->warn("    section tak dikenal: '{$c0}' — item di bawahnya dilewati");
                }

                continue;
            }
            // Item row.
            if ($c0 !== '' && $curType) {
                $qty = max(1, (int) preg_replace('/[^0-9]/', '', $c1) ?: 1);
                $harga = (int) preg_replace('/[^0-9]/', '', $c2);
                $sub = (int) preg_replace('/[^0-9]/', '', $c3);
                $out[] = [
                    'section' => $curSection,
                    'type' => $curType,
                    'name' => $c0,
                    'qty' => $qty,
                    'harga' => $harga,
                    // Sub Total file (kolom-3) = harga bundel komponen; fallback qty×harga.
                    'subtotal' => $sub ?: ($qty * $harga),
                ];
            }
        }

        return $out;
    }

    // ── Resolusi master ──────────────────────────────────────────────────────

    private function buildMasterMaps(): void
    {
        foreach (['PROCEDURE', 'MEDICATION', 'BHP', 'IOL'] as $t) {
            $this->maps[$t] = [];
            $this->cands[$t] = [];
        }
        foreach (DB::table('procedures')->select('id', 'name')->get() as $p) {
            $this->addMaster('PROCEDURE', $p->id, $p->name);
        }
        foreach (DB::table('medications')->select('id', 'name')->get() as $m) {
            $this->addMaster('MEDICATION', $m->id, $m->name);
        }
        foreach (DB::table('bhp_items')->select('id', 'name')->get() as $b) {
            $this->addMaster('BHP', $b->id, $b->name);
        }
        foreach (DB::table('iol_items')->select('id', 'brand')->get() as $i) {
            $this->addMaster('IOL', $i->id, $i->brand);
        }
    }

    private function addMaster(string $type, string $id, string $name): void
    {
        $k = $this->norm($name);
        if (! isset($this->maps[$type][$k])) {
            $this->maps[$type][$k] = ['id' => $id, 'name' => $name];
        }
        $this->cands[$type][] = ['id' => $id, 'name' => $name, 'norm' => $k];
    }

    private function resolve(string $type, string $name): ?array
    {
        $k = $this->norm($name);

        return $this->alias[$type][$k] ?? $this->maps[$type][$k] ?? null;
    }

    /**
     * Muat peta alias terkurasi (item_type,nama_file,master_name). master_name
     * di-resolve ke master via exact-normalized; baris yg master-nya tak ketemu
     * di-warn & dilewati. Kolom 'SKIP' / kosong di master_name = sengaja diabaikan.
     */
    private function loadAlias(): void
    {
        $path = $this->option('alias');
        if (! $path) {
            return;
        }
        if (! is_file($path)) {
            $this->error("Alias CSV not found: {$path}");

            return;
        }
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh); // buang header
        $loaded = 0;
        $missing = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $type = strtoupper(trim((string) ($row[0] ?? '')));
            $fileName = trim((string) ($row[1] ?? ''));
            $masterName = trim((string) ($row[2] ?? ''));
            if ($type === '' || $fileName === '' || $masterName === '' || strtoupper($masterName) === 'SKIP') {
                continue;
            }
            $hit = $this->maps[$type][$this->norm($masterName)] ?? null;
            if (! $hit) {
                $this->warn("    alias: master '{$masterName}' ({$type}) tak ditemukan — dilewati");
                $missing++;

                continue;
            }
            $this->alias[$type][$this->norm($fileName)] = $hit;
            $loaded++;
        }
        fclose($fh);
        $this->info("Alias dimuat: {$loaded}" . ($missing ? "  (master tak ketemu: {$missing})" : ''));
    }

    /** Gabung item dengan (item_type,item_id) sama; qty dijumlah. */
    private function dedupeItems(array $items): array
    {
        $by = [];
        foreach ($items as $it) {
            $k = $it['item_type'] . '|' . $it['item_id'];
            if (isset($by[$k])) {
                $by[$k]['quantity'] += $it['quantity'];
            } else {
                $by[$k] = $it;
            }
        }

        return array_values($by);
    }

    /** Saran master terdekat (similar_text %). @return array{0:string,1:int} */
    private function closest(string $type, string $name): array
    {
        $q = $this->norm($name);
        $best = '';
        $bestScore = 0;
        foreach ($this->cands[$type] as $c) {
            similar_text($q, $c['norm'], $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $best = $c['name'];
            }
        }

        return [$best, (int) round($bestScore)];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Normalisasi nama untuk pencocokan (selaras RebuildImportObat::norm). */
    private function norm(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = str_replace(',', '.', $s);
        $s = preg_replace('/\b(bpjs|inhealth)\b/u', ' ', $s);
        $s = preg_replace('/\b(tab|tablet|kaplet|caplet|kapsul|cap|inj|injeksi|syrup|sirup|drops|drop|nebule|nebules|amp|ampul)\b/u', ' ', $s);
        $s = preg_replace('/\s*(mg|ml|mcg|gr|g|iu)\b/u', '$1', $s);
        $s = preg_replace('/[^a-z0-9.\/%+]+/u', ' ', $s);
        $s = preg_replace('/\s*\/\s*/', '/', $s);
        $s = preg_replace('/\s+%/', '%', $s);
        $s = preg_replace('/\s+/', ' ', trim($s));

        return $s;
    }

    /** Sheet name → category bersih (buang angka romawi, perbaiki typo KORNERA). */
    private function categoryOf(string $sheet): string
    {
        $c = preg_replace('/\s+[IVX]+$/i', '', trim($sheet));
        $c = preg_replace('/^KORNERA\b/i', 'KORNEA', $c);

        return $c;
    }

    private function surgeryType(string $title, string $category): ?string
    {
        $guess = SurgeryPackage::suggestSurgeryType($title);
        if ($guess) {
            return $guess;
        }
        $cat = strtoupper($category);
        if (str_contains($cat, 'RETINA')) {
            return 'VITREORETINA';
        }
        if (str_contains($cat, 'KATARAK')) {
            return 'KATARAK';
        }
        if (str_contains($cat, 'GLAUCOMA') || str_contains($cat, 'GLAUKOMA')) {
            return 'GLAUKOMA';
        }

        return 'LAINNYA';
    }

    private function nextCode(): string
    {
        $prefix = 'PKTB';
        $this->codeSeq[$prefix] = $this->codeSeq[$prefix] ?? 0;
        do {
            $this->codeSeq[$prefix]++;
            $code = $prefix . '-' . str_pad((string) $this->codeSeq[$prefix], 3, '0', STR_PAD_LEFT);
        } while (DB::table('surgery_packages')->where('code', $code)->exists());

        return $code;
    }

    private function ins(string $table, array $data): string
    {
        $id = (string) Str::orderedUuid();
        DB::table($table)->insert(array_merge($data, [
            'id' => $id, 'created_at' => $this->now, 'updated_at' => $this->now,
        ]));

        return $id;
    }

    /**
     * Upsert baris tarif paket UMUM (idempoten by UNIQUE(package,insurer)). Revive bila
     * baris soft-deleted (unique tak mengindahkan deleted_at). discount_percent SENGAJA
     * NULL (mode nominal) agar recalcTotalBasePrice tak menimpa sell_price.
     */
    private function upsertUmumTariff(string $packageId, int $sellPrice): void
    {
        $existing = DB::table('surgery_package_tariffs')
            ->where('surgery_package_id', $packageId)
            ->where('insurer_id', $this->umumId)
            ->first();
        if ($existing) {
            DB::table('surgery_package_tariffs')->where('id', $existing->id)->update([
                'sell_price' => $sellPrice,
                'discount_percent' => null,
                'is_active' => true,
                'deleted_at' => null,
                'updated_at' => $this->now,
            ]);

            return;
        }
        $this->ins('surgery_package_tariffs', [
            'surgery_package_id' => $packageId,
            'insurer_id' => $this->umumId,
            'sell_price' => $sellPrice,
            'discount_percent' => null,
            'is_active' => true,
        ]);
    }

    private function writeReview(string $path, array $rows): void
    {
        $fh = fopen($path, 'w');
        fputcsv($fh, ['sheet', 'paket', 'section', 'item_type', 'nama_file', 'qty', 'harga', 'SARAN_master_terdekat', 'kemiripan_%']);
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);
    }
}
