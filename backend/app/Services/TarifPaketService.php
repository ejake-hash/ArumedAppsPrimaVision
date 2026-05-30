<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\Procedure;
use App\Models\SurgeryPackage;
use App\Models\SurgeryPackageItem;
use App\Models\SurgeryPackageTariff;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TarifPaketService
{
    public function __construct(
        private readonly Request $request,
        private readonly MasterDataService $masterData,
    ) {}

    // =========================================================================
    // TARIF PER PENJAMIN — delegasi ke MasterDataService (reuse, no duplication)
    // =========================================================================

    public function indexTarif(string $type, array $filters = []): LengthAwarePaginator
    {
        return $this->masterData->indexTarif($type, $filters);
    }

    public function storeTarif(string $type, array $data): mixed
    {
        return $this->masterData->storeTarif($type, $data);
    }

    public function updateTarif(string $type, string $id, array $data): mixed
    {
        return $this->masterData->updateTarif($type, $id, $data);
    }

    public function deleteTarif(string $type, string $id): void
    {
        $this->masterData->deleteTarif($type, $id);
    }

    public function showMetodeBayar(string $id): array
    {
        return $this->masterData->showMetodeBayar($id);
    }

    public function templateTarifCsv(string $type): string
    {
        return $this->masterData->templateTarifCsv($type);
    }

    public function exportTarifCsvForInsurer(string $type, string $insurerId): string
    {
        return $this->masterData->exportTarifCsvForInsurer($type, $insurerId);
    }

    public function importTarifCsvForInsurer(string $type, string $insurerId, string $csv): array
    {
        return $this->masterData->importTarifCsvForInsurer($type, $insurerId, $csv);
    }

    /** Expose harga master untuk satu item (dipakai endpoint master-price). */
    public function getMasterPriceFor(string $type, string $itemId): float
    {
        $modelType = match ($type) {
            'tindakan' => SurgeryPackageItem::TYPE_PROCEDURE,
            'obat'     => SurgeryPackageItem::TYPE_MEDICATION,
            'bhp'      => SurgeryPackageItem::TYPE_BHP,
            'iol'      => SurgeryPackageItem::TYPE_IOL,
            default    => throw new \Exception("Tipe tidak dikenal: {$type}", 422),
        };
        return $this->resolveMasterPrice($modelType, $itemId);
    }


    // =========================================================================
    // PAKET BEDAH — CRUD utama
    // =========================================================================

    public function indexPaket(array $filters = []): LengthAwarePaginator
    {
        $query = SurgeryPackage::query()->with(['items', 'packageTariffs.insurer']);

        if (! empty($filters['search'])) {
            $kw = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$kw}%")
                ->orWhere('code', 'ilike', "%{$kw}%")
                ->orWhere('category', 'ilike', "%{$kw}%")
            );
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }

        return $query->orderBy('name')->paginate($filters['per_page'] ?? 20);
    }

    public function showPaket(string $id): SurgeryPackage
    {
        $pkg = SurgeryPackage::with(['items', 'packageTariffs.insurer'])->findOrFail($id);

        // Enrich items dengan info nama/kode item terkait (resolveItem polymorphic)
        $pkg->items->each(function (SurgeryPackageItem $item) {
            $resolved = $item->resolveItem();
            $item->setAttribute('item_name', $resolved?->name ?? $resolved?->brand ?? '-');
            $item->setAttribute('item_code', $resolved?->code ?? null);
            $item->setAttribute('subtotal', $item->subtotal());
        });

        // Enrich tariffs dengan diskon yang dihitung
        $pkg->packageTariffs->each(function (SurgeryPackageTariff $t) use ($pkg) {
            $t->setRelation('package', $pkg);
            $t->setAttribute('discount_amount', $t->discountAmount());
            $t->setAttribute('discount_percent', $t->discountPercent());
        });

        return $pkg;
    }

    public function storePaket(array $data): SurgeryPackage
    {
        $pkg = SurgeryPackage::create($data + ['total_base_price' => 0]);
        $this->log(auth('api')->id(), 'CREATE_PAKET_BEDAH', SurgeryPackage::class, $pkg->id);
        return $pkg;
    }

    public function updatePaket(string $id, array $data): SurgeryPackage
    {
        $pkg = SurgeryPackage::findOrFail($id);
        $pkg->update($data);
        $this->log(auth('api')->id(), 'UPDATE_PAKET_BEDAH', SurgeryPackage::class, $id);
        return $pkg->fresh();
    }

    public function deletePaket(string $id): void
    {
        SurgeryPackage::findOrFail($id)->delete();
        $this->log(auth('api')->id(), 'DELETE_PAKET_BEDAH', SurgeryPackage::class, $id);
    }

    // =========================================================================
    // PAKET BEDAH — ITEMS (komposisi paket)
    // =========================================================================

    public function listItems(string $packageId): array
    {
        $pkg = SurgeryPackage::findOrFail($packageId);
        return $pkg->items()->get()->map(function (SurgeryPackageItem $item) {
            $resolved = $item->resolveItem();
            return [
                'id'            => $item->id,
                'item_type'     => $item->item_type,
                'item_id'       => $item->item_id,
                'item_code'     => $resolved?->code ?? null,
                'item_name'     => $resolved?->name ?? $resolved?->brand ?? '-',
                'quantity'      => $item->quantity,
                'default_price' => $item->default_price,
                'subtotal'      => $item->subtotal(),
                'notes'         => $item->notes,
            ];
        })->toArray();
    }

    /**
     * Tambah item ke paket. Snapshot default_price diambil dari master
     * (base_price/price) jika data['default_price'] tidak disediakan.
     */
    public function addItem(string $packageId, array $data): SurgeryPackageItem
    {
        $pkg = SurgeryPackage::findOrFail($packageId);

        $defaultPrice = $data['default_price'] ?? $this->resolveMasterPrice($data['item_type'], $data['item_id']);

        return DB::transaction(function () use ($pkg, $data, $defaultPrice) {
            $item = SurgeryPackageItem::updateOrCreate(
                [
                    'surgery_package_id' => $pkg->id,
                    'item_type'          => $data['item_type'],
                    'item_id'            => $data['item_id'],
                ],
                [
                    'quantity'      => $data['quantity'] ?? 1,
                    'default_price' => $defaultPrice,
                    'notes'         => $data['notes'] ?? null,
                ]
            );

            $pkg->recalcTotalBasePrice();
            $this->log(auth('api')->id(), 'ADD_PAKET_ITEM', SurgeryPackageItem::class, $item->id, "package:{$pkg->id}");

            return $item;
        });
    }

    public function updateItem(string $packageId, string $itemId, array $data): SurgeryPackageItem
    {
        $pkg  = SurgeryPackage::findOrFail($packageId);
        $item = $pkg->items()->where('id', $itemId)->firstOrFail();

        return DB::transaction(function () use ($pkg, $item, $data) {
            $item->update(array_filter([
                'quantity'      => $data['quantity']      ?? null,
                'default_price' => $data['default_price'] ?? null,
                'notes'         => $data['notes']         ?? null,
            ], fn ($v) => $v !== null));

            $pkg->recalcTotalBasePrice();
            $this->log(auth('api')->id(), 'UPDATE_PAKET_ITEM', SurgeryPackageItem::class, $item->id);

            return $item->fresh();
        });
    }

    public function removeItem(string $packageId, string $itemId): void
    {
        $pkg  = SurgeryPackage::findOrFail($packageId);
        $item = $pkg->items()->where('id', $itemId)->firstOrFail();

        DB::transaction(function () use ($pkg, $item) {
            $item->delete();
            $pkg->recalcTotalBasePrice();
            $this->log(auth('api')->id(), 'DELETE_PAKET_ITEM', SurgeryPackageItem::class, $item->id);
        });
    }

    /**
     * Lihat harga master "live" untuk satu item (PROCEDURE/MEDICATION/BHP/IOL).
     * Dipakai sebagai default ketika admin belum override default_price.
     */
    private function resolveMasterPrice(string $type, string $itemId): float
    {
        return match ($type) {
            SurgeryPackageItem::TYPE_PROCEDURE  => (float) (Procedure::find($itemId)?->base_price  ?? 0),
            SurgeryPackageItem::TYPE_MEDICATION => (float) (Medication::find($itemId)?->price      ?? 0),
            SurgeryPackageItem::TYPE_BHP        => (float) (BhpItem::find($itemId)?->price         ?? 0),
            SurgeryPackageItem::TYPE_IOL        => (float) (IolItem::find($itemId)?->price         ?? 0),
            default                             => 0,
        };
    }

    // =========================================================================
    // PAKET BEDAH — TARIFFS (harga jual per penjamin + auto diskon)
    // =========================================================================

    public function listTariffs(string $packageId): array
    {
        $pkg = SurgeryPackage::findOrFail($packageId);
        return $pkg->packageTariffs()->with('insurer')->get()->map(function (SurgeryPackageTariff $t) use ($pkg) {
            $t->setRelation('package', $pkg);
            return [
                'id'               => $t->id,
                'insurer_id'       => $t->insurer_id,
                'insurer_name'     => $t->insurer?->name ?? 'SEMUA',
                'classification'   => $t->classification,
                'sell_price'       => $t->sell_price,
                'base_price'       => $pkg->total_base_price,
                'discount_amount'  => $t->discountAmount(),
                'discount_percent' => $t->discountPercent(),
                'is_active'        => $t->is_active,
            ];
        })->toArray();
    }

    public function upsertTariff(string $packageId, array $data): SurgeryPackageTariff
    {
        $pkg = SurgeryPackage::findOrFail($packageId);

        $tariff = SurgeryPackageTariff::updateOrCreate(
            [
                'surgery_package_id' => $pkg->id,
                'insurer_id'         => $data['insurer_id'] ?? null,
                'classification'     => $data['classification'],
            ],
            [
                'sell_price' => $data['sell_price'],
                'is_active'  => $data['is_active'] ?? true,
            ]
        );

        $this->log(auth('api')->id(), 'UPSERT_PAKET_TARIFF', SurgeryPackageTariff::class, $tariff->id, "package:{$pkg->id}");
        return $tariff->load('insurer');
    }

    public function deleteTariff(string $packageId, string $tariffId): void
    {
        $pkg    = SurgeryPackage::findOrFail($packageId);
        $tariff = $pkg->packageTariffs()->where('id', $tariffId)->firstOrFail();
        $tariff->delete();
        $this->log(auth('api')->id(), 'DELETE_PAKET_TARIFF', SurgeryPackageTariff::class, $tariffId);
    }

    // =========================================================================
    // PAKET BEDAH — CSV TEMPLATE / EXPORT / IMPORT
    // =========================================================================

    private const CSV_HEADERS = ['nama_paket', 'kategori', 'deskripsi', 'aktif', 'item_tipe', 'item_nama', 'qty', 'catatan'];

    public function templatePaketCsv(): string
    {
        $output = fopen('php://temp', 'r+');
        foreach ($this->paketCsvNotes() as $note) {
            fwrite($output, '# ' . $note . "\n");
        }
        fputcsv($output, self::CSV_HEADERS, ',', '"', '\\');
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    public function exportPaketCsv(): string
    {
        $packages = SurgeryPackage::with('items')->orderBy('name')->get();
        return $this->buildPaketCsv($packages);
    }

    /**
     * Template CSV utk SATU paket — header notes + komposisi item paket ini
     * terisi (siap di-edit/import balik). Dipakai dari halaman detail paket.
     * Kalau paket belum punya item → 1 baris header (kolom item kosong).
     */
    public function templatePaketCsvForPackage(string $packageId): string
    {
        $pkg = SurgeryPackage::with('items')->findOrFail($packageId);
        return $this->buildPaketCsv(collect([$pkg]), withNotes: true);
    }

    /**
     * Export komposisi SATU paket apa adanya (tanpa baris petunjuk).
     */
    public function exportPaketCsvForPackage(string $packageId): string
    {
        $pkg = SurgeryPackage::with('items')->findOrFail($packageId);
        return $this->buildPaketCsv(collect([$pkg]));
    }

    /**
     * Tulis CSV paket (long format) dari koleksi paket. Optional baris petunjuk
     * "#" di atas header (template). Item master soft-deleted di-skip; paket
     * tanpa item valid tetap dapat 1 baris header.
     */
    private function buildPaketCsv($packages, bool $withNotes = false): string
    {
        $output = fopen('php://temp', 'r+');

        if ($withNotes) {
            foreach ($this->paketCsvNotes() as $note) {
                fwrite($output, '# ' . $note . "\n");
            }
        }

        fputcsv($output, self::CSV_HEADERS, ',', '"', '\\');

        foreach ($packages as $pkg) {
            $headerRow = [
                $pkg->name,
                $pkg->category ?? '',
                $pkg->description ?? '',
                $pkg->is_active ? '1' : '0',
            ];

            $exportedItem = false;
            foreach ($pkg->items as $item) {
                $resolved = $item->resolveItem();
                // Master item sudah soft-deleted → resolveItem null → nama kosong.
                // Skip: kalau diekspor, import gagal "item_nama kosong" (export & import inkonsisten).
                if (! $resolved) {
                    continue;
                }
                $itemName = $this->formatItemNameForCsv($item->item_type, $resolved);
                $exportedItem = true;
                fputcsv($output, array_merge($headerRow, [
                    $item->item_type,
                    $itemName,
                    (string) $item->quantity,
                    $item->notes ?? '',
                ]), ',', '"', '\\');
            }

            // Paket tanpa item valid (kosong, atau semua master-nya sudah dihapus) →
            // tetap diekspor 1 baris header (kolom item kosong) supaya paket tak hilang.
            if (! $exportedItem) {
                fputcsv($output, array_merge($headerRow, ['', '', '', '']), ',', '"', '\\');
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /** Baris petunjuk pengisian (tanpa prefix '#') untuk template paket bedah. */
    private function paketCsvNotes(): array
    {
        return [
            'PETUNJUK PENGISIAN — baris diawali "#" diabaikan saat import (boleh dibiarkan/dihapus).',
            'Format LONG: 1 baris = 1 item. Paket multi-item → ulang baris dgn nama_paket sama.',
            'Kolom WAJIB: nama_paket, item_tipe, item_nama, qty. (kategori/deskripsi/aktif/catatan opsional)',
            'item_tipe salah satu: ' . implode(' | ', SurgeryPackageItem::TYPES) . '.',
            'item_nama dicocokkan ke master (case-insensitive). IOL: tulis "Brand Model PowerD" mis. "Alcon AcrySof IQ 21D".',
            'qty = angka >= 1 (kosong/0 → dianggap 1). aktif: 1 = aktif, 0 = nonaktif.',
            'Import nama_paket yang SUDAH ada → komposisi item di-REPLACE (tarif jual per penjamin TIDAK disentuh).',
        ];
    }

    /**
     * Import paket bedah dari CSV (long format).
     * Konflik nama paket → replace komposisi items (header dipertahankan, tarif jual tidak disentuh).
     * Item lookup case-insensitive by name (IOL: "brand model powerD").
     * default_price auto dari master saat ini.
     */
    public function importPaketCsv(string $csvContent): array
    {
        $lines = $this->csvDataLines($csvContent);
        if (empty($lines)) {
            throw new \Exception('File CSV kosong.', 422);
        }

        $headers = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), ',', '"', '\\'));
        foreach (['nama_paket', 'item_tipe', 'item_nama', 'qty'] as $required) {
            if (! in_array($required, $headers, true)) {
                throw new \Exception("Header CSV harus mengandung kolom '{$required}'.", 422);
            }
        }

        // Group baris by nama_paket
        $grouped = [];
        $errors  = [];
        foreach ($lines as $idx => $line) {
            $lineNum = $idx + 2;
            if (trim($line) === '') continue;

            $values = str_getcsv($line, ',', '"', '\\');
            if (count($values) !== count($headers)) {
                $errors[] = "Baris {$lineNum}: jumlah kolom tidak sesuai header";
                continue;
            }
            $row = array_combine($headers, $values);
            $namaPaket = trim((string) ($row['nama_paket'] ?? ''));
            if ($namaPaket === '') {
                $errors[] = "Baris {$lineNum}: 'nama_paket' kosong";
                continue;
            }

            $key = mb_strtolower($namaPaket);
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'name'        => $namaPaket,
                    'category'    => trim((string) ($row['kategori'] ?? '')) ?: null,
                    'description' => trim((string) ($row['deskripsi'] ?? '')) ?: null,
                    'is_active'   => $this->parseBool($row['aktif'] ?? '1'),
                    'items'       => [],
                    '_lines'      => [],
                ];
            }
            $grouped[$key]['_lines'][] = $lineNum;

            $itemTipe = strtoupper(trim((string) ($row['item_tipe'] ?? '')));
            $itemNama = trim((string) ($row['item_nama'] ?? ''));
            $qty      = (int) ($row['qty'] ?? 0);

            // Baris tanpa item (paket kosong) — skip item, header tetap diproses
            if ($itemTipe === '' && $itemNama === '') {
                continue;
            }

            if (! in_array($itemTipe, SurgeryPackageItem::TYPES, true)) {
                $errors[] = "Baris {$lineNum}: item_tipe '{$itemTipe}' tidak valid (harus " . implode('/', SurgeryPackageItem::TYPES) . ')';
                continue;
            }
            if ($itemNama === '') {
                $errors[] = "Baris {$lineNum}: 'item_nama' kosong";
                continue;
            }
            if ($qty < 1) $qty = 1;

            $grouped[$key]['items'][] = [
                'item_type' => $itemTipe,
                'item_name' => $itemNama,
                'quantity'  => $qty,
                'notes'     => trim((string) ($row['catatan'] ?? '')) ?: null,
                '_line'     => $lineNum,
            ];
        }

        $created         = 0;
        $updated         = 0;
        $itemsInserted   = 0;
        $itemsLookupFail = 0;

        foreach ($grouped as $group) {
            $existing = SurgeryPackage::whereRaw('LOWER(name) = ?', [mb_strtolower($group['name'])])->first();

            DB::transaction(function () use ($group, $existing, &$created, &$updated, &$itemsInserted, &$itemsLookupFail, &$errors) {
                if ($existing) {
                    $existing->update([
                        'category'    => $group['category'] ?? $existing->category,
                        'description' => $group['description'] ?? $existing->description,
                        'is_active'   => $group['is_active'],
                    ]);
                    // Replace items
                    $existing->items()->delete();
                    $pkg = $existing;
                    $updated++;
                } else {
                    $pkg = SurgeryPackage::create([
                        'name'             => $group['name'],
                        'category'         => $group['category'],
                        'description'      => $group['description'],
                        'is_active'        => $group['is_active'],
                        'total_base_price' => 0,
                    ]);
                    $created++;
                }

                foreach ($group['items'] as $itemRow) {
                    $itemId = $this->lookupItemIdByName($itemRow['item_type'], $itemRow['item_name']);
                    if (! $itemId) {
                        $itemsLookupFail++;
                        $errors[] = "Baris {$itemRow['_line']}: item {$itemRow['item_type']} '{$itemRow['item_name']}' tidak ditemukan di master";
                        continue;
                    }

                    $defaultPrice = $this->resolveMasterPrice($itemRow['item_type'], $itemId);

                    SurgeryPackageItem::updateOrCreate(
                        [
                            'surgery_package_id' => $pkg->id,
                            'item_type'          => $itemRow['item_type'],
                            'item_id'            => $itemId,
                        ],
                        [
                            'quantity'      => $itemRow['quantity'],
                            'default_price' => $defaultPrice,
                            'notes'         => $itemRow['notes'],
                        ]
                    );
                    $itemsInserted++;
                }

                $pkg->recalcTotalBasePrice();
            });
        }

        $this->log(auth('api')->id(), 'IMPORT_PAKET_BEDAH_CSV', null, null,
            "new:{$created} upd:{$updated} items:{$itemsInserted} lookup_fail:{$itemsLookupFail}");

        return [
            'created'           => $created,
            'updated'           => $updated,
            'items_inserted'    => $itemsInserted,
            'items_lookup_fail' => $itemsLookupFail,
            'errors'            => $errors,
        ];
    }

    private function parseBool(mixed $v): bool
    {
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'true', 'yes', 'y', 'ya', 'aktif'], true);
    }

    /**
     * Pecah CSV jadi baris data: buang \r, baris kosong, dan baris komentar (#)
     * — petunjuk pengisian di template. Elemen pertama hasil = header.
     */
    private function csvDataLines(string $csvContent): array
    {
        $raw = explode("\n", str_replace("\r", '', trim($csvContent)));
        $lines = array_filter($raw, static function ($line) {
            $t = trim($line);
            return $t !== '' && ! str_starts_with($t, '#');
        });
        return array_values($lines);
    }

    /** Format item_nama untuk CSV (IOL pakai brand+model+power, lainnya pakai name). */
    private function formatItemNameForCsv(string $type, ?\Illuminate\Database\Eloquent\Model $resolved): string
    {
        if (! $resolved) return '';
        if ($type === SurgeryPackageItem::TYPE_IOL) {
            $brand = trim((string) ($resolved->brand ?? ''));
            $model = trim((string) ($resolved->model ?? ''));
            $power = $resolved->power !== null ? rtrim(rtrim(number_format((float) $resolved->power, 2, '.', ''), '0'), '.') . 'D' : '';
            return trim("{$brand} {$model} {$power}");
        }
        return (string) ($resolved->name ?? '');
    }

    /** Lookup item ID by nama (case-insensitive). Return null kalau tidak ketemu atau ambigu. */
    private function lookupItemIdByName(string $type, string $name): ?string
    {
        $needle = mb_strtolower(trim($name));

        return match ($type) {
            SurgeryPackageItem::TYPE_PROCEDURE => $this->lookupSingle(
                Procedure::whereRaw('LOWER(name) = ?', [$needle])
            ),
            SurgeryPackageItem::TYPE_MEDICATION => $this->lookupSingle(
                Medication::whereRaw('LOWER(name) = ?', [$needle])
            ),
            SurgeryPackageItem::TYPE_BHP => $this->lookupSingle(
                BhpItem::whereRaw('LOWER(name) = ?', [$needle])
            ),
            SurgeryPackageItem::TYPE_IOL => $this->lookupIolByDisplayName($needle),
            default => null,
        };
    }

    private function lookupSingle(\Illuminate\Database\Eloquent\Builder $query): ?string
    {
        $rows = $query->limit(2)->pluck('id');
        return $rows->count() === 1 ? (string) $rows->first() : null;
    }

    /**
     * IOL display name: "{brand} {model} {power}D".
     * Strategi: split akhiran power, sisa = brand+model.
     */
    private function lookupIolByDisplayName(string $needle): ?string
    {
        // Tangkap power di akhir (mis. "21D" / "21.5D")
        if (! preg_match('/^(.*?)\s+([\d.]+)d\s*$/i', $needle, $m)) {
            return null;
        }
        $prefix = trim($m[1]);
        $power  = (float) $m[2];

        // prefix bisa "brand model" — coba match brand+model digabung
        $rows = IolItem::whereRaw('LOWER(CONCAT(brand, \' \', model)) = ?', [$prefix])
            ->whereRaw('ABS(power - ?) < 0.001', [$power])
            ->limit(2)
            ->pluck('id');

        return $rows->count() === 1 ? (string) $rows->first() : null;
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    private function log(
        ?string $userId,
        string $action,
        ?string $model = null,
        ?string $modelId = null,
        ?string $description = null
    ): void {
        SystemLog::create([
            'user_id'     => $userId,
            'action'      => $action,
            'model'       => $model,
            'model_id'    => $modelId,
            'description' => $description,
            'ip_address'  => $this->request->ip(),
            'user_agent'  => $this->request->userAgent(),
        ]);
    }
}
