<?php

namespace App\Console\Commands;

use App\Models\BhpItem;
use App\Models\Insurer;
use App\Models\InventoryPrice;
use App\Models\InventoryPriceSetting;
use App\Models\Medication;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Procedure;
use App\Models\ProcedureTariff;
use App\Models\SurgeryPackage;
use App\Models\SurgeryPackageTariff;
use App\Models\Visit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * GELOMBANG-2 — Migrasi master harga/tarif/paket/resep Prima Vision → Arumed.
 *
 * Sumber  : CSV gzip di `Docs/migrasi data/csv2/*.csv.gz` (streaming, tak butuh restore).
 * Target  : DB Arumed aktif (medications, bhp_items, inventory_prices, procedures,
 *           procedure_tariffs, surgery_packages(+tariffs), prescriptions(+items)).
 *
 * ⚠️ WAJIB DIJALANKAN SETELAH Gelombang-1 (`migrasi:primavision`) sudah commit penuh:
 *   - procedure_tariffs butuh `insurers` hasil Gel-1 (carabayar+asuransi, ~236 penjamin).
 *   - prescriptions butuh `visits.legacy_uuid` hasil Gel-1 (99,9% resep match).
 * Command ini warm-cache insurer & visit dari DB (bukan dari CSV), jadi Gel-1 harus jalan dulu.
 *
 * Idempotent: updateOrCreate by legacy_uuid → aman re-run. Matching master = exact by-name
 * (case-insensitive), sesuai keputusan plan #8.
 *
 * Keputusan mapping FINAL (lihat Docs/migrasi data/PLAN-MIGRASI-GABUNGAN.md):
 *  - obat jenis=Obat → medications; Alkes→bhp(MEDICAL_SUPPLIES), Bhp→bhp(MEDICAL_BHP), CSSD→INSTRUMENT_SET.
 *  - harga_obat → inventory_prices (item_type MEDICATION/BHP, klasifikasi by-name ke master obat).
 *  - base_price procedures = buku_tarif by-name → fallback tarif Umum carabayar (39% tetap 0).
 *  - kategori procedures = label buku_tarif → fallback nama sumber.
 *  - carabayar_tindakan_rawat_jalan → procedure_tariffs (skip 'eksekutif', alias 3 penjamin).
 *  - paket_bedah → surgery_packages + tarif UMUM (skip total<=0 / nama kosong).
 *  - resep → prescriptions(+items): group registrasi+tgl+dokter; signa→frequency+route; is_bedah; skip yatim.
 *
 * Contoh:
 *   php artisan migrasi:primavision-master --dry-run
 *   php artisan migrasi:primavision-master --only=medications,bhp,harga
 *   php -d memory_limit=1024M artisan migrasi:primavision-master
 */
class MigratePrimaVisionMaster extends Command
{
    protected $signature = 'migrasi:primavision-master
                            {--only= : Langkah dipisah koma (medications,bhp,harga,procedures,tarif,paket,resep). Default: semua}
                            {--dry-run : Hitung & validasi tanpa menulis ke DB}
                            {--limit=0 : Batasi N baris per tabel (uji sample). 0 = semua}';

    protected $description = 'Migrasi Gel-2 master harga/tarif/paket/resep Prima Vision (CSV gzip) ke Arumed. WAJIB setelah Gel-1.';

    private string $csvDir = '';
    private bool $dry = false;
    private int $limit = 0;
    private float $ppnRate = 11.0;

    /** Lookup cache. */
    private array $insurerByName = [];     // lower(name) => insurer id
    private array $medByName = [];         // lower(name) => medication id
    private array $bhpByName = [];         // lower(name) => bhp id
    private array $procByName = [];        // lower(name) => procedure id
    private array $pkgByName = [];         // lower(name) => surgery_package id
    private array $visitByLegacy = [];     // registrasi_uuid => visit id
    private array $employeeByLegacy = [];  // dokter uuid => employee id

    /** Klasifikasi master obat by-name: lower(nama) => 'obat'|'alkes'|'bhp' (dari obat.csv). */
    private array $obatJenisByName = [];

    /** Fallback DPJP (prescriptions.prescribed_by_id NOT NULL) bila dokter sumber tak ter-resolve. */
    private ?string $fallbackDpjp = null;

    /** Alias penjamin sumber → nama insurer Arumed (lihat plan: 3 alias manual). */
    private array $insurerAlias = [
        'bpjs ketenagakerjaan'             => 'bpjs tenaga kerja',
        'lonsum'                           => 'pt.pp.london sumatera indonesia tbk.',
        'pt.pp.london sumatera utara tbk.' => 'pt.pp.london sumatera indonesia tbk.',
    ];

    public function handle(): int
    {
        $candidates = [
            base_path('Docs/migrasi data/csv2'),
            dirname(base_path()) . '/Docs/migrasi data/csv2',
        ];
        foreach ($candidates as $c) {
            if (is_dir($c)) { $this->csvDir = $c; break; }
        }
        if ($this->csvDir === '') {
            $this->error('Folder CSV2 tidak ditemukan. Dicari di: ' . implode(' | ', $candidates));
            return self::FAILURE;
        }
        $this->dry = (bool) $this->option('dry-run');
        $this->limit = (int) $this->option('limit');
        $this->line("CSV dir: {$this->csvDir}");
        if ($this->dry) $this->warn('=== DRY RUN — tidak ada penulisan ke DB ===');

        $only = $this->option('only')
            ? array_map('trim', explode(',', (string) $this->option('only')))
            : ['medications', 'bhp', 'harga', 'procedures', 'tarif', 'paket', 'resep'];

        $this->warmCaches();
        $this->guardGel1();

        try {
            foreach ($only as $step) {
                match ($step) {
                    'medications' => $this->migrateMedications(),
                    'bhp'         => $this->migrateBhp(),
                    'harga'       => $this->migrateHarga(),
                    'procedures'  => $this->migrateProcedures(),
                    'tarif'       => $this->migrateTarif(),
                    'paket'       => $this->migratePaket(),
                    'resep'       => $this->migrateResep(),
                    default       => $this->warn("Lewati langkah tak dikenal: {$step}"),
                };
            }
        } catch (\Throwable $e) {
            $this->error("GAGAL: {$e->getMessage()}");
            $this->line($e->getFile() . ':' . $e->getLine());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info($this->dry ? 'Dry run Gel-2 selesai.' : 'Migrasi Gel-2 selesai.');
        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────── caches & guard

    private function warmCaches(): void
    {
        foreach (Insurer::query()->get(['id', 'name']) as $i) {
            $this->insurerByName[mb_strtolower($i->name)] = $i->id;
        }
        foreach (Medication::query()->get(['id', 'name']) as $m) {
            $this->medByName[mb_strtolower($m->name)] = $m->id;
        }
        foreach (BhpItem::query()->get(['id', 'name']) as $b) {
            $this->bhpByName[mb_strtolower($b->name)] = $b->id;
        }
        foreach (Procedure::query()->get(['id', 'name']) as $p) {
            $this->procByName[mb_strtolower($p->name)] = $p->id;
        }
        foreach (SurgeryPackage::query()->get(['id', 'name']) as $p) {
            $this->pkgByName[mb_strtolower($p->name)] = $p->id;
        }
        foreach (Visit::query()->whereNotNull('legacy_uuid')->get(['id', 'legacy_uuid']) as $v) {
            $this->visitByLegacy[$v->legacy_uuid] = $v->id;
        }
        // PPN aktif dari setting (default 11%).
        $setting = InventoryPriceSetting::query()->first();
        if ($setting) $this->ppnRate = (float) $setting->ppn_rate;

        // Klasifikasi master obat by-name (untuk inventory_prices & resep).
        foreach ($this->readCsv('obat') as $r) {
            $this->obatJenisByName[mb_strtolower(trim((string) $r['nama']))] =
                mb_strtolower(trim((string) ($r['jenis'] ?? '')));
        }

        // employee by legacy (dokter) — opsional; resep dokter_uuid → prescribed_by.
        if (DB::getSchemaBuilder()->hasColumn('employees', 'legacy_uuid')) {
            foreach (DB::table('employees')->whereNotNull('legacy_uuid')->get(['id', 'legacy_uuid']) as $e) {
                $this->employeeByLegacy[$e->legacy_uuid] = $e->id;
            }
        }
        // Fallback DPJP utk prescriptions.prescribed_by_id (NOT NULL): dokter pertama, lalu employee mana pun.
        $this->fallbackDpjp = DB::table('employees')
                ->where('profession', 'like', '%okter%')->value('id')
            ?? DB::table('employees')->value('id');
    }

    /** Peringatkan bila Gel-1 belum jalan (insurer cuma sistem / visit kosong). */
    private function guardGel1(): void
    {
        $insurerCount = count($this->insurerByName);
        $visitCount = count($this->visitByLegacy);
        if ($insurerCount <= 5) {
            $this->warn("⚠️  Hanya {$insurerCount} insurer terdeteksi — Gel-1 (insurers) tampaknya BELUM jalan. "
                . "procedure_tariffs akan banyak tak ter-match. Jalankan `migrasi:primavision` dulu.");
        }
        if ($visitCount === 0) {
            $this->warn('⚠️  0 visit dgn legacy_uuid — Gel-1 (visits) BELUM jalan. SEMUA resep akan jadi yatim & di-skip.');
        } else {
            $this->line("  insurer: {$insurerCount} · visit(legacy): {$visitCount} · ppn: {$this->ppnRate}%");
        }
    }

    // ───────────────────────────────────────────────────────── 1. medications

    private function migrateMedications(): void
    {
        $this->newLine();
        $this->info('▶ medications (obat jenis=Obat)');
        $created = 0; $skippedExist = 0; $skippedOther = 0; $n = 0;

        $rows = iterator_to_array($this->readCsv('obat'));
        $bar = $this->output->createProgressBar(count($rows));
        foreach ($rows as $r) {
            if ($this->limit && $n >= $this->limit) { $bar->finish(); break; }
            $n++;
            $bar->advance();

            $jenis = mb_strtolower(trim((string) ($r['jenis'] ?? '')));
            // jenis kosong "-" → default obat (mis. "Ranitidine Tablet"); Alkes/Bhp ke step bhp.
            if ($jenis !== 'obat' && $jenis !== '' && $jenis !== '-') { $skippedOther++; continue; }

            $name = $this->clean($r['nama']);
            if ($name === null) { $skippedOther++; continue; }
            $lname = mb_strtolower($name);

            $konversi = max(1, (int) ($r['hitung_kecil'] ?? 1));
            $data = [
                'name'        => $this->truncate($name, 255),
                'unit'        => $this->truncate($this->clean($r['nama_satuan_kecil'] ?? null), 50) ?? 'Pcs',
                'unit_besar'  => $this->truncate($this->clean($r['nama_satuan_besar'] ?? null), 50),
                'unit_kecil'  => $this->truncate($this->clean($r['nama_satuan_kecil'] ?? null), 50),
                'konversi'    => $konversi,
                'golongan'    => $this->truncate($this->clean($r['golongan'] ?? null), 100),
                // formularium NOT NULL — sumber kosong "-" → default NON-FORNAS (selaras data existing).
                'formularium' => $this->truncate($this->clean($r['formularium'] ?? null), 100) ?? 'NON-FORNAS',
                'min_stock'   => (int) ($r['min_stock'] ?? 0),
                'stock'       => 0,                 // stok manual / opname, tidak dari migrasi
                'price'       => 0,                 // harga via inventory_prices (step harga)
                'is_active'   => ((string) ($r['delete_soft'] ?? '1')) === '1',
            ];

            if (! $this->dry) {
                $med = $this->upsertByLegacy(Medication::class, $r['uuid'], $data, 'MED');
                $this->medByName[$lname] = $med->id;
            } else {
                $this->medByName[$lname] = $this->medByName[$lname] ?? 'dry';
            }
            $created++;
        }
        $bar->finish();
        $this->newLine();
        $this->line("  dibuat/diupdate: {$created} · dilewati (Alkes/Bhp→step bhp): {$skippedOther} · sudah ada: {$skippedExist}");
    }

    // ───────────────────────────────────────────────────────────────── 2. bhp

    private function migrateBhp(): void
    {
        $this->newLine();
        $this->info('▶ bhp_items (obat jenis=Alkes/Bhp/CSSD)');
        $created = 0; $skipped = 0; $n = 0;

        $rows = iterator_to_array($this->readCsv('obat'));
        $bar = $this->output->createProgressBar(count($rows));
        foreach ($rows as $r) {
            if ($this->limit && $n >= $this->limit) { $bar->finish(); break; }
            $n++;
            $bar->advance();

            $jenis = mb_strtolower(trim((string) ($r['jenis'] ?? '')));
            $category = $this->mapBhpCategory($jenis);
            if ($category === null) { $skipped++; continue; } // 'obat'/kosong → step medications

            $name = $this->clean($r['nama']);
            if ($name === null) { $skipped++; continue; }
            $lname = mb_strtolower($name);

            $data = [
                'name'      => $this->truncate($name, 255),
                'category'  => $category,
                'unit'      => $this->truncate($this->clean($r['nama_satuan_kecil'] ?? null), 50) ?? 'Pcs',
                'min_stock' => (int) ($r['min_stock'] ?? 0),
                'stock'     => 0,
                'price'     => 0,
                'is_active' => ((string) ($r['delete_soft'] ?? '1')) === '1',
            ];

            if (! $this->dry) {
                $bhp = $this->upsertByLegacy(BhpItem::class, $r['uuid'], $data, 'BHP');
                $this->bhpByName[$lname] = $bhp->id;
            } else {
                $this->bhpByName[$lname] = $this->bhpByName[$lname] ?? 'dry';
            }
            $created++;
        }
        $bar->finish();
        $this->newLine();
        $this->line("  dibuat/diupdate: {$created} · dilewati (Obat→step medications): {$skipped}");
    }

    private function mapBhpCategory(string $jenis): ?string
    {
        return match ($jenis) {
            'alkes' => BhpItem::CATEGORY_MEDICAL_SUPPLIES,
            'bhp'   => BhpItem::CATEGORY_MEDICAL_BHP,
            'cssd'  => BhpItem::CATEGORY_INSTRUMENT_SET,
            default => null,
        };
    }

    // ──────────────────────────────────────────────────────────── 3. harga

    private function migrateHarga(): void
    {
        $this->newLine();
        $this->info('▶ inventory_prices (harga_obat → MEDICATION/BHP)');
        $created = 0; $skipped = 0; $n = 0;

        $rows = iterator_to_array($this->readCsv('harga_obat'));
        $bar = $this->output->createProgressBar(count($rows));
        foreach ($rows as $r) {
            if ($this->limit && $n >= $this->limit) { $bar->finish(); break; }
            $n++;
            $bar->advance();

            $name = $this->clean($r['nama']);
            if ($name === null) { $skipped++; continue; }
            $lname = mb_strtolower($name);

            // Klasifikasi by-name ke master obat (keputusan #8). jenis kosong → MEDICATION.
            $jenis = $this->obatJenisByName[$lname] ?? 'obat';
            $isMed = ($jenis === 'obat' || $jenis === '' || $jenis === '-');

            $itemType = $isMed ? InventoryPrice::TYPE_MEDICATION : InventoryPrice::TYPE_BHP;
            $itemId   = $isMed ? ($this->medByName[$lname] ?? null) : ($this->bhpByName[$lname] ?? null);
            if ($itemId === null || $itemId === 'dry') {
                // Master belum ada (step medications/bhp belum jalan, atau dry-run) → skip senyap di dry.
                if (! $this->dry) { $skipped++; continue; }
                $skipped++;
                continue;
            }

            $hpp    = (float) ($r['hpp'] ?? 0);
            $margin = (float) ($r['margin_resep'] ?? 0);
            $ppnOn  = (float) ($r['harga_netto_ppn'] ?? 0) > 0;
            $hja    = (float) ($r['hja_resep'] ?? 0);
            if ($hja <= 0) $hja = InventoryPrice::computeHja($hpp, $margin, $ppnOn, $this->ppnRate);

            $data = [
                'hpp'            => round($hpp, 2),
                'margin_percent' => round($margin, 2),
                'ppn_enabled'    => $ppnOn,
                'hja'            => round($hja, 2),
                'notes'          => 'migrasi prima vision',
            ];

            if (! $this->dry) {
                InventoryPrice::updateOrCreate(
                    ['item_type' => $itemType, 'item_id' => $itemId],
                    $data
                );
            }
            $created++;
        }
        $bar->finish();
        $this->newLine();
        $this->line("  dibuat/diupdate: {$created} · dilewati (master tak ada / kosong): {$skipped}");
    }

    // ─────────────────────────────────────────────────────────── 4. procedures

    private function migrateProcedures(): void
    {
        $this->newLine();
        $this->info('▶ procedures (tindakan_rawat_jalan + bedah + non_bedah)');

        // buku_tarif: nama → [harga, label] untuk base_price & kategori.
        $bukuHarga = []; $bukuLabel = [];
        foreach ($this->readCsv('buku_tarif') as $r) {
            $nm = mb_strtolower(trim((string) $r['nama']));
            $bukuHarga[$nm] = (float) ($r['harga'] ?? 0);
            $bukuLabel[$nm] = $this->clean($r['label'] ?? null);
        }
        // tarif Umum carabayar: nama → harga (fallback base_price).
        $umumHarga = [];
        foreach ($this->readCsv('carabayar_tindakan_rawat_jalan') as $r) {
            if (mb_strtolower(trim((string) $r['carabayar_nama'])) !== 'umum') continue;
            $umumHarga[mb_strtolower(trim((string) $r['nama_tindakan_rawat_jalan']))] = (float) ($r['harga'] ?? 0);
        }

        $sources = [
            'tindakan_rawat_jalan' => 'Rawat Jalan',
            'tindakan_bedah'       => 'Bedah',
            'tindakan_non_bedah'   => 'Non Bedah',
        ];
        $created = 0; $skipped = 0; $withPrice = 0; $n = 0;

        foreach ($sources as $file => $fallbackCat) {
            foreach ($this->readCsv($file) as $r) {
                if ($this->limit && $n >= $this->limit) break 2;
                $n++;

                $name = $this->clean($r['nama']);
                if ($name === null) { $skipped++; continue; }
                $lname = mb_strtolower($name);

                $base = $bukuHarga[$lname] ?? ($umumHarga[$lname] ?? 0);
                if ($base > 0) $withPrice++;
                $category = $bukuLabel[$lname] ?? $fallbackCat;

                $data = [
                    'name'       => $this->truncate($name, 255),
                    'category'   => $this->truncate($category, 100),
                    'base_price' => round($base, 2),
                    'is_active'  => ((string) ($r['delete_soft'] ?? '1')) === '1',
                ];

                if (! $this->dry) {
                    $proc = $this->upsertByLegacy(Procedure::class, $r['uuid'], $data, 'TND');
                    $this->procByName[$lname] = $proc->id;
                } else {
                    $this->procByName[$lname] = $this->procByName[$lname] ?? 'dry';
                }
                $created++;
            }
        }
        $this->line("  dibuat/diupdate: {$created} · dilewati (nama kosong): {$skipped} · punya base_price>0: {$withPrice}");
    }

    // ────────────────────────────────────────────────────────────── 5. tarif

    private function migrateTarif(): void
    {
        $this->newLine();
        $this->info('▶ procedure_tariffs (carabayar_tindakan_rawat_jalan)');
        $created = 0; $skipEks = 0; $skipProc = 0; $skipInsurer = 0; $n = 0;
        $missInsurer = [];

        $rows = iterator_to_array($this->readCsv('carabayar_tindakan_rawat_jalan'));
        $bar = $this->output->createProgressBar(count($rows));
        foreach ($rows as $r) {
            if ($this->limit && $n >= $this->limit) { $bar->finish(); break; }
            $n++;
            $bar->advance();

            $carabayar = mb_strtolower(trim((string) ($r['carabayar_nama'] ?? '')));
            if ($carabayar === '' || str_contains($carabayar, 'eksekutif')) { $skipEks++; continue; }

            $procName = mb_strtolower(trim((string) ($r['nama_tindakan_rawat_jalan'] ?? '')));
            $procId = $this->procByName[$procName] ?? null;
            if ($procId === null || $procId === 'dry') {
                if ($this->dry && $procId === 'dry') { /* fallthrough utk hitung */ }
                else { $skipProc++; continue; }
            }

            $insurerId = $this->resolveInsurer($carabayar);
            if ($insurerId === null) {
                $skipInsurer++;
                $missInsurer[$r['carabayar_nama']] = ($missInsurer[$r['carabayar_nama']] ?? 0) + 1;
                continue;
            }

            $price = (float) ($r['harga'] ?? 0);
            if (! $this->dry && $procId !== 'dry') {
                ProcedureTariff::updateOrCreate(
                    ['legacy_uuid' => $r['uuid']],
                    [
                        'procedure_id' => $procId,
                        'insurer_id'   => $insurerId,
                        'price'        => round($price, 2),
                        'is_active'    => true,
                    ]
                );
            }
            $created++;
        }
        $bar->finish();
        $this->newLine();
        $this->line("  dibuat/diupdate: {$created} · skip eksekutif/kosong: {$skipEks} · skip tindakan tak dikenal: {$skipProc} · skip insurer tak ada: {$skipInsurer}");
        if ($missInsurer) {
            arsort($missInsurer);
            $top = array_slice($missInsurer, 0, 8, true);
            foreach ($top as $k => $v) $this->line("    (insurer tak ada) {$k}: {$v}");
        }
    }

    /** carabayar (lower) → insurer id, dgn alias 3 penjamin. */
    private function resolveInsurer(string $carabayarLower): ?string
    {
        $key = $this->insurerAlias[$carabayarLower] ?? $carabayarLower;
        return $this->insurerByName[$key] ?? null;
    }

    // ──────────────────────────────────────────────────────────────── 6. paket

    private function migratePaket(): void
    {
        $this->newLine();
        $this->info('▶ surgery_packages (paket_bedah) + tarif UMUM');
        $created = 0; $skipped = 0; $n = 0;
        $umumId = $this->insurerByName['umum'] ?? null;

        $rows = iterator_to_array($this->readCsv('paket_bedah'));
        $bar = $this->output->createProgressBar(count($rows));
        foreach ($rows as $r) {
            if ($this->limit && $n >= $this->limit) { $bar->finish(); break; }
            $n++;
            $bar->advance();

            $name = $this->clean($r['nama']);
            $total = (float) ($r['total'] ?? 0);
            if ($name === null || $total <= 0) { $skipped++; continue; } // skip total<=0 / nama kosong
            $lname = mb_strtolower($name);

            $data = [
                'name'             => $this->truncate($name, 255),
                'category'         => 'Bedah',
                'keterangan'       => $this->truncate($this->clean($r['keterangan'] ?? null), 255),
                'price'            => round($total, 2),
                'total_base_price' => round($total, 2),
                'is_active'        => ((string) ($r['delete_soft'] ?? '0')) === '0' ? true : false,
            ];

            if (! $this->dry) {
                $pkg = $this->upsertByLegacy(SurgeryPackage::class, $r['uuid'], $data, 'PKG');
                $this->pkgByName[$lname] = $pkg->id;
                if ($umumId) {
                    SurgeryPackageTariff::updateOrCreate(
                        ['surgery_package_id' => $pkg->id, 'insurer_id' => $umumId],
                        ['sell_price' => round($total, 2), 'is_active' => true]
                    );
                }
            }
            $created++;
        }
        $bar->finish();
        $this->newLine();
        $this->line("  dibuat/diupdate: {$created} · dilewati (total<=0 / nama kosong): {$skipped}");
    }

    // ──────────────────────────────────────────────────────────────── 7. resep

    private function migrateResep(): void
    {
        $this->newLine();
        $this->info('▶ prescriptions + prescription_items (resep)');
        if (empty($this->visitByLegacy)) {
            $this->warn('  Lewati: 0 visit legacy — jalankan Gel-1 dulu.');
            return;
        }

        // Group item per (registrasi_uuid|tanggal|dokter_uuid) = 1 resep header.
        // Stream sekali, kumpulkan per group (43.779 header) lalu tulis.
        $headers = [];   // gk => ['visit_id','dokter','tanggal','items'=>[]]
        $totItem = 0; $orphan = 0; $obatMiss = 0; $n = 0;

        foreach ($this->readCsv('resep') as $r) {
            if ($this->limit && $n >= $this->limit) break;
            $n++;
            $totItem++;

            $visitId = $this->visitByLegacy[$r['registrasi_uuid']] ?? null;
            if ($visitId === null) { $orphan++; continue; }

            // prescription_items.medication_id FK→medications (NOT NULL) → hanya obat (bukan bhp).
            $obatName = mb_strtolower(trim((string) ($r['nama_obat'] ?? '')));
            $medId = $this->medByName[$obatName] ?? null;
            if ($medId === null || $medId === 'dry') { $obatMiss++; continue; }

            $gk = ($r['registrasi_uuid'] ?? '') . '|' . ($r['tanggal'] ?? '') . '|' . ($r['dokter_uuid'] ?? '');
            if (! isset($headers[$gk])) {
                $headers[$gk] = [
                    'visit_id' => $visitId,
                    'dokter'   => $r['dokter_uuid'] ?? null,
                    'tanggal'  => $r['tanggal'] ?? null,
                    'items'    => [],
                ];
            }
            [$freq, $route, $note] = $this->parseSigna($r['signa'] ?? null, $r['posisimata'] ?? null);
            $headers[$gk]['items'][] = [
                'legacy_uuid' => $r['uuid'],
                'medication_id' => $medId,
                'quantity'    => max(1, (int) ($r['jumlah_kecil'] ?? 1)),
                'frequency'   => $freq,
                'route'       => $route,
                'instructions'=> $this->truncate(trim(($r['signa'] ?? '') . ($note ? " ({$note})" : '')), 255) ?: null,
                'is_bedah'    => ((string) ($r['is_bedah'] ?? '0')) === '1',
                'source'      => 'RESEP',
            ];
        }

        $this->line("  item terbaca: {$totItem} · header: " . count($headers)
            . " · yatim(visit): {$orphan} · obat tak dikenal: {$obatMiss}");

        if ($this->dry) {
            $this->line('  (dry-run — tidak menulis prescriptions)');
            return;
        }

        if ($this->fallbackDpjp === null) {
            $this->warn('  Lewati: tak ada employee sama sekali (prescribed_by_id NOT NULL). Seed employee dulu.');
            return;
        }

        $createdH = 0; $createdI = 0;
        $bar = $this->output->createProgressBar(count($headers));
        foreach ($headers as $h) {
            // prescribed_by_id NOT NULL → fallback DPJP bila dokter sumber tak ter-resolve.
            $prescribedBy = $this->employeeByLegacy[$h['dokter']] ?? $this->fallbackDpjp;
            // legacy_uuid header = gabungan stabil (registrasi+tgl+dokter) via item pertama.
            $headerLegacy = 'rx-' . substr(md5($h['visit_id'] . '|' . $h['tanggal'] . '|' . $h['dokter']), 0, 40);

            DB::transaction(function () use ($h, $prescribedBy, $headerLegacy, &$createdH, &$createdI) {
                $rx = Prescription::updateOrCreate(
                    ['legacy_uuid' => $headerLegacy],
                    [
                        'visit_id'         => $h['visit_id'],
                        'prescribed_by_id' => $prescribedBy,
                        'status'           => 'DISPENSED',   // historis = sudah diserahkan
                        'notes'            => 'migrasi prima vision',
                    ]
                );
                $createdH++;
                foreach ($h['items'] as $it) {
                    PrescriptionItem::updateOrCreate(
                        ['legacy_uuid' => $it['legacy_uuid']],
                        [
                            'prescription_id' => $rx->id,
                            'medication_id'   => $it['medication_id'],
                            'source'          => $it['source'],
                            'quantity'        => $it['quantity'],
                            'frequency'       => $it['frequency'],
                            'route'           => $it['route'],
                            'instructions'    => $it['instructions'],
                            'is_bedah'        => $it['is_bedah'],
                        ]
                    );
                    $createdI++;
                }
            });
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->line("  prescriptions: {$createdH} · items: {$createdI}");
    }

    /**
     * Parser signa Prima Vision → [frequency, route, note]. Terbukti ~87,5% (test_signa_parse.php).
     *   "4XODS"→4x sehari/ODS · "2X1"→2x sehari/ORAL · "/3 jam os"→8x sehari/OS · "k/p"→PRN · "/jam"→tiap 1 jam.
     */
    private function parseSigna(?string $signa, ?string $posisimata): array
    {
        $raw = trim((string) $signa);
        if ($raw === '' || $raw === '-') {
            return [null, $this->normRoute($posisimata), null];
        }
        $s = mb_strtoupper($raw);
        $note = null;
        if (preg_match('/\b(MALAM|PAGI|SIANG|SORE)\b/u', $s, $mw)) {
            $note = ucfirst(mb_strtolower($mw[1]));
            $s = trim(preg_replace('/\b(MALAM|PAGI|SIANG|SORE)\b/u', '', $s));
        }
        // PRN
        if (preg_match('#^K\s*/?\s*P$|^P\s*/?\s*R\s*/?\s*N$#u', $s)) {
            return ['kalau perlu', $this->normRoute($posisimata), 'PRN'];
        }
        // interval berangka: "/2JAM OD"
        if (preg_match('#/?\s*(?:TIAP\s*)?(\d+)\s*JAM#u', $s, $m)) {
            $f = round(24 / max(1, (int) $m[1])) . 'x sehari';
            return [$f, $this->pickRoute($s, $posisimata), $note];
        }
        // "tiap jam" tanpa angka
        if (preg_match('#(?:/|PER\s*|TIAP\s*|SETIAP\s*)JAM#u', $s)) {
            return ['tiap 1 jam', $this->pickRoute($s, $posisimata), $note];
        }
        // pola utama "NxLOKASI" / "Nx1"
        if (preg_match('/^(\d+)\s*X\s*([A-Z0-9]*)/u', $s, $m)) {
            $f = ((int) $m[1]) . 'x sehari';
            $tail = $m[2];
            if ($tail === '1') return [$f, 'ORAL', $note];
            if ($tail === '') return [$f, $this->pickRoute('', $posisimata), $note];
            return [$f, $this->normRoute($tail) ?? $this->pickRoute($tail, $posisimata), $note];
        }
        // hanya angka
        if (preg_match('/^(\d+)$/', $s, $m)) {
            return [((int) $m[1]) . 'x sehari', $this->pickRoute('', $posisimata) ?? 'ORAL', $note];
        }
        return [null, $this->normRoute($posisimata), $raw];
    }

    private function normRoute(?string $v): ?string
    {
        $v = mb_strtoupper(trim((string) $v));
        $v = str_replace(['DAN', ' '], ['', ''], $v);
        if ($v === '' || $v === '-') return null;
        if (in_array($v, ['ODS', 'ODOS', 'OSOD'], true)) return 'ODS';
        if ($v === 'OD') return 'OD';
        if ($v === 'OS') return 'OS';
        if (str_contains($v, 'ODS')) return 'ODS';
        if (str_contains($v, 'OD') && str_contains($v, 'OS')) return 'ODS';
        if (str_contains($v, 'OD')) return 'OD';
        if (str_contains($v, 'OS')) return 'OS';
        return null;
    }

    private function pickRoute(string $tail, ?string $posisimata): ?string
    {
        return $this->normRoute($tail) ?? $this->normRoute($posisimata);
    }

    // ───────────────────────────────────────────────────────────────── helpers

    /**
     * Upsert by legacy_uuid + autogen `code` SEBELUM insert (kolom code NOT NULL).
     * firstOrNew → fill → set code hanya bila record baru/belum punya code → save.
     * Idempotent: re-run mengisi data terbaru, code lama dipertahankan.
     */
    private function upsertByLegacy(string $modelClass, string $legacyUuid, array $data, string $codePrefix)
    {
        $model = $modelClass::withTrashed()->firstOrNew(['legacy_uuid' => $legacyUuid]);
        $model->fill($data);
        if (empty($model->code)) {
            $model->code = $this->genCode($modelClass, $codePrefix);
        }
        // pulihkan bila sebelumnya soft-deleted tapi sumber aktif
        if ($model->trashed() && ($data['is_active'] ?? true)) {
            $model->deleted_at = null;
        }
        $model->save();
        return $model;
    }

    /**
     * Generator kode unik PREFIX-NNN. MAX numerik via CAST (BUKAN lexicographic — agar
     * MED-1000 > MED-999, kalau string-sort salah). Cache per-prefix utk hindari N query.
     */
    private array $codeSeq = []; // prefix => angka terakhir terpakai

    private function genCode(string $modelClass, string $prefix): string
    {
        if (! isset($this->codeSeq[$prefix])) {
            // ambil angka maksimum sesungguhnya dari kolom code (numeric cast).
            $max = 0;
            foreach ($modelClass::withTrashed()->where('code', 'like', "{$prefix}-%")->pluck('code') as $c) {
                if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', $c, $m)) {
                    $max = max($max, (int) $m[1]);
                }
            }
            $this->codeSeq[$prefix] = $max;
        }
        $next = ++$this->codeSeq[$prefix];
        return sprintf('%s-%03d', $prefix, $next);
    }

    /** Generator baris asosiatif dari CSV gzip (quote-aware, streaming). */
    private function readCsv(string $name): \Generator
    {
        $path = "{$this->csvDir}/{$name}.csv.gz";
        if (! is_file($path)) {
            throw new \RuntimeException("CSV tidak ditemukan: {$path}");
        }
        $fh = gzopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Gagal membuka gzip: {$path}");
        }
        try {
            $header = $this->gzFgetcsv($fh);
            if ($header === null) return;
            while (($row = $this->gzFgetcsv($fh)) !== null) {
                if (count($row) !== count($header)) continue;
                yield array_combine($header, $row);
            }
        } finally {
            gzclose($fh);
        }
    }

    private function gzFgetcsv($fh): ?array
    {
        $line = gzgets($fh);
        if ($line === false) return null;
        while (substr_count($line, '"') % 2 !== 0 && ! gzeof($fh)) {
            $next = gzgets($fh);
            if ($next === false) break;
            $line .= $next;
        }
        return str_getcsv(rtrim($line, "\r\n"), ',', '"', '\\');
    }

    private function clean(?string $v): ?string
    {
        if ($v === null) return null;
        $v = trim($v);
        return ($v === '' || $v === '-' || strtoupper($v) === 'NULL') ? null : $v;
    }

    private function truncate(?string $v, int $len): ?string
    {
        return $v === null ? null : mb_substr($v, 0, $len);
    }
}
