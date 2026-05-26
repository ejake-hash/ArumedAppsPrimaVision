<?php

namespace App\Http\Controllers;

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
        $filters = $request->only(['search', 'station', 'status', 'date_from', 'date_to', 'per_page']);
        return $this->ok($this->service->index($filters));
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->service->show($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'requesting_station'    => 'required|in:ADMISI,TRIASE,REFRAKSIONIS,DOKTER,PENUNJANG,BEDAH,KASIR,FARMASI',
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
            'requesting_station'    => 'nullable|in:ADMISI,TRIASE,REFRAKSIONIS,DOKTER,PENUNJANG,BEDAH,KASIR,FARMASI',
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
