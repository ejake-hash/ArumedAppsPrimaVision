<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\InventoryPrice;
use App\Models\InventoryPriceSetting;
use App\Models\IolItem;
use App\Models\Medication;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InventoryPriceService
{
    public function getPpnRate(): float
    {
        return (float) InventoryPriceSetting::current()->ppn_rate;
    }

    public function setPpnRate(float $rate): InventoryPriceSetting
    {
        $setting = InventoryPriceSetting::current();
        $setting->ppn_rate = $rate;
        $setting->save();

        if ($rate >= 0) {
            $this->recomputeAllHja($rate);
        }
        return $setting;
    }

    /**
     * Daftar item per tipe (MEDICATION / BHP / IOL) di-LEFT JOIN dgn inventory_prices.
     * Item yang belum di-price tetap tampil (hpp/hja null).
     */
    public function listForType(string $type, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        $search  = trim($filters['search'] ?? '');

        $query = match ($type) {
            'MEDICATION' => $this->buildMedicationQuery($search),
            'BHP'        => $this->buildBhpQuery($search),
            'IOL'        => $this->buildIolQuery($search),
            default      => throw new \Exception('Tipe tidak dikenal: ' . $type, 422),
        };

        return $query->paginate($perPage);
    }

    private function buildMedicationQuery(string $search)
    {
        $q = Medication::query()
            ->select([
                'medications.id',
                'medications.code',
                'medications.name',
                'medications.generic_name',
                'medications.form_sediaan',
                'medications.unit_besar',
                'medications.unit_kecil',
                'medications.konversi',
                'medications.is_active',
                'inventory_prices.id as price_id',
                'inventory_prices.hpp',
                'inventory_prices.margin_percent',
                'inventory_prices.ppn_enabled',
                'inventory_prices.hja',
                'inventory_prices.effective_date',
                'inventory_prices.notes',
            ])
            ->leftJoin('inventory_prices', function ($j) {
                $j->on('inventory_prices.item_id', '=', 'medications.id')
                  ->where('inventory_prices.item_type', '=', 'MEDICATION');
            })
            ->orderBy('medications.name');

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('medications.name', 'like', "%$search%")
                  ->orWhere('medications.code', 'like', "%$search%")
                  ->orWhere('medications.generic_name', 'like', "%$search%");
            });
        }
        return $q;
    }

    private function buildBhpQuery(string $search)
    {
        $q = BhpItem::query()
            ->select([
                'bhp_items.id',
                'bhp_items.code',
                'bhp_items.name',
                'bhp_items.category',
                'bhp_items.unit',
                'bhp_items.is_active',
                'inventory_prices.id as price_id',
                'inventory_prices.hpp',
                'inventory_prices.margin_percent',
                'inventory_prices.ppn_enabled',
                'inventory_prices.hja',
                'inventory_prices.effective_date',
                'inventory_prices.notes',
            ])
            ->leftJoin('inventory_prices', function ($j) {
                $j->on('inventory_prices.item_id', '=', 'bhp_items.id')
                  ->where('inventory_prices.item_type', '=', 'BHP');
            })
            ->orderBy('bhp_items.name');

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('bhp_items.name', 'like', "%$search%")
                  ->orWhere('bhp_items.code', 'like', "%$search%")
                  ->orWhere('bhp_items.category', 'like', "%$search%");
            });
        }
        return $q;
    }

    private function buildIolQuery(string $search)
    {
        $q = IolItem::query()
            ->select([
                'iol_items.id',
                'iol_items.brand',
                'iol_items.model',
                'iol_items.iol_type',
                'iol_items.power',
                'iol_items.cylinder',
                'iol_items.serial_number',
                'iol_items.is_active',
                'inventory_prices.id as price_id',
                'inventory_prices.hpp',
                'inventory_prices.margin_percent',
                'inventory_prices.ppn_enabled',
                'inventory_prices.hja',
                'inventory_prices.effective_date',
                'inventory_prices.notes',
            ])
            ->leftJoin('inventory_prices', function ($j) {
                $j->on('inventory_prices.item_id', '=', 'iol_items.id')
                  ->where('inventory_prices.item_type', '=', 'IOL');
            })
            ->orderBy('iol_items.brand')
            ->orderBy('iol_items.model');

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('iol_items.brand', 'like', "%$search%")
                  ->orWhere('iol_items.model', 'like', "%$search%")
                  ->orWhere('iol_items.serial_number', 'like', "%$search%");
            });
        }
        return $q;
    }

    /**
     * Upsert harga item. Hitung HJA dari ppn_rate global aktif.
     */
    public function upsert(string $itemType, string $itemId, array $data): InventoryPrice
    {
        $this->assertItemExists($itemType, $itemId);

        $ppnRate     = $this->getPpnRate();
        $hpp         = (float) ($data['hpp'] ?? 0);
        $margin      = (float) ($data['margin_percent'] ?? 0);
        $ppnEnabled  = (bool) ($data['ppn_enabled'] ?? true);
        $hja         = InventoryPrice::computeHja($hpp, $margin, $ppnEnabled, $ppnRate);

        $price = InventoryPrice::firstOrNew([
            'item_type' => $itemType,
            'item_id'   => $itemId,
        ]);

        $price->fill([
            'hpp'            => $hpp,
            'margin_percent' => $margin,
            'ppn_enabled'    => $ppnEnabled,
            'hja'            => $hja,
            'notes'          => $data['notes'] ?? null,
            'effective_date' => $data['effective_date'] ?? null,
            'updated_by'     => auth('api')->id(),
        ])->save();

        return $price;
    }

    public function delete(string $itemType, string $itemId): void
    {
        InventoryPrice::where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->delete();
    }

    private function assertItemExists(string $itemType, string $itemId): void
    {
        $exists = match ($itemType) {
            'MEDICATION' => Medication::whereKey($itemId)->exists(),
            'BHP'        => BhpItem::whereKey($itemId)->exists(),
            'IOL'        => IolItem::whereKey($itemId)->exists(),
            default      => false,
        };
        if (!$exists) {
            throw new \Exception('Item tidak ditemukan untuk tipe ' . $itemType, 404);
        }
    }

    /**
     * Saat ppn_rate global berubah, recompute HJA semua row yang ppn_enabled=true.
     */
    private function recomputeAllHja(float $ppnRate): void
    {
        InventoryPrice::query()->chunkById(500, function ($rows) use ($ppnRate) {
            foreach ($rows as $r) {
                $newHja = InventoryPrice::computeHja(
                    (float) $r->hpp,
                    (float) $r->margin_percent,
                    (bool) $r->ppn_enabled,
                    $ppnRate
                );
                if ((float) $r->hja !== $newHja) {
                    $r->hja = $newHja;
                    $r->save();
                }
            }
        });
    }
}
