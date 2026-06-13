<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\InventoryStock;
use App\Models\Medication;
use App\Models\StockOpnameItem;
use App\Models\StockOpnameSession;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * StockOpnameService — rekam kegiatan opname sebagai SESI (Berita Acara) + detail
 * per item. Layer perekaman MURNI: mutasi stok tetap lewat
 * InventoryStockService::opname() (FEFO/batch OPNAME/system_logs tak diubah).
 */
class StockOpnameService
{
    /** Hanya MEDICATION & BHP yang bisa opname (IOL read-only, selaras InventoryStockService). */
    public const ITEM_TYPES = ['MEDICATION', 'BHP'];

    public function __construct(private InventoryStockService $stockService)
    {
    }

    // =========================================================================
    // BUAT SESI (apply + rekam Berita Acara) — satu transaksi
    // =========================================================================
    public function createSession(array $data): StockOpnameSession
    {
        $location = $data['location'] ?? null;
        $itemType = $data['item_type'] ?? null;
        $rows     = $data['items'] ?? [];

        if (!in_array($location, InventoryStock::LOCATIONS, true)) {
            abort(422, "Lokasi opname '{$location}' tidak didukung.");
        }
        if (!in_array($itemType, self::ITEM_TYPES, true)) {
            abort(422, 'Jenis item opname harus MEDICATION atau BHP.');
        }
        if (empty($rows) || !is_array($rows)) {
            abort(422, 'Opname harus berisi minimal 1 item dengan selisih.');
        }

        $opnameDate = !empty($data['opname_date'])
            ? Carbon::parse($data['opname_date'])->toDateString()
            : now()->toDateString();

        // Snapshot kode/nama master (anti N+1) untuk seluruh item_id yang dikirim.
        $ids   = collect($rows)->pluck('item_id')->filter()->unique()->values()->all();
        $names = $this->resolveMasterNames($itemType, $ids);

        $userId = auth('api')->id();

        $attempts = 0;
        while (true) {
            try {
                return DB::transaction(function () use ($rows, $location, $itemType, $opnameDate, $names, $userId, $attempts) {
                    $session = StockOpnameSession::create([
                        'session_number' => $this->generateNumber($opnameDate, $attempts),
                        'location'       => $location,
                        'item_type'      => $itemType,
                        'opname_date'    => $opnameDate,
                        'status'         => StockOpnameSession::STATUS_APPLIED,
                        'notes'          => $data['notes'] ?? null,
                        'counted_by'     => $userId,
                        'applied_by'     => $userId,
                        'applied_at'     => now(),
                    ]);

                    $totalItems = $totalPlus = $totalMinus = 0;

                    foreach ($rows as $idx => $row) {
                        $itemId = $row['item_id'] ?? null;
                        if (!$itemId) {
                            abort(422, 'Item baris #' . ($idx + 1) . ': item_id wajib.');
                        }
                        $physical = (float) ($row['physical_qty'] ?? -1);
                        if ($physical < 0) {
                            abort(422, 'Item baris #' . ($idx + 1) . ': stok fisik tidak valid.');
                        }

                        // Hitung ulang stok SISTEM dari sumber kebenaran (inventory_stocks),
                        // BUKAN dari klien — anti-stale/spoof. Ini juga jadi nilai BA.
                        $systemQty = $this->stockService->onHand($itemType, $itemId, $location);
                        $delta     = $physical - $systemQty;
                        if (abs($delta) < 0.0001) {
                            continue; // tak ada selisih → tak direkam & tak di-apply
                        }

                        // Terapkan ke stok via opname set-total (reuse penuh: FEFO/batch/system_logs).
                        $this->stockService->opname([
                            'item_type' => $itemType,
                            'item_id'   => $itemId,
                            'location'  => $location,
                            'new_qty'   => $physical,
                            'reason'    => "Opname {$session->session_number}",
                        ]);

                        StockOpnameItem::create([
                            'stock_opname_session_id' => $session->id,
                            'item_type'   => $itemType,
                            'item_id'     => $itemId,
                            'item_code'   => $names[$itemId]['code'] ?? null,
                            'item_name'   => $names[$itemId]['name'] ?? '-',
                            'system_qty'  => $systemQty,
                            'physical_qty'=> $physical,
                            'delta'       => $delta,
                            'status'      => $delta > 0 ? StockOpnameItem::STATUS_LEBIH : StockOpnameItem::STATUS_KURANG,
                            'note'        => $row['note'] ?? null,
                        ]);

                        $totalItems++;
                        $delta > 0 ? $totalPlus++ : $totalMinus++;
                    }

                    if ($totalItems === 0) {
                        abort(422, 'Tidak ada selisih untuk direkam (stok fisik sama dengan sistem).');
                    }

                    $session->update([
                        'total_items' => $totalItems,
                        'total_plus'  => $totalPlus,
                        'total_minus' => $totalMinus,
                    ]);

                    return $session->fresh('items');
                });
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '23505' && ++$attempts < 8) continue;
                throw $e;
            }
        }
    }

    // =========================================================================
    // DAFTAR & DETAIL
    // =========================================================================
    public function index(array $f = []): LengthAwarePaginator
    {
        $q = StockOpnameSession::query()->withCount('items');

        if (!empty($f['location']))  $q->where('location', $f['location']);
        if (!empty($f['item_type'])) $q->where('item_type', $f['item_type']);
        if (!empty($f['date_from'])) $q->whereDate('opname_date', '>=', $f['date_from']);
        if (!empty($f['date_to']))   $q->whereDate('opname_date', '<=', $f['date_to']);
        if (!empty($f['search']))    $q->where('session_number', 'ilike', '%' . trim($f['search']) . '%');

        $perPage = min(200, max(5, (int) ($f['per_page'] ?? 25)));
        $p = $q->orderByDesc('opname_date')->orderByDesc('session_number')->paginate($perPage);
        $p->getCollection()->transform(fn ($s) => $this->sessionRow($s));
        return $p;
    }

    public function show(string $id): array
    {
        $s = StockOpnameSession::with(['items', 'countedBy:id,name', 'appliedBy:id,name'])->findOrFail($id);
        return $this->sessionDetail($s);
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================
    private function sessionRow(StockOpnameSession $s): array
    {
        return [
            'id'             => $s->id,
            'session_number' => $s->session_number,
            'location'       => $s->location,
            'item_type'      => $s->item_type,
            'opname_date'    => optional($s->opname_date)->toDateString(),
            'status'         => $s->status,
            'total_items'    => $s->total_items,
            'total_plus'     => $s->total_plus,
            'total_minus'    => $s->total_minus,
            'items_count'    => $s->items_count,
            'notes'          => $s->notes,
            'applied_at'     => $s->applied_at,
        ];
    }

    private function sessionDetail(StockOpnameSession $s): array
    {
        return array_merge($this->sessionRow($s), [
            'counted_by_name' => $s->countedBy?->name,
            'applied_by_name' => $s->appliedBy?->name,
            'items' => $s->items->map(fn ($it) => [
                'id'           => $it->id,
                'item_type'    => $it->item_type,
                'item_id'      => $it->item_id,
                'item_code'    => $it->item_code,
                'item_name'    => $it->item_name,
                'system_qty'   => (float) $it->system_qty,
                'physical_qty' => (float) $it->physical_qty,
                'delta'        => (float) $it->delta,
                'status'       => $it->status,
                'note'         => $it->note,
            ])->values(),
        ]);
    }

    /** Snapshot kode+nama master per item_id (1 query). */
    private function resolveMasterNames(string $type, array $ids): array
    {
        if (empty($ids)) return [];
        $rows = $type === 'MEDICATION'
            ? Medication::whereIn('id', $ids)->get(['id', 'code', 'name'])
            : BhpItem::whereIn('id', $ids)->get(['id', 'code', 'name']);
        return $rows->mapWithKeys(fn ($m) => [$m->id => ['code' => $m->code, 'name' => $m->name]])->toArray();
    }

    private function generateNumber(?string $date, int $bump = 0): string
    {
        $d = $date ? Carbon::parse($date) : now();
        $prefix = 'OPN-' . $d->format('Ym') . '-';
        $next = (int) DB::table('stock_opname_sessions')
            ->where('session_number', 'like', $prefix . '%')
            ->selectRaw("COALESCE(MAX(CAST(SUBSTRING(session_number FROM '\d+$') AS INTEGER)), 0) + 1 AS n")
            ->value('n');
        return $prefix . sprintf('%04d', $next + $bump);
    }
}
