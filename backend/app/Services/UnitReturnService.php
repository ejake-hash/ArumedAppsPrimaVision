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

    public function __construct(private InventoryStockService $stockService)
    {
    }

    public function index(array $filters = []): LengthAwarePaginator
    {
        $q = UnitReturn::query()->with(['unitRequest:id,request_number', 'items']);

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
        $paginator = $q->orderByDesc('return_date')->orderByDesc('return_number')->paginate($perPage);
        $paginator->getCollection()->transform(fn ($r) => $this->toArray($r));
        return $paginator;
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

        $attempts = 0;
        while (true) {
            try {
                return DB::transaction(function () use ($data, $items, $attempts) {
                    $ret = UnitReturn::create([
                        'return_number'     => $this->generateNumber($data['return_date'] ?? null, $attempts),
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
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '23505' && ++$attempts < 8) continue;
                throw $e;
            }
        }
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

        $station = $ret->returning_station;

        $result = DB::transaction(function () use ($ret, $station) {
            foreach ($ret->items as $item) {
                // Stok unit SELALU berkurang (barang fisik memang keluar dari unit,
                // apa pun kondisinya).
                $this->removeUnitStock($item->item_type, $item->item_id, (float) $item->qty_returned, $station);

                // Gudang (INVENTORI) hanya bertambah untuk barang yang MASIH LAYAK
                // pakai. Barang DAMAGED/EXPIRED tidak boleh masuk kembali ke stok
                // jual/dispensing — itu jadi limbah, bukan stok. Kalau tidak
                // difilter, barang rusak/kadaluarsa bisa terdispensing ke pasien.
                if ($this->isReusableCondition($item->condition)) {
                    $this->returnStock($item);                          // gudang (INVENTORI) bertambah
                }
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
     * Barang retur yang masih layak masuk kembali ke stok jual/dispensing.
     * GOOD & NEAR_EXPIRY masih bisa dipakai; DAMAGED & EXPIRED tidak (limbah).
     */
    private function isReusableCondition(?string $condition): bool
    {
        return in_array(
            $condition ?? UnitReturnItem::CONDITION_GOOD,
            [UnitReturnItem::CONDITION_GOOD, UnitReturnItem::CONDITION_NEAR_EXPIRY],
            true
        );
    }

    /**
     * Tambah stok kembali ke GUDANG (INVENTORI), upsert per item+batch.
     */
    private function returnStock(UnitReturnItem $item): void
    {
        // upsertStock = sumber tunggal (batch_no NULL tidak diduplikasi).
        $this->stockService->upsertStock(
            $item->item_type,
            $item->item_id,
            InventoryStock::LOC_INVENTORI,
            $item->batch_no,
            (float) $item->qty_returned,
            $item->expiry_date ?? null
        );
    }

    /**
     * Kurangi stok di lokasi UNIT peretur saat retur diterima gudang (FEFO,
     * strict). Abort 422 kalau stok unit kurang dari qty retur. IOL serialized
     * → tidak dikelola di inventory_stocks, diabaikan.
     */
    private function removeUnitStock(string $type, string $itemId, float $qty, string $location): void
    {
        if ($type === 'IOL' || $qty <= 0) return;
        $this->stockService->consume($type, $itemId, $qty, $location);
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

    private function generateNumber(?string $date, int $bump = 0): string
    {
        $d = $date ? Carbon::parse($date) : now();
        $prefix = 'RET-' . $d->format('Ym') . '-';
        $next = (int) DB::table('unit_returns')
            ->where('return_number', 'like', $prefix . '%')
            ->selectRaw("COALESCE(MAX(CAST(SUBSTRING(return_number FROM '\d+$') AS INTEGER)), 0) + 1 AS n")
            ->value('n');
        return $prefix . sprintf('%04d', $next + $bump);
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
