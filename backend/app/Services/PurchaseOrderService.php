<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Concerns\RetriesUniqueNumber;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    use RetriesUniqueNumber;

    public const ITEM_TYPES = ['MEDICATION', 'BHP', 'IOL'];

    public function index(array $filters = []): LengthAwarePaginator
    {
        $q = PurchaseOrder::query()->with(['supplier:id,code,name']);

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $q->where(function ($qq) use ($term) {
                $qq->where('po_number', 'ilike', $term)
                   ->orWhereHas('supplier', fn ($s) => $s->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term));
            });
        }

        if (!empty($filters['status'])) {
            $q->where('status', strtoupper($filters['status']));
        }

        // Filter multi-status (dipakai tab Aktif vs History di frontend).
        // `statuses` = whitelist (whereIn), `exclude_statuses` = blacklist.
        if (!empty($filters['statuses'])) {
            $list = array_map('strtoupper', (array) $filters['statuses']);
            $q->whereIn('status', $list);
        }
        if (!empty($filters['exclude_statuses'])) {
            $list = array_map('strtoupper', (array) $filters['exclude_statuses']);
            $q->whereNotIn('status', $list);
        }

        if (!empty($filters['supplier_id'])) {
            $q->where('supplier_id', $filters['supplier_id']);
        }

        if (!empty($filters['date_from'])) {
            $q->whereDate('po_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $q->whereDate('po_date', '<=', $filters['date_to']);
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        return $q->orderByDesc('po_date')->orderByDesc('po_number')->paginate($perPage);
    }

    public function show(string $id): array
    {
        $po = PurchaseOrder::with(['supplier', 'items'])->findOrFail($id);

        return [
            'id'            => $po->id,
            'po_number'     => $po->po_number,
            'supplier_id'   => $po->supplier_id,
            'supplier'      => $po->supplier,
            'po_date'       => optional($po->po_date)->toDateString(),
            'expected_date' => optional($po->expected_date)->toDateString(),
            'status'        => $po->status,
            'notes'         => $po->notes,
            'total_amount'  => (float) $po->total_amount,
            'created_by'    => $po->created_by,
            'created_at'    => $po->created_at,
            'updated_at'    => $po->updated_at,
            'items'         => $po->items->map(fn ($it) => $this->itemRow($it))->toArray(),
        ];
    }

    public function create(array $data): PurchaseOrder
    {
        Supplier::findOrFail($data['supplier_id']);
        $items = $data['items'] ?? [];
        $this->validateItems($items);

        // Nomor PO via MAX+1 bisa tabrakan saat 2 request berbarengan → retry
        // SELURUH transaksi saat unique-violation (Postgres membatalkan transaksi
        // begitu satu statement gagal, jadi retry harus transaksi baru).
        return $this->createWithRetry(function () use ($data, $items) {
            return DB::transaction(function () use ($data, $items) {
                $po = PurchaseOrder::create([
                    'po_number'     => $this->generatePoNumber($data['po_date'] ?? null),
                    'supplier_id'   => $data['supplier_id'],
                    'po_date'       => $data['po_date'] ?? now()->toDateString(),
                    'expected_date' => $data['expected_date'] ?? null,
                    'status'        => $data['status'] ?? PurchaseOrder::STATUS_DRAFT,
                    'notes'         => $data['notes'] ?? null,
                    'total_amount'  => 0,
                    'created_by'    => auth('api')->id(),
                ]);

                $this->syncItems($po, $items);
                $this->recalcTotal($po);

                return $po->fresh(['supplier', 'items']);
            });
        });
    }

    public function update(string $id, array $data): PurchaseOrder
    {
        $po = PurchaseOrder::with('items')->findOrFail($id);

        if (in_array($po->status, [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CANCELED], true)) {
            abort(422, "PO dengan status {$po->status} tidak bisa diubah.");
        }

        if (array_key_exists('items', $data)) {
            $this->validateItems($data['items'] ?? []);
        }

        return DB::transaction(function () use ($po, $data) {
            $po->update([
                'supplier_id'   => $data['supplier_id']   ?? $po->supplier_id,
                'po_date'       => $data['po_date']       ?? $po->po_date,
                'expected_date' => $data['expected_date'] ?? $po->expected_date,
                'status'        => $data['status']        ?? $po->status,
                'notes'         => $data['notes']         ?? $po->notes,
            ]);

            if (array_key_exists('items', $data)) {
                if ($po->goodsReceipts()->exists()) {
                    abort(422, 'PO sudah memiliki penerimaan — item tidak bisa diubah penuh. Edit hanya catatan/status.');
                }
                $this->syncItems($po, $data['items']);
                $this->recalcTotal($po);
            }

            return $po->fresh(['supplier', 'items']);
        });
    }

    public function delete(string $id): void
    {
        $po = PurchaseOrder::findOrFail($id);

        if ($po->goodsReceipts()->exists()) {
            abort(422, 'PO tidak bisa dihapus — sudah ada penerimaan terkait.');
        }
        if (in_array($po->status, [PurchaseOrder::STATUS_PARTIAL, PurchaseOrder::STATUS_RECEIVED], true)) {
            abort(422, "PO dengan status {$po->status} tidak bisa dihapus.");
        }

        $po->delete();
    }

    public function cancel(string $id): PurchaseOrder
    {
        $po = PurchaseOrder::findOrFail($id);
        if ($po->goodsReceipts()->exists()) {
            abort(422, 'PO sudah memiliki penerimaan — tidak bisa di-cancel.');
        }
        if ($po->status === PurchaseOrder::STATUS_RECEIVED) {
            abort(422, 'PO sudah RECEIVED — tidak bisa di-cancel.');
        }
        $po->update(['status' => PurchaseOrder::STATUS_CANCELED]);
        return $po;
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    private function validateItems(array $items): void
    {
        if (empty($items)) {
            abort(422, 'PO harus berisi minimal 1 item.');
        }
        foreach ($items as $idx => $row) {
            $type = $row['item_type'] ?? null;
            $itemId = $row['item_id'] ?? null;
            $qty = (float) ($row['qty_ordered'] ?? 0);
            if (!in_array($type, self::ITEM_TYPES, true)) {
                abort(422, "Item baris #" . ($idx + 1) . ": item_type tidak valid.");
            }
            if (!$itemId) {
                abort(422, "Item baris #" . ($idx + 1) . ": item_id wajib.");
            }
            if ($qty <= 0) {
                abort(422, "Item baris #" . ($idx + 1) . ": qty_ordered harus > 0.");
            }
            if (!$this->itemExists($type, $itemId)) {
                abort(422, "Item baris #" . ($idx + 1) . ": item tidak ditemukan di master {$type}.");
            }
        }
    }

    private function itemExists(string $type, string $itemId): bool
    {
        return match ($type) {
            'MEDICATION' => Medication::whereKey($itemId)->exists(),
            'BHP'        => BhpItem::whereKey($itemId)->exists(),
            'IOL'        => IolItem::whereKey($itemId)->exists(),
        };
    }

    private function syncItems(PurchaseOrder $po, array $items): void
    {
        $po->items()->delete();

        foreach ($items as $row) {
            $qty = (float) $row['qty_ordered'];
            $price = (float) ($row['unit_price'] ?? 0);
            PurchaseOrderItem::create([
                'po_id'        => $po->id,
                'item_type'    => $row['item_type'],
                'item_id'      => $row['item_id'],
                'qty_ordered'  => $qty,
                'qty_received' => 0,
                'unit_price'   => $price,
                'subtotal'     => round($qty * $price, 2),
                'notes'        => $row['notes'] ?? null,
            ]);
        }
    }

    private function recalcTotal(PurchaseOrder $po): void
    {
        $total = (float) $po->items()->sum('subtotal');
        $po->update(['total_amount' => $total]);
    }

    private function generatePoNumber(?string $poDate): string
    {
        $date = $poDate ? Carbon::parse($poDate) : now();
        $prefix = 'PO-' . $date->format('Ym') . '-';

        $last = PurchaseOrder::withTrashed()
            ->where('po_number', 'like', $prefix . '%')
            ->orderByDesc('po_number')
            ->value('po_number');

        $next = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $next = (int) $m[1] + 1;
        }
        return $prefix . sprintf('%04d', $next);
    }

    private function itemRow(PurchaseOrderItem $it): array
    {
        $resolved = $this->resolveMasterRow($it->item_type, $it->item_id);
        return [
            'id'            => $it->id,
            'item_type'     => $it->item_type,
            'item_id'       => $it->item_id,
            'item_code'     => $resolved['code']  ?? null,
            'item_name'     => $resolved['name']  ?? '-',
            'item_unit'     => $resolved['unit']  ?? null,
            'qty_ordered'   => (float) $it->qty_ordered,
            'qty_received'  => (float) $it->qty_received,
            'qty_remaining' => max(0, (float) $it->qty_ordered - (float) $it->qty_received),
            'unit_price'    => (float) $it->unit_price,
            'subtotal'      => (float) $it->subtotal,
            'notes'         => $it->notes,
        ];
    }

    private function resolveMasterRow(string $type, string $itemId): ?array
    {
        $row = match ($type) {
            'MEDICATION' => Medication::find($itemId),
            'BHP'        => BhpItem::find($itemId),
            'IOL'        => IolItem::find($itemId),
        };
        if (!$row) return null;
        return [
            'code' => $row->code ?? null,
            'name' => $row->name ?? $row->brand ?? '-',
            'unit' => $row->unit_kecil ?? $row->unit ?? null,
        ];
    }
}
