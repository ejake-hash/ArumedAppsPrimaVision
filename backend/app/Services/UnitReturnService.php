<?php

namespace App\Services;

use App\Events\InventoriUnitNotified;
use App\Models\BhpItem;
use App\Models\InventoryStock;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\UnitRequest;
use App\Models\UnitReturn;
use App\Models\UnitReturnItem;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UnitReturnService
{
    public const ITEM_TYPES = ['MEDICATION', 'BHP', 'IOL'];

    public function index(array $filters = []): LengthAwarePaginator
    {
        $q = UnitReturn::query()->with(['unitRequest:id,request_number']);

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $q->where(fn ($qq) => $qq
                ->where('return_number', 'ilike', $term)
                ->orWhere('reason', 'ilike', $term)
                ->orWhere('notes', 'ilike', $term));
        }

        if (!empty($filters['station'])) {
            $q->where('returning_station', $filters['station']);
        }

        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $q->whereDate('return_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $q->whereDate('return_date', '<=', $filters['date_to']);
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        return $q->orderByDesc('return_date')->orderByDesc('return_number')->paginate($perPage);
    }

    public function show(string $id): array
    {
        $ret = UnitReturn::with(['items', 'unitRequest:id,request_number'])->findOrFail($id);
        return $this->toArray($ret);
    }

    public function create(array $data): UnitReturn
    {
        $items = $data['items'] ?? [];
        $this->validateItems($items);

        if (!empty($data['unit_request_id'])) {
            UnitRequest::findOrFail($data['unit_request_id']);
        }

        return DB::transaction(function () use ($data, $items) {
            $ret = UnitReturn::create([
                'return_number'     => $this->generateNumber($data['return_date'] ?? null),
                'unit_request_id'   => $data['unit_request_id'] ?? null,
                'returning_station' => $data['returning_station'],
                'return_date'       => $data['return_date'] ?? now()->toDateString(),
                'status'            => UnitReturn::STATUS_DRAFT,
                'reason'            => $data['reason'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'returned_by'       => auth('api')->id(),
            ]);

            foreach ($items as $row) {
                $this->createItem($ret, $row);
            }

            return $ret->fresh('items');
        });
    }

    public function update(string $id, array $data): UnitReturn
    {
        $ret = UnitReturn::with('items')->findOrFail($id);

        if ($ret->status !== UnitReturn::STATUS_DRAFT) {
            abort(422, "Retur dengan status {$ret->status} tidak bisa diedit.");
        }

        return DB::transaction(function () use ($ret, $data) {
            $ret->update(array_filter([
                'returning_station' => $data['returning_station'] ?? null,
                'unit_request_id'   => $data['unit_request_id'] ?? null,
                'return_date'       => $data['return_date'] ?? null,
                'reason'            => $data['reason'] ?? null,
                'notes'             => $data['notes'] ?? null,
            ], fn ($v) => $v !== null));

            if (isset($data['items']) && is_array($data['items'])) {
                $this->validateItems($data['items']);
                $ret->items()->delete();
                foreach ($data['items'] as $row) {
                    $this->createItem($ret, $row);
                }
            }

            return $ret->fresh('items');
        });
    }

    /**
     * Submit retur dari sisi unit — hanya pindah status DRAFT → SUBMITTED.
     * Stok BELUM bertambah; admin inventori perlu verifikasi fisik dulu via receive().
     */
    public function submit(string $id): UnitReturn
    {
        $ret = UnitReturn::with('items')->findOrFail($id);

        if ($ret->status !== UnitReturn::STATUS_DRAFT) {
            abort(422, 'Hanya retur DRAFT yang bisa di-submit.');
        }
        if ($ret->items->isEmpty()) {
            abort(422, 'Retur harus berisi minimal 1 item sebelum di-submit.');
        }

        $ret->update(['status' => UnitReturn::STATUS_SUBMITTED]);
        return $ret->fresh('items');
    }

    /**
     * Admin inventori verifikasi fisik & terima retur — DI SINI stok bertambah.
     */
    public function receive(string $id): UnitReturn
    {
        $ret = UnitReturn::with('items')->findOrFail($id);
        if ($ret->status !== UnitReturn::STATUS_SUBMITTED) {
            abort(422, 'Hanya retur SUBMITTED yang bisa di-receive.');
        }

        $result = DB::transaction(function () use ($ret) {
            foreach ($ret->items as $item) {
                $this->returnStock($item);                              // gudang bertambah
                $this->removeUnitStock($item->item_type, $item->item_id, (float) $item->qty_returned); // stok unit berkurang
            }

            $ret->update([
                'status'      => UnitReturn::STATUS_RECEIVED,
                'received_by' => auth('api')->id(),
                'received_at' => now(),
            ]);

            return $ret->fresh('items');
        });

        $this->notifyUnit($result, 'received', "Retur {$result->return_number} diterima gudang. Stok unit telah disesuaikan.");

        return $result;
    }

    /**
     * Reject retur — stok belum pernah masuk, jadi cuma update status + catat alasan.
     */
    public function reject(string $id, ?string $reason = null): UnitReturn
    {
        $ret = UnitReturn::findOrFail($id);

        if (in_array($ret->status, [UnitReturn::STATUS_RECEIVED, UnitReturn::STATUS_REJECTED], true)) {
            abort(422, "Retur dengan status {$ret->status} tidak bisa di-reject.");
        }

        $ret->update([
            'status' => UnitReturn::STATUS_REJECTED,
            'notes'  => trim(($ret->notes ? $ret->notes . "\n" : '') . '[REJECT] ' . ($reason ?? '')),
        ]);

        $this->notifyUnit($ret, 'rejected', trim("Retur {$ret->return_number} ditolak gudang. " . ($reason ?? '')));

        return $ret->fresh('items');
    }

    public function delete(string $id): void
    {
        $ret = UnitReturn::with('items')->findOrFail($id);
        if ($ret->status !== UnitReturn::STATUS_DRAFT) {
            abort(422, 'Hanya retur DRAFT yang bisa dihapus.');
        }
        $ret->items()->delete();
        $ret->delete();
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    private function validateItems(array $items): void
    {
        if (empty($items)) {
            abort(422, 'Retur harus berisi minimal 1 item.');
        }
        foreach ($items as $idx => $row) {
            $type = $row['item_type'] ?? null;
            $itemId = $row['item_id'] ?? null;
            $qty = (float) ($row['qty_returned'] ?? 0);
            if (!in_array($type, self::ITEM_TYPES, true)) {
                abort(422, "Item baris #" . ($idx + 1) . ": item_type tidak valid.");
            }
            if (!$itemId) {
                abort(422, "Item baris #" . ($idx + 1) . ": item_id wajib.");
            }
            if ($qty <= 0) {
                abort(422, "Item baris #" . ($idx + 1) . ": qty_returned harus > 0.");
            }
            if (!$this->itemExists($type, $itemId)) {
                abort(422, "Item baris #" . ($idx + 1) . ": item tidak ditemukan di master {$type}.");
            }
        }
    }

    private function createItem(UnitReturn $ret, array $row): UnitReturnItem
    {
        return UnitReturnItem::create([
            'unit_return_id' => $ret->id,
            'item_type'      => $row['item_type'],
            'item_id'        => $row['item_id'],
            'qty_returned'   => (float) $row['qty_returned'],
            'batch_no'       => $row['batch_no'] ?? null,
            'expiry_date'    => $row['expiry_date'] ?? null,
            'condition'      => $row['condition'] ?? UnitReturnItem::CONDITION_GOOD,
            'notes'          => $row['notes'] ?? null,
        ]);
    }

    /**
     * Tambah stok kembali ke inventori (upsert per item+batch).
     */
    private function returnStock(UnitReturnItem $item): void
    {
        $stock = InventoryStock::firstOrNew([
            'item_type' => $item->item_type,
            'item_id'   => $item->item_id,
            'batch_no'  => $item->batch_no,
        ]);
        $stock->expiry_date = $item->expiry_date ?? $stock->expiry_date;
        $stock->qty_on_hand = (float) ($stock->qty_on_hand ?? 0) + (float) $item->qty_returned;
        $stock->last_received_at = now();
        $stock->save();
    }

    /**
     * Kurangi stok master unit saat retur diterima gudang (clamp ≥ 0).
     * MEDICATION/BHP punya kolom `stock`; IOL serialized → diabaikan.
     */
    private function removeUnitStock(string $type, string $itemId, float $qty): void
    {
        $model = match ($type) {
            'MEDICATION' => Medication::find($itemId),
            'BHP'        => BhpItem::find($itemId),
            default      => null,
        };
        if (!$model) return;

        $newStock = max(0, (float) $model->stock - $qty);
        $model->update(['stock' => $newStock]);
    }

    /**
     * Pancarkan notifikasi realtime ke unit peretur.
     */
    private function notifyUnit(UnitReturn $ret, string $action, string $message): void
    {
        broadcast(new InventoriUnitNotified($ret->returning_station, [
            'kind'    => 'return',
            'action'  => $action,
            'number'  => $ret->return_number,
            'status'  => $ret->status,
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
        $prefix = 'RET-' . $d->format('Ym') . '-';
        $last = UnitReturn::withTrashed()
            ->where('return_number', 'like', $prefix . '%')
            ->orderByDesc('return_number')
            ->value('return_number');
        $next = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $next = (int) $m[1] + 1;
        }
        return $prefix . sprintf('%04d', $next);
    }

    private function toArray(UnitReturn $ret): array
    {
        return [
            'id'                => $ret->id,
            'return_number'     => $ret->return_number,
            'unit_request_id'   => $ret->unit_request_id,
            'request_number'    => $ret->unitRequest?->request_number,
            'returning_station' => $ret->returning_station,
            'return_date'       => optional($ret->return_date)->toDateString(),
            'status'            => $ret->status,
            'reason'            => $ret->reason,
            'notes'             => $ret->notes,
            'returned_by'       => $ret->returned_by,
            'received_by'       => $ret->received_by,
            'received_at'       => $ret->received_at,
            'created_at'        => $ret->created_at,
            'items'             => $ret->items->map(fn ($it) => $this->itemRow($it))->toArray(),
        ];
    }

    private function itemRow(UnitReturnItem $it): array
    {
        $resolved = $this->resolveMasterRow($it->item_type, $it->item_id);
        return [
            'id'           => $it->id,
            'item_type'    => $it->item_type,
            'item_id'      => $it->item_id,
            'item_code'    => $resolved['code'] ?? null,
            'item_name'    => $resolved['name'] ?? '-',
            'item_unit'    => $resolved['unit'] ?? null,
            'qty_returned' => (float) $it->qty_returned,
            'batch_no'     => $it->batch_no,
            'expiry_date'  => optional($it->expiry_date)->toDateString(),
            'condition'    => $it->condition,
            'notes'        => $it->notes,
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
