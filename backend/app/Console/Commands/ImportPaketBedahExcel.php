<?php

namespace App\Console\Commands;

use App\Models\BhpItem;
use App\Models\BhpTariff;
use App\Models\Insurer;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\MedicationTariff;
use App\Models\Procedure;
use App\Models\SurgeryPackage;
use App\Services\MasterDataService;
use App\Services\TarifPaketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Sinkronkan komposisi 34 paket bedah dari "Docs/PAKET BEDAH.xlsx" (1 sheet = 1 paket;
 * item dikelompokkan per section kategori). REPLACE seluruh komposisi paket yang match
 * nama; harga jual per penjamin (packageTariffs) TIDAK disentuh; harga snapshot item
 * di-resolve dari Buku Tarif UMUM (Excel hanya menentukan komposisi & qty — keputusan user).
 *
 * Resolusi item berlapis: (1) alias config/paket_bedah_aliases.php → (2) exact name
 * per tipe section → (3) normalized (strip non-alfanumerik) lintas 4 master →
 * (4) AUTO-CREATE master baru + baris tarif UMUM harga Excel (keputusan user).
 *
 * Default DRY-RUN (transaksi di-rollback). Jalankan dengan --apply untuk menyimpan.
 */
class ImportPaketBedahExcel extends Command
{
    protected $signature = 'paket:import-excel {file : Path file xlsx} {--apply : Simpan perubahan (default dry-run)} {--paket= : Hanya proses 1 paket (nama persis)}';

    protected $description = 'Replace komposisi paket bedah dari Excel grouped per kategori (dry-run default)';

    /** Section Excel → tipe item master. */
    private const SECTION_TYPE = [
        'administrasi'         => 'PROCEDURE',
        'perawatan'            => 'PROCEDURE',
        'tindakan'             => 'PROCEDURE',
        'sewa kamar'           => 'PROCEDURE',
        'sewa peralatan medik' => 'PROCEDURE',
        'cssd supplies'        => 'BHP',
        'bahan habis pakai'    => 'BHP',
        'obat tindakan'        => 'MEDICATION',
    ];

    /** Section PROCEDURE → kategori master (procedure_categories) utk auto-create. */
    private const PROC_CREATE_CATEGORY = [
        'administrasi'         => 'Tarif Administrasi',
        'perawatan'            => 'Tindakan Perawatan dan Kefarmasian',
        'tindakan'             => 'Tindakan Dokter',
        'sewa kamar'           => 'Sewa Kamar',
        'sewa peralatan medik' => 'Sewa Peralatan Medik',
    ];

    /** Section BHP → kategori internal BhpItem utk auto-create. */
    private const BHP_CREATE_CATEGORY = [
        'cssd supplies'     => BhpItem::CATEGORY_CSSD,
        'bahan habis pakai' => BhpItem::CATEGORY_MEDICAL_BHP,
    ];

    private array $normIndex = [];   // norm(name) => list of ['type','id','name']
    private array $created   = [];   // laporan master auto-created
    private array $failures  = [];   // item tak ter-resolve
    private array $priceDiff = [];   // harga Excel ≠ Buku Tarif UMUM (informasional)

    public function handle(TarifPaketService $svc, MasterDataService $master): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File tidak ditemukan: {$file}");
            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $only  = $this->option('paket');

        $sheets = $this->parseWorkbook($file);
        $this->info(sprintf('%d sheet terbaca dari %s', count($sheets), basename($file)));

        $umumId = Insurer::where('is_system', true)->where('type', 'UMUM')->value('id');
        if (! $umumId) {
            $this->error('Penjamin sistem UMUM tidak ditemukan.');
            return self::FAILURE;
        }

        $this->buildNormIndex();

        DB::beginTransaction();
        try {
            $totItems = 0;
            $stats    = ['exact' => 0, 'alias' => 0, 'normalized' => 0, 'created' => 0];

            foreach ($sheets as $sheetName => $sheet) {
                if ($only && mb_strtolower(trim($only)) !== mb_strtolower($sheet['paket'])) {
                    continue;
                }
                $pkg = SurgeryPackage::whereRaw('LOWER(name) = ?', [mb_strtolower($sheet['paket'])])->first();
                if (! $pkg) {
                    $this->failures[] = "[{$sheetName}] paket '{$sheet['paket']}' tidak ditemukan di DB — sheet dilewati";
                    continue;
                }

                // Resolve semua item sheet; gagal satu pun → paket TIDAK direplace (jaga konsisten).
                $resolved = [];
                $sheetFail = 0;
                foreach ($sheet['items'] as $it) {
                    $hit = $this->resolveItem($it, $master, $umumId, $stats);
                    if (! $hit) {
                        $sheetFail++;
                        continue;
                    }
                    [$type, $id, $masterName] = $hit;
                    // Dedup (item sama muncul 2x di sheet) → jumlahkan qty.
                    $key = $type . '|' . $id;
                    if (isset($resolved[$key])) {
                        $resolved[$key]['quantity'] += (int) $it['qty'];
                    } else {
                        $resolved[$key] = [
                            'item_type' => $type,
                            'item_id'   => $id,
                            'quantity'  => (int) $it['qty'],
                            // default_price null → snapshot Buku Tarif UMUM (keputusan: Buku Tarif acuan)
                            'default_price' => null,
                        ];
                    }
                    // Buku Tarif acuan harga. Item TANPA baris tarif UMUM akan tertagih
                    // Rp 0 di kasir (getPrice tanpa fallback master) → lengkapi baris UMUM
                    // dari harga Excel. Tarif yang SUDAH ada tidak pernah diubah.
                    $excel     = (float) ($it['harga'] ?? 0);
                    $bukuTarif = $this->umumTariffPrice($type, $id, $umumId);
                    if ($bukuTarif === null && $excel > 0) {
                        $this->createUmumTariff($type, $id, $umumId, $excel, $masterName);
                    } elseif ($excel > 0 && $bukuTarif !== null && abs($bukuTarif - $excel) >= 1) {
                        $this->priceDiff[] = sprintf('[%s] %s: excel=%s bukutarif=%s', $sheetName, $masterName, number_format($excel), number_format($bukuTarif));
                    }
                }

                if ($sheetFail > 0) {
                    $this->failures[] = "[{$sheetName}] {$sheetFail} item gagal resolve — paket TIDAK direplace";
                    continue;
                }

                $n = $svc->replaceKomposisiResolved($pkg, array_values($resolved));
                $totItems += $n;
                $this->line(sprintf('  %-70s %3d item', $sheet['paket'], $n));
            }

            $this->newLine();
            $this->info(sprintf(
                'Item terpasang: %d (exact %d, alias %d, normalized %d, auto-created %d)',
                $totItems, $stats['exact'], $stats['alias'], $stats['normalized'], $stats['created']
            ));

            if ($this->created) {
                $this->warn('Master BARU dibuat (lengkapi detail via menu master):');
                foreach ($this->created as $c) $this->line('  + ' . $c);
            }
            if ($this->tariffCreated) {
                $this->warn('Baris tarif UMUM BARU (item sudah ada tapi tanpa Buku Tarif — diisi harga Excel):');
                foreach ($this->tariffCreated as $t) $this->line('  + ' . $t);
            }
            if ($this->priceDiff) {
                $this->warn('Harga Excel ≠ Buku Tarif UMUM (Buku Tarif tetap acuan, tidak diubah):');
                foreach ($this->priceDiff as $d) $this->line('  ~ ' . $d);
            }
            if ($this->failures) {
                $this->error('GAGAL (' . count($this->failures) . '):');
                foreach ($this->failures as $f) $this->line('  ! ' . $f);
            }

            if ($apply && ! $this->failures) {
                DB::commit();
                $this->info('APPLY: perubahan disimpan.');
            } else {
                DB::rollBack();
                $this->warn($this->failures
                    ? 'ROLLBACK: ada kegagalan — perbaiki alias/master dulu.'
                    : 'DRY-RUN: tidak ada perubahan tersimpan. Jalankan ulang dengan --apply.');
            }
            return $this->failures ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('ERROR: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    // ─── Parsing ────────────────────────────────────────────────────────────

    /** @return array<string, array{paket:string, items:array}> */
    private function parseWorkbook(string $file): array
    {
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $wb  = $reader->load($file);
        $out = [];
        foreach ($wb->getAllSheets() as $ws) {
            $rows  = $ws->toArray(null, true, false, false);
            $paket = trim((string) ($rows[0][0] ?? ''));
            if ($paket === '') {
                continue;
            }
            $items   = [];
            $section = null;
            foreach (array_slice($rows, 2) as $r) {
                $name = trim((string) ($r[0] ?? ''));
                $qty  = $r[1] ?? null;
                if ($name === '' || strtoupper($name) === 'TOTAL' || $name === 'Deskripsi') {
                    continue;
                }
                if ($qty === null || $qty === '') {           // header section
                    $section = mb_strtolower($name);
                    continue;
                }
                if (! isset(self::SECTION_TYPE[$section])) {
                    $this->failures[] = "[{$ws->getTitle()}] section tak dikenal '{$section}' — item '{$name}' dilewati";
                    continue;
                }
                $items[] = [
                    'section' => $section,
                    'type'    => self::SECTION_TYPE[$section],
                    'name'    => $name,
                    'qty'     => max(1, (int) $qty),
                    'harga'   => is_numeric($r[2] ?? null) ? (float) $r[2] : null,
                ];
            }
            $out[$ws->getTitle()] = ['paket' => $paket, 'items' => $items];
        }
        return $out;
    }

    // ─── Resolusi item ──────────────────────────────────────────────────────

    /** @return array{0:string,1:string,2:string}|null [type, id, masterName] */
    private function resolveItem(array $it, MasterDataService $master, string $umumId, array &$stats): ?array
    {
        $name = trim($it['name']);
        $type = $it['type'];

        // 1. Alias (boleh override tipe). Nama boleh string atau ARRAY kandidat yang
        //    dicoba berurutan — master dev vs live bisa beda nama (mis. live berprefix
        //    "Alk - "). 'code:XXX' = lookup by kode master (kasus nama duplikat ambigu).
        //    Semua kandidat tak ketemu → AUTO-CREATE dengan nama kanonis kandidat
        //    PERTAMA (bukan typo Excel); IOL tidak pernah di-auto-create.
        $alias = config('paket_bedah_aliases')[mb_strtolower($name)] ?? null;
        if ($alias) {
            [$aType, $aNames] = $alias;
            foreach ((array) $aNames as $aName) {
                if (str_starts_with($aName, 'code:')) {
                    $id = $this->lookupByCode($aType, substr($aName, 5));
                } else {
                    $id = $aType === 'IOL' ? $this->lookupIolByBrand($aName) : app(TarifPaketService::class)->lookupItemId($aType, $aName);
                }
                if ($id) {
                    $stats['alias']++;
                    return [$aType, $id, $aName];
                }
            }
            if ($aType === 'IOL') {
                $this->failures[] = "alias '{$name}' → IOL '" . implode("'/'", (array) $aNames) . "' tidak ketemu (IOL tidak di-auto-create)";
                return null;
            }
            $canonical = (array) $aNames;
            $canonical = str_starts_with($canonical[0], 'code:') ? $name : $canonical[0];
            return $this->autoCreate($it, $master, $umumId, $stats, $canonical, $aType);
        }

        // 2. Exact per tipe section
        $id = app(TarifPaketService::class)->lookupItemId($type, $name);
        if ($id) {
            $stats['exact']++;
            return [$type, $id, $name];
        }

        // 3. Normalized lintas 4 master (tangkap typo/format sisa; tepat 1 → pakai)
        $hits = $this->normIndex[self::norm($name)] ?? [];
        if (count($hits) === 1) {
            $stats['normalized']++;
            return [$hits[0]['type'], $hits[0]['id'], $hits[0]['name']];
        }
        if (count($hits) > 1) {
            $this->failures[] = "'{$name}' ambigu (normalized match " . count($hits) . ' master) — tambahkan ke alias';
            return null;
        }

        // 4. Auto-create (keputusan user) — IOL tidak pernah dibuat otomatis.
        return $this->autoCreate($it, $master, $umumId, $stats);
    }

    private function autoCreate(array $it, MasterDataService $master, string $umumId, array &$stats, ?string $nameOverride = null, ?string $typeOverride = null): ?array
    {
        $name  = $nameOverride ?? trim($it['name']);
        $harga = (float) ($it['harga'] ?? 0);

        switch ($typeOverride ?? $it['type']) {
            case 'MEDICATION':
                $med = $master->storeObat([
                    'name' => $name, 'golongan' => 'KERAS', 'formularium' => 'NON-FORNAS',
                    'unit' => 'pcs', 'stock' => 0, 'min_stock' => 0,
                    'price' => $harga, 'is_active' => true,
                    // hint prefix kode OBT- (pos tarifnya memang OBAT_TINDAKAN di bawah)
                    'pos_kwitansi' => MedicationTariff::POS_OBAT_TINDAKAN,
                ]);
                MedicationTariff::create([
                    'medication_id' => $med->id, 'insurer_id' => $umumId, 'price' => $harga,
                    'is_active' => true, 'pos_kwitansi' => MedicationTariff::POS_OBAT_TINDAKAN,
                ]);
                $this->registerCreated('MEDICATION', $med->id, $name, $harga);
                $stats['created']++;
                return ['MEDICATION', $med->id, $name];

            case 'BHP':
                $bhp = $master->storeBhp([
                    'name' => $name,
                    'category' => self::BHP_CREATE_CATEGORY[$it['section']] ?? BhpItem::CATEGORY_MEDICAL_BHP,
                    'unit' => 'pcs', 'stock' => 0, 'min_stock' => 0,
                    'price' => $harga, 'is_active' => true,
                ]);
                BhpTariff::create([
                    'bhp_item_id' => $bhp->id, 'insurer_id' => $umumId, 'price' => $harga, 'is_active' => true,
                ]);
                $this->registerCreated('BHP', $bhp->id, $name, $harga);
                $stats['created']++;
                return ['BHP', $bhp->id, $name];

            case 'PROCEDURE':
                $cat  = self::PROC_CREATE_CATEGORY[$it['section']] ?? 'Tindakan Dokter';
                // storeTindakan: auto-generate kode dari prefix kategori + sync baris tarif UMUM.
                $proc = $master->storeTindakan([
                    'name' => $name, 'category' => $cat, 'base_price' => $harga, 'is_active' => true,
                ]);
                $this->registerCreated('PROCEDURE (' . $cat . ')', $proc->id, $name, $harga);
                $stats['created']++;
                return ['PROCEDURE', $proc->id, $name];

            default:
                $this->failures[] = "'{$name}' ({$it['type']}) tidak ketemu & tipe ini tidak di-auto-create";
                return null;
        }
    }

    private function registerCreated(string $type, string $id, string $name, float $harga): void
    {
        $label = sprintf('%s | %s | tarif UMUM Rp %s', $type, $name, number_format($harga));
        if (! in_array($label, $this->created, true)) {
            $this->created[] = $label;
        }
        $this->normIndex[self::norm($name)][] = ['type' => explode(' ', $type)[0], 'id' => $id, 'name' => $name];
    }

    // ─── Index & util ───────────────────────────────────────────────────────

    private function buildNormIndex(): void
    {
        foreach (Procedure::query()->pluck('name', 'id') as $id => $n) {
            $this->normIndex[self::norm($n)][] = ['type' => 'PROCEDURE', 'id' => (string) $id, 'name' => $n];
        }
        foreach (Medication::query()->pluck('name', 'id') as $id => $n) {
            $this->normIndex[self::norm($n)][] = ['type' => 'MEDICATION', 'id' => (string) $id, 'name' => $n];
        }
        foreach (BhpItem::query()->pluck('name', 'id') as $id => $n) {
            $this->normIndex[self::norm($n)][] = ['type' => 'BHP', 'id' => (string) $id, 'name' => $n];
        }
        foreach (IolItem::query()->pluck('brand', 'id') as $id => $n) {
            $this->normIndex[self::norm($n)][] = ['type' => 'IOL', 'id' => (string) $id, 'name' => $n];
        }
    }

    /** Harga baris tarif UMUM aktif; null bila item belum punya baris tarif. */
    private function umumTariffPrice(string $type, string $itemId, string $umumId): ?float
    {
        [$table, $fk] = self::tariffTable($type);
        $p = DB::table($table)->where($fk, $itemId)->where('insurer_id', $umumId)->where('is_active', true)->value('price');
        return $p !== null ? (float) $p : null;
    }

    private array $tariffCreated = [];

    private function createUmumTariff(string $type, string $itemId, string $umumId, float $price, string $masterName): void
    {
        [$table, $fk] = self::tariffTable($type);
        $payload = [
            'id' => (string) \Illuminate\Support\Str::uuid(), $fk => $itemId, 'insurer_id' => $umumId,
            'price' => $price, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ];
        if ($type === 'MEDICATION') {
            // Semua obat Excel ada di section "Obat Tindakan".
            $payload['pos_kwitansi'] = MedicationTariff::POS_OBAT_TINDAKAN;
        }
        DB::table($table)->insert($payload);
        $this->tariffCreated[] = sprintf('%s | %s | Rp %s', $type, $masterName, number_format($price));
    }

    private static function tariffTable(string $type): array
    {
        return [
            'PROCEDURE'  => ['procedure_tariffs',  'procedure_id'],
            'MEDICATION' => ['medication_tariffs', 'medication_id'],
            'BHP'        => ['bhp_tariffs',        'bhp_item_id'],
            'IOL'        => ['iol_tariffs',        'iol_item_id'],
        ][$type];
    }

    private function lookupByCode(string $type, string $code): ?string
    {
        $cls = ['PROCEDURE' => Procedure::class, 'MEDICATION' => Medication::class, 'BHP' => BhpItem::class][$type] ?? null;
        return $cls ? $cls::where('code', $code)->value('id') : null;
    }

    private function lookupIolByBrand(string $brand): ?string
    {
        $rows = IolItem::whereRaw('LOWER(brand) = ?', [mb_strtolower($brand)])->limit(2)->pluck('id');
        return $rows->count() === 1 ? (string) $rows->first() : null;
    }

    private static function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s));
    }

    private static function tarifType(string $itemType): string
    {
        return ['PROCEDURE' => 'tindakan', 'MEDICATION' => 'obat', 'BHP' => 'bhp', 'IOL' => 'iol'][$itemType];
    }
}
