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
