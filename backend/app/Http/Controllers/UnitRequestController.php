<?php

namespace App\Http\Controllers;

use App\Models\BhpItem;
use App\Models\InventoryStock;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\UnitRequest;
use App\Models\UnitReturn;
use App\Services\UnitRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitRequestController extends Controller
{
    public function __construct(private readonly UnitRequestService $service) {}

    /**
     * Inbox admin inventori — list ringan request + retur SUBMITTED (butuh action).
     * Dipakai untuk modal notifikasi di header InventoriFarmasiLayout.
     */
    public function inbox(): JsonResponse
    {
        $requests = UnitRequest::where('status', UnitRequest::STATUS_SUBMITTED)
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'kind'           => 'REQUEST',
                'id'             => $r->id,
                'number'         => $r->request_number,
                'station'        => $r->requesting_station,
                'date'           => optional($r->request_date)->toDateString(),
                'items_count'    => $r->items_count,
                'created_at'     => $r->created_at,
            ]);

        $returns = UnitReturn::where('status', UnitReturn::STATUS_SUBMITTED)
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'kind'           => 'RETURN',
                'id'             => $r->id,
                'number'         => $r->return_number,
                'station'        => $r->returning_station,
                'date'           => optional($r->return_date)->toDateString(),
                'reason'         => $r->reason,
                'items_count'    => $r->items_count,
                'created_at'     => $r->created_at,
            ]);

        $items = $requests->concat($returns)
            ->sortByDesc('created_at')
            ->values();

        return $this->ok([
            'total'         => $items->count(),
            'request_count' => $requests->count(),
            'return_count'  => $returns->count(),
            'items'         => $items,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'station', 'status', 'statuses', 'date_from', 'date_to', 'per_page']);
        return $this->ok($this->service->index($filters));
    }

    /**
     * Snapshot stok gudang inventori per tipe (MEDICATION / BHP / IOL).
     * Output: list item master + total qty (sum batch) + batch detail (batch_no, expiry, qty)
     * supaya UI bisa tampilkan expiry terdekat. Filter `search` optional (nama / kode).
     */
    public function stock(Request $request, string $type): JsonResponse
    {
        $type = strtoupper($type);
        if (!in_array($type, ['MEDICATION', 'BHP', 'IOL'], true)) {
            return response()->json(['success' => false, 'message' => 'Tipe tidak valid'], 422);
        }

        $search = trim((string) $request->query('search', ''));

        $location = strtoupper(trim((string) $request->query('location', InventoryStock::LOC_INVENTORI)));
        if (!in_array($location, InventoryStock::LOCATIONS, true)) {
            $location = InventoryStock::LOC_INVENTORI;
        }

        $masterQ = match ($type) {
            'MEDICATION' => Medication::query()->select(['id', 'code', 'name', 'unit_kecil', 'unit'])->where('is_active', true),
            'BHP'        => BhpItem::query()->select(['id', 'code', 'name', 'unit'])->where('is_active', true),
            'IOL'        => IolItem::query()->select(['id', 'brand', 'model', 'power'])->where('is_active', true),
        };

        // Gudang INVENTORI = pemegang master → tampilkan seluruh item aktif.
        // Lokasi unit (FARMASI/BEDAH) hanya menyimpan barang yang PERNAH dikirim
        // gudang (transfer saat deliver membuat baris inventory_stocks di lokasi
        // itu, dan baris bertahan walau qty habis/0). Maka cukup batasi master ke
        // item yang punya baris stok di lokasi tsb — bukan seluruh master data.
        if ($location !== InventoryStock::LOC_INVENTORI) {
            $locationItemIds = InventoryStock::where('item_type', $type)
                ->where('location', $location)
                ->distinct()
                ->pluck('item_id');
            $masterQ->whereIn('id', $locationItemIds);
        }

        if ($search !== '') {
            $term = '%' . $search . '%';
            if ($type === 'IOL') {
                $masterQ->where(fn ($q) => $q->where('brand', 'ilike', $term)->orWhere('model', 'ilike', $term));
            } else {
                $masterQ->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term));
            }
        }

        $masters = $masterQ->orderBy($type === 'IOL' ? 'brand' : 'name')->limit(500)->get();

        $batches = InventoryStock::where('item_type', $type)
            ->where('location', $location)
            ->whereIn('item_id', $masters->pluck('id'))
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->get(['item_id', 'batch_no', 'expiry_date', 'qty_on_hand'])
            ->groupBy('item_id');

        $rows = $masters->map(function ($m) use ($type, $batches) {
            $list = $batches->get($m->id, collect());
            $totalQty = (float) $list->sum('qty_on_hand');
            $nearest = $list->firstWhere(fn ($b) => $b->expiry_date !== null);
            return [
                'id'             => $m->id,
                'code'           => $type === 'IOL' ? ($m->model ?? '-') : ($m->code ?? '-'),
                'name'           => $type === 'IOL' ? trim(($m->brand ?? '') . ' ' . ($m->power ? '· ' . $m->power . 'D' : '')) : $m->name,
                'unit'           => $type === 'MEDICATION' ? ($m->unit_kecil ?? $m->unit) : ($type === 'BHP' ? $m->unit : 'pcs'),
                'total_qty'      => $totalQty,
                'nearest_expiry' => optional($nearest?->expiry_date)->toDateString(),
                'batches'        => $list->map(fn ($b) => [
                    'batch_no'    => $b->batch_no,
                    'expiry_date' => optional($b->expiry_date)->toDateString(),
                    'qty'         => (float) $b->qty_on_hand,
                ])->values(),
            ];
        })->values();

        return $this->ok($rows);
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->service->show($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'requesting_station'    => 'required|in:' . implode(',', UnitRequest::STATIONS),
            'request_date'          => 'nullable|date',
            'status'                => 'nullable|in:DRAFT,SUBMITTED',
            'notes'                 => 'nullable|string',
            'items'                 => 'required|array|min:1',
            'items.*.item_type'     => 'required|in:MEDICATION,BHP,IOL',
            'items.*.item_id'       => 'required|uuid',
            'items.*.qty_requested' => 'required|numeric|min:0.01',
            'items.*.batch_no'      => 'nullable|string|max:50',
            'items.*.expiry_date'   => 'nullable|date',
            'items.*.notes'         => 'nullable|string',
        ]);

        $req = $this->service->create($data);
        return $this->ok($this->service->show($req->id), 'Request unit dibuat', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'requesting_station'    => 'nullable|in:' . implode(',', UnitRequest::STATIONS),
            'request_date'          => 'nullable|date',
            'notes'                 => 'nullable|string',
            'items'                 => 'nullable|array',
            'items.*.item_type'     => 'required_with:items|in:MEDICATION,BHP,IOL',
            'items.*.item_id'       => 'required_with:items|uuid',
            'items.*.qty_requested' => 'required_with:items|numeric|min:0.01',
            'items.*.batch_no'      => 'nullable|string|max:50',
            'items.*.expiry_date'   => 'nullable|date',
            'items.*.notes'         => 'nullable|string',
        ]);

        $req = $this->service->update($id, $data);
        return $this->ok($this->service->show($req->id), 'Request unit diperbarui');
    }

    public function submit(string $id): JsonResponse
    {
        $req = $this->service->submit($id);
        return $this->ok($this->service->show($req->id), 'Request unit di-submit');
    }

    public function approve(string $id): JsonResponse
    {
        $req = $this->service->approve($id);
        return $this->ok($this->service->show($req->id), 'Request unit disetujui');
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $req = $this->service->reject($id, $data['reason'] ?? null);
        return $this->ok($this->service->show($req->id), 'Request unit ditolak');
    }

    public function deliver(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.id'             => 'required|uuid',
            'items.*.qty_delivered'  => 'required|numeric|min:0',
            'items.*.batch_no'       => 'nullable|string|max:50',
            'items.*.expiry_date'    => 'nullable|date',
        ]);
        $req = $this->service->deliver($id, $data);
        return $this->ok($this->service->show($req->id), 'Request unit dikirim & stok dikurangi');
    }

    public function close(string $id): JsonResponse
    {
        $req = $this->service->close($id);
        return $this->ok($this->service->show($req->id), 'Request unit ditutup');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return $this->ok(null, 'Request unit dihapus');
    }

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }
}
