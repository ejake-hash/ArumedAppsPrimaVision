<?php

namespace App\Services;

use App\Events\InventoriUnitNotified;
use App\Models\BhpItem;
use App\Models\InventoryStock;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\UnitRequest;
use App\Models\UnitRequestItem;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UnitRequestService
{
    public const ITEM_TYPES = ['MEDICATION', 'BHP', 'IOL'];

    public function index(array $filters = []): LengthAwarePaginator
    {
        $q = UnitRequest::query();

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $q->where(fn ($qq) => $qq
                ->where('request_number', 'ilike', $term)
                ->orWhere('notes', 'ilike', $term));
        }

        if (!empty($filters['station'])) {
            $q->where('requesting_station', $filters['station']);
        }

        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $q->whereDate('request_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $q->whereDate('request_date', '<=', $filters['date_to']);
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        return $q->orderByDesc('request_date')->orderByDesc('request_number')->paginate($perPage);
    }

    public function show(string $id): array
    {
        $req = UnitRequest::with('items')->findOrFail($id);
        return $this->toArray($req);
    }

    public function create(array $data): UnitRequest
    {
        $items = $data['items'] ?? [];
        $this->validateItems($items);

        return DB::transaction(function () use ($data, $items) {
            $req = UnitRequest::create([
                'request_number'     => $this->generateNumber($data['request_date'] ?? null),
                'requesting_station' => $data['requesting_station'],
                'request_date'       => $data['request_date'] ?? now()->toDateString(),
                'status'             => $data['status'] ?? UnitRequest::STATUS_DRAFT,
                'notes'              => $data['notes'] ?? null,
                'requested_by'       => auth('api')->id(),
            ]);

            foreach ($items as $row) {
                $this->createItem($req, $row);
            }

            return $req->fresh('items');
        });
    }

    public function update(string $id, array $data): UnitRequest
    {
        $req = UnitRequest::with('items')->findOrFail($id);

        if (!in_array($req->status, [UnitRequest::STATUS_DRAFT, UnitRequest::STATUS_SUBMITTED], true)) {
            abort(422, "Request dengan status {$req->status} tidak bisa diedit.");
        }

        return DB::transaction(function () use ($req, $data) {
            $req->update(array_filter([
                'requesting_station' => $data['requesting_station'] ?? null,
                'request_date'       => $data['request_date'] ?? null,
                'notes'              => $data['notes'] ?? null,
            ], fn ($v) => $v !== null));

            if (isset($data['items']) && is_array($data['items'])) {
                $this->validateItems($data['items']);
                $req->items()->delete();
                foreach ($data['items'] as $row) {
                    $this->createItem($req, $row);
                }
            }

            return $req->fresh('items');
        });
    }

    public function submit(string $id): UnitRequest
    {
        $req = UnitRequest::with('items')->findOrFail($id);

        if ($req->status !== UnitRequest::STATUS_DRAFT) {
            abort(422, "Hanya request DRAFT yang bisa di-submit.");
        }
        if ($req->items->isEmpty()) {
            abort(422, 'Request harus berisi minimal 1 item sebelum di-submit.');
        }

        $req->update(['status' => UnitRequest::STATUS_SUBMITTED]);
        return $req->fresh('items');
    }

    public function approve(string $id): UnitRequest
    {
        $req = UnitRequest::findOrFail($id);

        if ($req->status !== UnitRequest::STATUS_SUBMITTED) {
            abort(422, 'Hanya request SUBMITTED yang bisa disetujui.');
        }

        $req->update([
            'status'      => UnitRequest::STATUS_APPROVED,
            'approved_by' => auth('api')->id(),
            'approved_at' => now(),
        ]);

        $this->notifyUnit($req, 'approved', "Permintaan {$req->request_number} disetujui gudang.");

        return $req->fresh('items');
    }

    public function reject(string $id, ?string $reason = null): UnitRequest
    {
        $req = UnitRequest::findOrFail($id);

        if (!in_array($req->status, [UnitRequest::STATUS_SUBMITTED, UnitRequest::STATUS_APPROVED], true)) {
            abort(422, "Request dengan status {$req->status} tidak bisa di-reject.");
        }

        $req->update([
            'status' => UnitRequest::STATUS_REJECTED,
            'notes'  => trim(($req->notes ? $req->notes . "\n" : '') . '[REJECT] ' . ($reason ?? '')),
        ]);

        $this->notifyUnit($req, 'rejected', trim("Permintaan {$req->request_number} ditolak gudang. " . ($reason ?? '')));

        return $req->fresh('items');
    }

    /**
     * Delivery — kurangi stock di inventori, set qty_delivered per item.
     * Asumsi: qty diambil dari batch yg paling cepat expired (FEFO sederhana).
     */
    public function deliver(string $id, array $data): UnitRequest
    {
        $req = UnitRequest::with('items')->findOrFail($id);

        if ($req->status !== UnitRequest::STATUS_APPROVED) {
            abort(422, 'Hanya request APPROVED yang bisa di-deliver.');
        }

        $deliveryByItemId = collect($data['items'] ?? [])->keyBy('id');

        $result = DB::transaction(function () use ($req, $deliveryByItemId) {
            foreach ($req->items as $item) {
                $row = $deliveryByItemId->get($item->id);
                if (!$row) continue;

                $qty = (float) ($row['qty_delivered'] ?? 0);
                if ($qty <= 0) continue;
                if ($qty > (float) $item->qty_requested + 0.001) {
                    abort(422, "Qty deliver melebihi qty_requested untuk item " . $this->itemLabel($item));
                }

                $this->consumeStock($item->item_type, $item->item_id, $qty, $row['batch_no'] ?? null);

                $item->update([
                    'qty_delivered' => $qty,
                    'batch_no'      => $row['batch_no'] ?? $item->batch_no,
                    'expiry_date'   => $row['expiry_date'] ?? $item->expiry_date,
                ]);
            }

            $req->update([
                'status'       => UnitRequest::STATUS_DELIVERED,
                'delivered_by' => auth('api')->id(),
                'delivered_at' => now(),
            ]);

            return $req->fresh('items');
        });

        $this->notifyUnit($result, 'delivered', "Permintaan {$result->request_number} dikirim gudang — silakan terima barang.");

        return $result;
    }

    /**
     * Tutup request oleh unit (terima barang) — stok unit BERTAMBAH sebesar qty_delivered.
     */
    public function close(string $id): UnitRequest
    {
        $req = UnitRequest::with('items')->findOrFail($id);
        if ($req->status !== UnitRequest::STATUS_DELIVERED) {
            abort(422, 'Hanya request DELIVERED yang bisa ditutup.');
        }

        return DB::transaction(function () use ($req) {
            foreach ($req->items as $item) {
                $qty = (float) $item->qty_delivered;
                if ($qty > 0) {
                    $this->addUnitStock($item->item_type, $item->item_id, $qty);
                }
            }

            $req->update(['status' => UnitRequest::STATUS_CLOSED]);
            return $req->fresh('items');
        });
    }

    public function delete(string $id): void
    {
        $req = UnitRequest::findOrFail($id);
        if (!in_array($req->status, [UnitRequest::STATUS_DRAFT, UnitRequest::STATUS_REJECTED], true)) {
            abort(422, 'Hanya request DRAFT atau REJECTED yang bisa dihapus.');
        }
        $req->items()->delete();
        $req->delete();
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    private function validateItems(array $items): void
    {
        if (empty($items)) {
            abort(422, 'Request harus berisi minimal 1 item.');
        }
        foreach ($items as $idx => $row) {
            $type = $row['item_type'] ?? null;
            $itemId = $row['item_id'] ?? null;
            $qty = (float) ($row['qty_requested'] ?? 0);
            if (!in_array($type, self::ITEM_TYPES, true)) {
                abort(422, "Item baris #" . ($idx + 1) . ": item_type tidak valid.");
            }
            if (!$itemId) {
                abort(422, "Item baris #" . ($idx + 1) . ": item_id wajib.");
            }
            if ($qty <= 0) {
                abort(422, "Item baris #" . ($idx + 1) . ": qty_requested harus > 0.");
            }
            if (!$this->itemExists($type, $itemId)) {
                abort(422, "Item baris #" . ($idx + 1) . ": item tidak ditemukan di master {$type}.");
            }
        }
    }

    private function createItem(UnitRequest $req, array $row): UnitRequestItem
    {
        return UnitRequestItem::create([
            'unit_request_id' => $req->id,
            'item_type'       => $row['item_type'],
            'item_id'         => $row['item_id'],
            'qty_requested'   => (float) $row['qty_requested'],
            'batch_no'        => $row['batch_no'] ?? null,
            'expiry_date'     => $row['expiry_date'] ?? null,
            'notes'           => $row['notes'] ?? null,
        ]);
    }

    /**
     * Konsumsi stok: kurangi qty dari batch yang ditentukan, atau FEFO kalau null.
     */
    private function consumeStock(string $type, string $itemId, float $qty, ?string $batchNo): void
    {
        if ($batchNo) {
            $stock = InventoryStock::where([
                'item_type' => $type,
                'item_id'   => $itemId,
                'batch_no'  => $batchNo,
            ])->first();
            if (!$stock || (float) $stock->qty_on_hand < $qty) {
                abort(422, "Stok batch {$batchNo} tidak cukup untuk item {$type}.");
            }
            $stock->decrement('qty_on_hand', $qty);
            return;
        }

        // FEFO — ambil dari batch yg paling cepat expired
        $remaining = $qty;
        $stocks = InventoryStock::where('item_type', $type)
            ->where('item_id', $itemId)
            ->where('qty_on_hand', '>', 0)
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->lockForUpdate()
            ->get();

        foreach ($stocks as $st) {
            if ($remaining <= 0) break;
            $take = min($remaining, (float) $st->qty_on_hand);
            $st->decrement('qty_on_hand', $take);
            $remaining -= $take;
        }

        if ($remaining > 0.001) {
            abort(422, "Stok tidak cukup untuk item {$type}. Kurang {$remaining} unit.");
        }
    }

    /**
     * Tambah stok master unit saat barang diterima (close).
     * MEDICATION/BHP punya kolom `stock`; IOL serialized → diabaikan.
     */
    private function addUnitStock(string $type, string $itemId, float $qty): void
    {
        $model = match ($type) {
            'MEDICATION' => Medication::find($itemId),
            'BHP'        => BhpItem::find($itemId),
            default      => null,
        };
        $model?->increment('stock', $qty);
    }

    /**
     * Pancarkan notifikasi realtime ke unit pemohon.
     */
    private function notifyUnit(UnitRequest $req, string $action, string $message): void
    {
        broadcast(new InventoriUnitNotified($req->requesting_station, [
            'kind'    => 'request',
            'action'  => $action,
            'number'  => $req->request_number,
            'status'  => $req->status,
            'message' => $message,
        ]));
    }

    private function itemExists(string $type, string $itemId): bool
    {
        return match ($type) {
            'MEDICATION' => Medication::whereKey($itemId)->exists(),
            'BHP'        => BhpItem::whereKey($itemId)->exists(),
            'IOL'        => IolItem::whereKey($itemId)->exists(),
        };
    }

    private function generateNumber(?string $date): string
    {
        $d = $date ? Carbon::parse($date) : now();
        $prefix = 'REQ-' . $d->format('Ym') . '-';
        $last = UnitRequest::withTrashed()
            ->where('request_number', 'like', $prefix . '%')
            ->orderByDesc('request_number')
            ->value('request_number');
        $next = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $next = (int) $m[1] + 1;
        }
        return $prefix . sprintf('%04d', $next);
    }

    private function toArray(UnitRequest $req): array
    {
        return [
            'id'                 => $req->id,
            'request_number'     => $req->request_number,
            'requesting_station' => $req->requesting_station,
            'request_date'       => optional($req->request_date)->toDateString(),
            'status'             => $req->status,
            'notes'              => $req->notes,
            'requested_by'       => $req->requested_by,
            'approved_by'        => $req->approved_by,
            'approved_at'        => $req->approved_at,
            'delivered_by'       => $req->delivered_by,
            'delivered_at'       => $req->delivered_at,
            'created_at'         => $req->created_at,
            'items'              => $req->items->map(fn ($it) => $this->itemRow($it))->toArray(),
        ];
    }

    private function itemRow(UnitRequestItem $it): array
    {
        $resolved = $this->resolveMasterRow($it->item_type, $it->item_id);
        return [
            'id'             => $it->id,
            'item_type'      => $it->item_type,
            'item_id'        => $it->item_id,
            'item_code'      => $resolved['code'] ?? null,
            'item_name'      => $resolved['name'] ?? '-',
            'item_unit'      => $resolved['unit'] ?? null,
            'qty_requested'  => (float) $it->qty_requested,
            'qty_delivered'  => (float) $it->qty_delivered,
            'batch_no'       => $it->batch_no,
            'expiry_date'    => optional($it->expiry_date)->toDateString(),
            'notes'          => $it->notes,
        ];
    }

    private function itemLabel(UnitRequestItem $it): string
    {
        $r = $this->resolveMasterRow($it->item_type, $it->item_id);
        return ($r['name'] ?? $it->item_id) . " [{$it->item_type}]";
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
